<?php

namespace App\Services\Webhooks;

use App\Enums\EventType;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\CampaignRecipient;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\WebhookCall;
use App\Services\Campaigns\EventRecorder;
use App\Services\Contacts\SuppressionService;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Support\Facades\DB;

/**
 * Applies ElasticEmail notifications:
 *  - delivered → event;
 *  - hard bounce → event + GLOBAL suppression (address cleaned everywhere);
 *  - soft bounce → event; SOFT_BOUNCE_LIMIT in a row cleans the address;
 *  - complaint → event + GLOBAL suppression;
 *  - unsubscribe → event + suppression of the campaign's audience.
 * Idempotent: the same notification is applied once (dedupe key).
 */
class ElasticEmailWebhookProcessor
{
    public const int SOFT_BOUNCE_LIMIT = 5;

    /** Notifications older than this are ignored (replay protection). */
    public const int MAX_AGE_DAYS = 30;

    public function __construct(
        private readonly EventRecorder $events,
        private readonly SuppressionService $suppressions,
        private readonly CurrentOrganization $current,
    ) {}

    public function process(WebhookCall $call): void
    {
        $payload = $call->payload;
        $items = array_is_list($payload) ? $payload : [$payload];
        $results = [];

        foreach ($items as $item) {
            $results[] = is_array($item) ? $this->apply(ElasticEmailEvent::fromArray($item)) : 'ignorado: formato desconocido';
        }

        $applied = array_filter($results, fn ($r) => str_starts_with($r, 'aplicado'));

        $call->forceFill([
            'status' => $applied !== [] ? 'processed' : 'ignored',
            'result' => mb_substr(implode("\n", $results), 0, 5000),
            'processed_at' => now(),
        ])->save();
    }

    private function apply(ElasticEmailEvent $event): string
    {
        if ($event->type === null) {
            return "ignorado: estado «{$event->rawStatus}» sin acción";
        }

        if ($event->occurredAt !== null && $event->occurredAt->lt(now()->subDays(self::MAX_AGE_DAYS))) {
            return 'ignorado: evento demasiado antiguo';
        }

        $recipient = $this->findRecipient($event);
        $organization = $recipient !== null
            ? Organization::query()->find($recipient->organization_id)
            : (Organization::query()->count() === 1 ? Organization::query()->first() : null);

        if ($organization === null) {
            return 'ignorado: no se identificó el destinatario ni la organización';
        }

        return $this->current->runAs($organization, function () use ($event, $recipient) {
            if ($recipient !== null && ! $this->events->record($event->type, $recipient, $event->occurredAt, $event->dedupeKey(), meta: array_filter([
                'provider' => 'elasticemail',
                'status' => $event->rawStatus,
                'category' => $event->category,
            ]))) {
                return 'ignorado: duplicado';
            }

            $email = $recipient->email ?? $event->to;

            if ($email === null) {
                return 'aplicado: solo evento';
            }

            $detail = trim("ElasticEmail: {$event->rawStatus} {$event->category}");

            match ($event->type) {
                EventType::HardBounce => $this->suppressions->suppress($email, SuppressionReason::HardBounce, null, 'elasticemail', $detail),
                EventType::Complaint => $this->suppressions->suppress($email, SuppressionReason::Complaint, null, 'elasticemail', $detail),
                EventType::Unsubscribe => $this->suppressions->suppress($email, SuppressionReason::Unsubscribed, $this->audienceOf($recipient), 'elasticemail', $detail),
                EventType::SoftBounce => $this->softBounce($recipient, $email, $detail),
                default => null,
            };

            if ($event->type === EventType::Delivered && $recipient?->subscription_id !== null) {
                DB::table('audience_contact')->where('id', $recipient->subscription_id)->update(['soft_bounces' => 0]);
            }

            return "aplicado: {$event->type->value} {$email}";
        });
    }

    private function softBounce(?CampaignRecipient $recipient, string $email, string $detail): void
    {
        if ($recipient?->subscription_id === null) {
            return;
        }

        DB::table('audience_contact')->where('id', $recipient->subscription_id)->increment('soft_bounces');
        $count = (int) Subscription::query()->whereKey($recipient->subscription_id)->toBase()->value('soft_bounces');

        if ($count >= self::SOFT_BOUNCE_LIMIT) {
            $this->suppressions->suppress($email, SuppressionReason::Cleaned, null, 'elasticemail', "{$count} rebotes suaves seguidos · {$detail}");
        }
    }

    private function audienceOf(?CampaignRecipient $recipient): ?Audience
    {
        $audienceId = $recipient?->campaign()->withoutGlobalScope(OrganizationScope::class)->toBase()->value('audience_id');

        return $audienceId !== null ? Audience::query()->find((int) $audienceId) : null;
    }

    private function findRecipient(ElasticEmailEvent $event): ?CampaignRecipient
    {
        $query = fn () => CampaignRecipient::query()->withoutGlobalScope(OrganizationScope::class);

        if (($id = $event->recipientIdFromPostback()) !== null && ($found = $query()->find($id)) !== null) {
            return $found;
        }

        if ($event->messageId !== null && ($found = $query()->where('message_id', $event->messageId)->latest('id')->first()) !== null) {
            return $found;
        }

        if ($event->transactionId !== null && ($found = $query()->where('provider_id', $event->transactionId)->latest('id')->first()) !== null) {
            return $found;
        }

        // Last resort: the most recent recipient with that address in the last 30 days.
        return $event->to !== null
            ? $query()->where('email', $event->to)->where('sent_at', '>=', now()->subDays(30))->latest('sent_at')->first()
            : null;
    }
}
