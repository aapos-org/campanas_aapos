<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Jobs\SendCampaignBatch;
use App\Models\Campaign;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates sending: freeze recipients → split into batches → queue jobs.
 * Pause/cancel are checked by the jobs before every email; resume re-queues only
 * pending recipients, so nobody who already received the email gets it twice.
 */
class CampaignSender
{
    public const int BATCH_SIZE = 200;

    public function __construct(
        private readonly RecipientFreezer $freezer,
        private readonly LinkRegistry $links,
    ) {}

    public function start(Campaign $campaign, ?User $by = null): void
    {
        $claimed = Campaign::query()->whereKey($campaign->id)
            ->whereIn('status', [CampaignStatus::Draft->value, CampaignStatus::Scheduled->value])
            ->update(['status' => CampaignStatus::Sending->value, 'started_at' => now(), 'status_reason' => null]);

        if ($claimed === 0) {
            throw new RuntimeException('La campaña ya se está enviando o ya se envió.');
        }

        $campaign->refresh();
        $this->links->register($campaign);

        foreach ($campaign->variants as $variant) {
            $this->links->register($campaign->withVariant($variant));
        }

        $count = $this->freezer->freeze($campaign);

        if ($campaign->isAbTest()) {
            app(AbTester::class)->assignSample($campaign);
        }

        Audit::record('campaigns.started', 'Envío de campaña iniciado', $campaign, ['recipients' => $count], $by, $campaign->organization_id);

        if ($count === 0) {
            $this->finish($campaign);

            return;
        }

        $this->dispatchPending($campaign);
    }

    public function pause(Campaign $campaign, ?string $reason = null, ?User $by = null): void
    {
        Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending->value)
            ->update(['status' => CampaignStatus::Paused->value, 'status_reason' => $reason]);

        Audit::record('campaigns.paused', 'Envío de campaña pausado', $campaign, ['reason' => $reason], $by, $campaign->organization_id);
    }

    public function resume(Campaign $campaign, ?User $by = null): void
    {
        $resumed = Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Paused->value)
            ->update(['status' => CampaignStatus::Sending->value, 'status_reason' => null]);

        if ($resumed === 0) {
            return;
        }

        Audit::record('campaigns.resumed', 'Envío de campaña reanudado', $campaign, [], $by, $campaign->organization_id);
        $this->dispatchPending($campaign->refresh());
    }

    public function cancel(Campaign $campaign, ?User $by = null): void
    {
        $cancelled = Campaign::query()->whereKey($campaign->id)
            ->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Sending->value, CampaignStatus::Paused->value, CampaignStatus::Testing->value])
            ->update(['status' => CampaignStatus::Cancelled->value, 'finished_at' => now()]);

        if ($cancelled === 0) {
            return;
        }

        DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->where('status', RecipientStatus::Pending->value)
            ->update(['status' => RecipientStatus::Skipped->value, 'last_error' => 'Campaña cancelada', 'updated_at' => now()]);

        $this->refreshCounters($campaign);
        Audit::record('campaigns.cancelled', 'Campaña cancelada', $campaign, [], $by, $campaign->organization_id);
    }

    /**
     * Called when a batch finishes: if nothing is pending, the campaign is done.
     */
    public function finishIfDone(Campaign $campaign): void
    {
        $campaign->refresh();
        $this->refreshCounters($campaign);

        if ($campaign->status !== CampaignStatus::Sending) {
            return;
        }

        $pending = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->where('status', RecipientStatus::Pending->value)
            ->when($campaign->abPending(), fn ($q) => $q->whereNotNull('variant_id'))
            ->exists();

        if ($pending) {
            return;
        }

        if ($campaign->abPending()) {
            // Sample sent: wait for the results before sending the winner to the rest.
            Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending->value)
                ->update(['status' => CampaignStatus::Testing->value, 'ab_sample_finished_at' => now()]);
            Audit::record('campaigns.ab_sample_sent', 'Prueba A/B: muestra enviada', $campaign, [], organizationId: $campaign->organization_id);

            return;
        }

        $this->finish($campaign);
    }

    /**
     * After the A/B winner is chosen: send it to the rest (or finish if nobody is left).
     */
    public function continueAfterTest(Campaign $campaign): void
    {
        $campaign->refresh();

        if (! DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->where('status', RecipientStatus::Pending->value)->exists()) {
            $this->finish($campaign);

            return;
        }

        $this->dispatchPending($campaign);
    }

    public function refreshCounters(Campaign $campaign): void
    {
        $counts = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $campaign->forceFill([
            'sent_count' => (int) ($counts[RecipientStatus::Sent->value] ?? 0),
            'failed_count' => (int) ($counts[RecipientStatus::Failed->value] ?? 0),
            'skipped_count' => (int) ($counts[RecipientStatus::Skipped->value] ?? 0),
        ])->save();
    }

    private function dispatchPending(Campaign $campaign): void
    {
        $ids = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->where('status', RecipientStatus::Pending->value)
            ->when($campaign->abPending(), fn ($q) => $q->whereNotNull('variant_id'))
            ->orderBy('id')->pluck('id')->all();

        if ($ids === []) {
            $this->finishIfDone($campaign);

            return;
        }

        $jobs = array_map(
            fn (array $chunk) => new SendCampaignBatch($campaign->id, array_map('intval', $chunk)),
            array_chunk($ids, self::BATCH_SIZE),
        );

        $campaignId = $campaign->id;
        $batch = Bus::batch($jobs)
            ->name("Campaña {$campaign->id}")
            ->allowFailures()
            ->onConnection((string) config('queue.long_running_connection'))
            ->onQueue('emails')
            ->finally(function () use ($campaignId) {
                $campaign = Campaign::query()->withoutGlobalScopes()->find($campaignId);
                if ($campaign !== null) {
                    app(self::class)->finishIfDone($campaign);
                }
            })
            ->dispatch();

        $campaign->forceFill(['job_batch_id' => $batch->id])->save();
    }

    private function finish(Campaign $campaign): void
    {
        $campaign->forceFill(['status' => CampaignStatus::Sent, 'finished_at' => now()])->save();
        $this->refreshCounters($campaign);
        Audit::record('campaigns.sent', 'Campaña enviada', $campaign, [
            'sent' => $campaign->sent_count,
            'failed' => $campaign->failed_count,
        ], organizationId: $campaign->organization_id);
    }
}
