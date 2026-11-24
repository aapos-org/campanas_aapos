<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\EventType;
use App\Enums\RecipientStatus;
use App\Mail\Transport\MailTransportFactory;
use App\Mail\Transport\OutgoingEmail;
use App\Mail\Transport\TransportException;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\EventRecorder;
use App\Services\Contacts\SuppressionService;
use App\Services\Limits\MonthlySendQuota;
use App\Services\Sending\DailySendQuota;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sends up to CampaignSender::BATCH_SIZE recipients of a campaign.
 *
 * Guarantees:
 *  - Each recipient is claimed atomically (pending → sending) before sending: two workers can
 *    never send to the same person, and a re-run of the job skips everyone already handled.
 *  - The provider receives a stable reference (Idempotency-Key in ElasticEmail).
 *  - Pause/cancel is honoured before every email; rate limit and daily quota are respected.
 *  - Temporary errors put the recipient back as pending and retry the job with exponential backoff.
 */
class SendCampaignBatch implements ShouldQueue
{
    use Batchable, Queueable;

    public const int MAX_ATTEMPTS_PER_RECIPIENT = 5;

    /** Consecutive permanent errors that pause the campaign (e.g. revoked credentials). */
    public const int PAUSE_AFTER_CONSECUTIVE_ERRORS = 5;

    public int $timeout = 900;

    public int $tries = 0;

    public int $maxExceptions = 3;

    /**
     * @param  list<int>  $recipientIds
     */
    public function __construct(public readonly int $campaignId, public readonly array $recipientIds) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(3);
    }

    public function handle(
        CurrentOrganization $current,
        MailTransportFactory $transports,
        CampaignRenderer $renderer,
        DailySendQuota $quota,
        EventRecorder $events,
        SuppressionService $suppressions,
        CampaignSender $sender,
        MonthlySendQuota $monthly,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $campaign = Campaign::query()->withoutGlobalScopes()->with(['mailConnection', 'audience'])->find($this->campaignId);

        // Regular campaigns send while "sending"; automation emails while their flow exists.
        $sendable = $campaign?->isAutomation() ? CampaignStatus::Automation : CampaignStatus::Sending;

        if ($campaign === null || $campaign->status !== $sendable) {
            return; // paused or cancelled: pending recipients stay pending
        }

        $organization = Organization::query()->findOrFail($campaign->organization_id);

        $current->runAs($organization, function () use ($campaign, $sendable, $organization, $transports, $renderer, $quota, $events, $suppressions, $sender, $monthly) {
            $connection = $campaign->mailConnection;

            if ($connection === null) {
                if ($campaign->isAutomation()) {
                    DB::table('campaign_recipients')->whereIn('id', $this->recipientIds)->where('status', RecipientStatus::Pending->value)
                        ->update(['status' => RecipientStatus::Failed->value, 'last_error' => 'La automatización no tiene conexión de envío.', 'updated_at' => now()]);
                } else {
                    $sender->pause($campaign, 'La conexión de envío de la campaña ya no existe. Elige otra y reanuda.');
                }

                return;
            }

            $transport = $transports->for($connection);
            $consecutiveErrors = 0;

            foreach ($this->recipientIds as $recipientId) {
                if (Campaign::query()->whereKey($campaign->id)->toBase()->value('status') !== $sendable->value) {
                    return;
                }

                if (! $this->claim($recipientId)) {
                    continue;
                }

                /** @var CampaignRecipient $recipient */
                $recipient = CampaignRecipient::query()->with(['contact', 'variant'])->findOrFail($recipientId);
                $contact = $recipient->contact;

                // The person may have unsubscribed or bounced after the list was frozen.
                $stillSubscribed = $contact !== null
                    && Subscription::query()->whereKey($recipient->subscription_id)->toBase()->value('status') === ContactStatus::Subscribed->value
                    && ! $suppressions->isSuppressed($contact->email, $campaign->audience_id);

                if (! $stillSubscribed) {
                    $this->finishRecipient($recipient, RecipientStatus::Skipped, 'Ya no está suscrito');

                    continue;
                }

                if ($campaign->rate_per_minute !== null) {
                    $key = 'campaign-rate:'.$connection->id;

                    if (RateLimiter::tooManyAttempts($key, $campaign->rate_per_minute)) {
                        $this->unclaim($recipient);
                        $this->release(max(1, RateLimiter::availableIn($key)));

                        return;
                    }

                    RateLimiter::hit($key, 60);
                }

                if (! $quota->reserve($connection)) {
                    $this->unclaim($recipient);

                    if ($campaign->isAutomation()) {
                        $this->release(1800); // automation emails wait for tomorrow's quota

                        return;
                    }

                    $sender->pause($campaign, sprintf('Se alcanzó el límite diario de la conexión «%s» (%d). Reanuda mañana o cambia de conexión.', $connection->name, (int) $connection->daily_limit));

                    return;
                }

                if (! $monthly->reserve($organization)) {
                    $quota->release($connection);
                    $this->unclaim($recipient);

                    if ($campaign->isAutomation()) {
                        $this->release(3600); // automation emails wait until the limit allows it

                        return;
                    }

                    $sender->pause($campaign, sprintf('Se alcanzó el límite mensual de %s envíos de la organización. Reanuda el próximo mes o pide a un administrador que lo amplíe.', number_format((int) $organization->limit('monthly_sends'), 0, '.', ',')));

                    return;
                }

                $version = $campaign->withVariant($recipient->variant);
                $email = $renderer->render($version, $organization, $campaign->audience, $recipient, $contact);

                try {
                    $result = $transport->send(new OutgoingEmail(
                        fromEmail: $campaign->from_email ?: $connection->from_email,
                        fromName: $version->from_name ?: $connection->from_name,
                        to: $recipient->email,
                        toName: $contact->full_name ?: null,
                        subject: $email->subject,
                        html: $email->html,
                        text: $email->text,
                        replyTo: $campaign->reply_to ?: $connection->reply_to,
                        headers: $email->headers,
                        reference: 'cr-'.$recipient->id,
                    ));
                } catch (TransportException $e) {
                    $quota->release($connection);
                    $monthly->release($organization);

                    if ($e->retryable && $recipient->attempts < self::MAX_ATTEMPTS_PER_RECIPIENT) {
                        $this->unclaim($recipient, $e->getMessage());
                        $this->release(min(1800, 60 * (2 ** $recipient->attempts)));

                        return;
                    }

                    $this->finishRecipient($recipient, RecipientStatus::Failed, $e->getMessage());
                    $events->record(EventType::Failed, $recipient, meta: ['error' => mb_substr($e->getMessage(), 0, 500)]);

                    if (++$consecutiveErrors >= self::PAUSE_AFTER_CONSECUTIVE_ERRORS && ! $campaign->isAutomation()) {
                        $sender->pause($campaign, 'Se pausó tras varios errores seguidos: '.mb_substr($e->getMessage(), 0, 200));

                        return;
                    }

                    continue;
                }

                $consecutiveErrors = 0;
                $recipient->forceFill([
                    'status' => RecipientStatus::Sent,
                    'sent_at' => now(),
                    'message_id' => $result->messageId,
                    'provider_id' => $result->providerId,
                    'last_error' => null,
                ])->save();
                $events->record(EventType::Sent, $recipient, dedupeKey: 'sent:'.$recipient->id);
            }
        });
    }

    /**
     * pending → sending, atomically.
     */
    private function claim(int $recipientId): bool
    {
        return DB::table('campaign_recipients')
            ->where('id', $recipientId)
            ->where('status', RecipientStatus::Pending->value)
            ->update([
                'status' => RecipientStatus::Sending->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * sending → pending. Waits (rate limit, daily quota) are not counted as attempts;
     * provider errors are.
     */
    private function unclaim(CampaignRecipient $recipient, ?string $error = null): void
    {
        DB::table('campaign_recipients')->where('id', $recipient->id)->update([
            'status' => RecipientStatus::Pending->value,
            'attempts' => $error === null ? DB::raw('GREATEST(attempts - 1, 0)') : DB::raw('attempts'),
            'last_error' => $error,
            'updated_at' => now(),
        ]);
    }

    private function finishRecipient(CampaignRecipient $recipient, RecipientStatus $status, ?string $error): void
    {
        $recipient->forceFill(['status' => $status, 'last_error' => $error !== null ? mb_substr($error, 0, 1000) : null])->save();
    }
}
