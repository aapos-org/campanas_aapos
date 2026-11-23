<?php

namespace App\Services\Reports;

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\EventType;
use App\Models\Campaign;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Organization-wide report: audience growth, unsubscribes per month and campaign comparison.
 */
class OverviewReport
{
    /**
     * New subscriptions and unsubscribes per month (organization timezone), oldest first.
     *
     * @return list<array{month: string, subscribed: int, unsubscribed: int}>
     */
    public function growth(string $timezone, ?int $audienceId = null, int $months = 12): array
    {
        $from = CarbonImmutable::now($timezone)->startOfMonth()->subMonths($months - 1);
        $fromUtc = $from->utc();

        $bucket = fn (string $column) => Subscription::query()
            ->when($audienceId, fn ($q, $id) => $q->where('audience_id', $id))
            ->where($column, '>=', $fromUtc)
            ->selectRaw($column === 'subscribed_at'
                ? "to_char(date_trunc('month', (subscribed_at AT TIME ZONE 'UTC') AT TIME ZONE ?), 'YYYY-MM') as month, count(*) as total"
                : "to_char(date_trunc('month', (unsubscribed_at AT TIME ZONE 'UTC') AT TIME ZONE ?), 'YYYY-MM') as month, count(*) as total", [$timezone])
            ->groupBy('month')
            ->toBase()->pluck('total', 'month');

        $subscribed = $bucket('subscribed_at');
        $unsubscribed = $bucket('unsubscribed_at');

        $rows = [];
        for ($m = $from; $m->lessThanOrEqualTo(CarbonImmutable::now($timezone)); $m = $m->addMonth()) {
            $key = $m->format('Y-m');
            $rows[] = ['month' => $key, 'subscribed' => (int) ($subscribed[$key] ?? 0), 'unsubscribed' => (int) ($unsubscribed[$key] ?? 0)];
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function totals(?int $audienceId = null): array
    {
        $counts = Subscription::query()
            ->when($audienceId, fn ($q, $id) => $q->where('audience_id', $id))
            ->selectRaw('status, count(*) as total')->groupBy('status')
            ->toBase()->pluck('total', 'status');

        return collect(ContactStatus::cases())->mapWithKeys(fn (ContactStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();
    }

    /**
     * Last sent campaigns with comparable rates (historic ones from their Mailchimp summary).
     *
     * @return list<array<string, mixed>>
     */
    public function campaigns(?int $audienceId = null, int $limit = 25): array
    {
        $campaigns = Campaign::query()
            ->where('status', CampaignStatus::Sent)
            ->when($audienceId, fn ($q, $id) => $q->where('audience_id', $id))
            ->orderByDesc('finished_at')->orderByDesc('id')
            ->limit($limit)->get();

        $live = $campaigns->where('is_historic', false)->pluck('id');
        $stats = $live->isEmpty() ? collect() : DB::table('email_events')
            ->whereIn('campaign_id', $live)
            ->selectRaw('campaign_id')
            ->selectRaw("count(DISTINCT campaign_recipient_id) FILTER (WHERE type = 'open') as opens")
            ->selectRaw("count(DISTINCT campaign_recipient_id) FILTER (WHERE type = 'click') as clicks")
            ->selectRaw("count(DISTINCT campaign_recipient_id) FILTER (WHERE type = 'unsubscribe') as unsubscribes")
            ->selectRaw("count(DISTINCT campaign_recipient_id) FILTER (WHERE type IN ('".EventType::HardBounce->value."', '".EventType::SoftBounce->value."')) as bounces")
            ->groupBy('campaign_id')->get()->keyBy('campaign_id');

        return array_values($campaigns->map(function (Campaign $c) use ($stats) {
            if ($c->is_historic) {
                $s = (array) $c->report_summary;
                $sent = (int) ($s['emails_sent'] ?? 0);
                $opens = (int) data_get($s, 'opens.unique_opens', 0);
                $clicks = (int) data_get($s, 'clicks.unique_subscriber_clicks', 0);
                $unsubscribes = (int) ($s['unsubscribed'] ?? 0);
                $bounces = (int) data_get($s, 'bounces.hard_bounces', 0) + (int) data_get($s, 'bounces.soft_bounces', 0);
            } else {
                $row = $stats[$c->id] ?? null;
                $sent = $c->sent_count;
                $opens = (int) ($row->opens ?? 0);
                $clicks = (int) ($row->clicks ?? 0);
                $unsubscribes = (int) ($row->unsubscribes ?? 0);
                $bounces = (int) ($row->bounces ?? 0);
            }

            $rate = fn (int $n) => $sent > 0 ? round($n * 100 / $sent, 1) : null;

            return [
                'id' => $c->id,
                'name' => $c->name,
                'is_historic' => $c->is_historic,
                'finished_at' => $c->finished_at?->toIso8601String(),
                'sent' => $sent,
                'open_rate' => $rate($opens),
                'click_rate' => $rate($clicks),
                'unsubscribes' => $unsubscribes,
                'bounces' => $bounces,
            ];
        })->all());
    }
}
