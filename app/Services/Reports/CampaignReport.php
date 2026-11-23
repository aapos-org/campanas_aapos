<?php

namespace App\Services\Reports;

use App\Enums\EventType;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Campaign report built from email_events (our own tracking + provider webhooks).
 * Historic Mailchimp campaigns use the report_summary snapshot instead.
 */
class CampaignReport
{
    /** Recipient activity filters (key => Spanish label). */
    public const array FILTERS = [
        'opened' => 'Abrieron',
        'not_opened' => 'No abrieron',
        'clicked' => 'Hicieron clic',
        'bounced' => 'Rebotaron',
        'unsubscribed' => 'Se dieron de baja',
        'complained' => 'Marcaron como spam',
        'failed' => 'Error de envío',
    ];

    /**
     * @return array<string, int>
     */
    public function metrics(Campaign $campaign): array
    {
        $rows = DB::table('email_events')->where('campaign_id', $campaign->id)
            ->selectRaw('type, count(*) as total, count(DISTINCT campaign_recipient_id) as uniques')
            ->groupBy('type')->get()->keyBy('type');

        $get = fn (EventType $type, string $field = 'uniques') => (int) ($rows[$type->value]->{$field} ?? 0);

        return [
            'sent' => $campaign->sent_count,
            'delivered' => $get(EventType::Delivered),
            'opens' => $get(EventType::Open, 'total'),
            'unique_opens' => $get(EventType::Open),
            'clicks' => $get(EventType::Click, 'total'),
            'unique_clicks' => $get(EventType::Click),
            'hard_bounces' => $get(EventType::HardBounce),
            'soft_bounces' => $get(EventType::SoftBounce),
            'complaints' => $get(EventType::Complaint),
            'unsubscribes' => $get(EventType::Unsubscribe),
            'failed' => $campaign->failed_count,
        ];
    }

    /**
     * Links ordered by clicks (historic Mailchimp totals included).
     *
     * @return list<array{url: string, clicks: int, unique_clicks: int}>
     */
    public function links(Campaign $campaign, int $limit = 20): array
    {
        $clicks = DB::table('email_events')
            ->where('campaign_id', $campaign->id)->where('type', EventType::Click->value)
            ->selectRaw('campaign_link_id, count(*) as clicks, count(DISTINCT campaign_recipient_id) as uniques')
            ->groupBy('campaign_link_id');

        return array_values(DB::table('campaign_links as l')
            ->leftJoinSub($clicks, 'c', 'c.campaign_link_id', '=', 'l.id')
            ->where('l.campaign_id', $campaign->id)
            ->selectRaw('l.url, COALESCE(c.clicks, 0) + COALESCE(l.historic_clicks, 0) as clicks, COALESCE(c.uniques, 0) + COALESCE(l.historic_unique_clicks, 0) as unique_clicks')
            ->orderByDesc('clicks')->orderBy('l.position')
            ->limit($limit)->get()
            ->map(fn ($r) => ['url' => (string) $r->url, 'clicks' => (int) $r->clicks, 'unique_clicks' => (int) $r->unique_clicks])
            ->all());
    }

    /**
     * Opens and clicks over time in the organization's timezone: hourly during the first
     * 72 hours after sending, daily afterwards (up to 60 days).
     *
     * @return array{unit: 'hour'|'day', points: list<array{at: string, opens: int, clicks: int}>}
     */
    public function timeline(Campaign $campaign, string $timezone): array
    {
        $start = $campaign->started_at ? CarbonImmutable::parse($campaign->started_at) : null;

        if ($start === null) {
            return ['unit' => 'hour', 'points' => []];
        }

        $unit = $start->diffInHours(now()) <= 72 ? 'hour' : 'day';
        $end = $unit === 'hour' ? $start->addHours(72) : $start->addDays(60);

        $rows = DB::table('email_events')
            ->where('campaign_id', $campaign->id)
            ->whereIn('type', [EventType::Open->value, EventType::Click->value])
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw("date_trunc('{$unit}', (occurred_at AT TIME ZONE 'UTC') AT TIME ZONE ?) as bucket", [$timezone])
            ->selectRaw("count(*) FILTER (WHERE type = 'open') as opens, count(*) FILTER (WHERE type = 'click') as clicks")
            ->groupBy('bucket')->orderBy('bucket')->get();

        return [
            'unit' => $unit,
            'points' => array_values($rows->map(fn ($r) => [
                'at' => CarbonImmutable::parse((string) $r->bucket)->format($unit === 'hour' ? 'Y-m-d H:00' : 'Y-m-d'),
                'opens' => (int) $r->opens,
                'clicks' => (int) $r->clicks,
            ])->all()),
        ];
    }

    /**
     * Recipients with their activity, filterable and searchable.
     *
     * @return EloquentBuilder<CampaignRecipient>
     */
    public function recipients(Campaign $campaign, ?string $filter = null, ?string $search = null): EloquentBuilder
    {
        $activity = DB::table('email_events')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('campaign_recipient_id')
            ->selectRaw('campaign_recipient_id')
            ->selectRaw("count(*) FILTER (WHERE type = 'open') as opens")
            ->selectRaw("count(*) FILTER (WHERE type = 'click') as clicks")
            ->selectRaw("bool_or(type = 'bounce_hard') as hard_bounce")
            ->selectRaw("bool_or(type = 'bounce_soft') as soft_bounce")
            ->selectRaw("bool_or(type = 'unsubscribe') as unsubscribed")
            ->selectRaw("bool_or(type = 'complaint') as complained")
            ->selectRaw("max(occurred_at) FILTER (WHERE type IN ('open', 'click')) as last_activity_at")
            ->groupBy('campaign_recipient_id');

        return CampaignRecipient::query()
            ->where('campaign_recipients.campaign_id', $campaign->id)
            ->leftJoinSub($activity, 'a', 'a.campaign_recipient_id', '=', 'campaign_recipients.id')
            ->leftJoin('contacts', 'contacts.id', '=', 'campaign_recipients.contact_id')
            ->select('campaign_recipients.*')
            ->addSelect(['contacts.first_name', 'contacts.last_name'])
            ->selectRaw('COALESCE(a.opens, 0) as opens, COALESCE(a.clicks, 0) as clicks')
            ->selectRaw('COALESCE(a.hard_bounce, false) as hard_bounce, COALESCE(a.soft_bounce, false) as soft_bounce')
            ->selectRaw('COALESCE(a.unsubscribed, false) as unsubscribed, COALESCE(a.complained, false) as complained, a.last_activity_at')
            ->when($search, fn ($q, $s) => $q->where('campaign_recipients.email', 'ilike', '%'.addcslashes((string) $s, '%_\\').'%'))
            ->when($filter, fn ($q, $f) => match ($f) {
                'opened' => $q->where('a.opens', '>', 0),
                'not_opened' => $q->where('campaign_recipients.status', 'sent')->where(fn (EloquentBuilder|Builder $w) => $w->whereNull('a.opens')->orWhere('a.opens', 0)),
                'clicked' => $q->where('a.clicks', '>', 0),
                'bounced' => $q->where(fn (EloquentBuilder|Builder $w) => $w->where('a.hard_bounce', true)->orWhere('a.soft_bounce', true)),
                'unsubscribed' => $q->where('a.unsubscribed', true),
                'complained' => $q->where('a.complained', true),
                'failed' => $q->where('campaign_recipients.status', 'failed'),
                default => $q,
            })
            ->orderBy('campaign_recipients.id');
    }

    /**
     * @return array<string, mixed>
     */
    public static function recipientRow(CampaignRecipient $r): array
    {
        $attributes = $r->getAttributes();

        return [
            'id' => $r->id,
            'contact_id' => $r->contact_id,
            'email' => $r->email,
            'name' => trim(($attributes['first_name'] ?? '').' '.($attributes['last_name'] ?? '')),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'sent_at' => $r->sent_at?->toIso8601String(),
            'opens' => (int) ($attributes['opens'] ?? 0),
            'clicks' => (int) ($attributes['clicks'] ?? 0),
            'bounce' => (bool) ($attributes['hard_bounce'] ?? false) ? 'duro' : ((bool) ($attributes['soft_bounce'] ?? false) ? 'suave' : null),
            'unsubscribed' => (bool) ($attributes['unsubscribed'] ?? false),
            'complained' => (bool) ($attributes['complained'] ?? false),
            'last_activity_at' => isset($attributes['last_activity_at']) ? CarbonImmutable::parse($attributes['last_activity_at'], 'UTC')->toIso8601String() : null,
            'error' => $r->last_error,
        ];
    }
}
