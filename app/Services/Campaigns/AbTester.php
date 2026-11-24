<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * A/B tests: the sample (a percentage of the frozen recipients) is split in equal halves
 * between the variants; after the waiting time the variant with the best unique open or
 * click rate is chosen and sent to everybody else.
 */
class AbTester
{
    /**
     * Assigns the random sample to the variants. Returns the sample size.
     */
    public function assignSample(Campaign $campaign): int
    {
        $variants = $campaign->variants()->pluck('id')->values()->all();
        $total = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->count();

        if (count($variants) < 2 || $total === 0) {
            return 0;
        }

        // At least one person per variant; never more than the whole list.
        $size = min($total, max(count($variants), (int) round($total * (int) $campaign->ab_sample_percent / 100)));

        $cases = [];
        $bindings = [];
        foreach ($variants as $i => $id) {
            $cases[] = 'WHEN s.rn % ? = ? THEN CAST(? AS bigint)';
            array_push($bindings, count($variants), $i, $id);
        }

        DB::update(
            'UPDATE campaign_recipients cr SET variant_id = CASE '.implode(' ', $cases).' END, updated_at = now()
             FROM (SELECT id, row_number() OVER (ORDER BY random()) AS rn FROM campaign_recipients WHERE campaign_id = ?) s
             WHERE s.id = cr.id AND s.rn <= ?',
            [...$bindings, $campaign->id, $size],
        );

        return $size;
    }

    /**
     * Per-variant results of the sample.
     *
     * @return list<array{id: int, label: string, sent: int, opens: int, clicks: int, open_rate: float|null, click_rate: float|null}>
     */
    public function results(Campaign $campaign): array
    {
        $sent = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->whereNotNull('variant_id')->where('status', 'sent')
            ->when($campaign->ab_decided_at, fn ($q, $at) => $q->where('sent_at', '<', $at))
            ->selectRaw('variant_id, count(*) as total')->groupBy('variant_id')->pluck('total', 'variant_id');

        $events = DB::table('email_events as e')
            ->join('campaign_recipients as cr', 'cr.id', '=', 'e.campaign_recipient_id')
            ->where('e.campaign_id', $campaign->id)->whereNotNull('cr.variant_id')
            ->when($campaign->ab_decided_at, fn ($q, $at) => $q->where('cr.sent_at', '<', $at))
            ->selectRaw("cr.variant_id, count(DISTINCT e.campaign_recipient_id) FILTER (WHERE e.type = 'open') as opens")
            ->selectRaw("count(DISTINCT e.campaign_recipient_id) FILTER (WHERE e.type = 'click') as clicks")
            ->groupBy('cr.variant_id')->get()->keyBy('variant_id');

        return array_values($campaign->variants()->get()->map(function (CampaignVariant $v) use ($sent, $events) {
            $n = (int) ($sent[$v->id] ?? 0);
            $opens = (int) ($events[$v->id]->opens ?? 0);
            $clicks = (int) ($events[$v->id]->clicks ?? 0);

            return [
                'id' => $v->id,
                'label' => $v->label,
                'sent' => $n,
                'opens' => $opens,
                'clicks' => $clicks,
                'open_rate' => $n > 0 ? round($opens * 100 / $n, 1) : null,
                'click_rate' => $n > 0 ? round($clicks * 100 / $n, 1) : null,
            ];
        })->all());
    }

    /**
     * Chooses the winner and sends it to the rest. Ties are broken by the other metric,
     * then by variant A.
     */
    public function decide(Campaign $campaign, ?User $by = null): ?CampaignVariant
    {
        $claimed = Campaign::query()->whereKey($campaign->id)
            ->where('status', CampaignStatus::Testing->value)->whereNull('ab_decided_at')
            ->update(['ab_decided_at' => now(), 'status' => CampaignStatus::Sending->value]);

        if ($claimed === 0) {
            return null;
        }

        $results = collect($this->results($campaign->refresh()));
        $primary = $campaign->ab_metric === 'opens' ? 'open_rate' : 'click_rate';
        $secondary = $primary === 'open_rate' ? 'click_rate' : 'open_rate';

        $winner = $results->sort(fn (array $a, array $b) => [$b[$primary] ?? -1, $b[$secondary] ?? -1, $a['label']] <=> [$a[$primary] ?? -1, $a[$secondary] ?? -1, $b['label']])->first();
        $variant = $winner !== null ? CampaignVariant::query()->find($winner['id']) : null;

        $campaign->forceFill(['ab_winner_variant_id' => $variant?->id])->save();

        DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
            ->whereNull('variant_id')->where('status', 'pending')
            ->update(['variant_id' => $variant?->id, 'updated_at' => now()]);

        Audit::record('campaigns.ab_decided', 'Prueba A/B: ganador elegido', $campaign, [
            'winner' => $variant?->label,
            'metric' => $campaign->ab_metric,
            'results' => $results->all(),
        ], $by, $campaign->organization_id);

        app(CampaignSender::class)->continueAfterTest($campaign);

        return $variant;
    }

    /**
     * Campaigns whose sample finished and whose waiting time is over.
     */
    public function decideDue(): int
    {
        $due = Campaign::query()->withoutGlobalScopes()
            ->where('status', CampaignStatus::Testing->value)->whereNull('ab_decided_at')
            ->whereNotNull('ab_sample_finished_at')
            ->whereRaw('ab_sample_finished_at + make_interval(hours => ab_wait_hours) <= ?', [now()])
            ->get();

        foreach ($due as $campaign) {
            $this->decide($campaign);
        }

        return $due->count();
    }
}
