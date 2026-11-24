<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Segment;
use App\Services\Segments\SegmentQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Freezes the recipient list when sending starts: subscribed contacts of the audience
 * (optionally with any of the selected tags and matching the segment), minus every global
 * or audience suppression. One INSERT … SELECT; re-running adds nobody twice.
 */
class RecipientFreezer
{
    public function __construct(private readonly SegmentQuery $segments) {}

    public function freeze(Campaign $campaign): int
    {
        $select = $this->eligible($campaign)->selectRaw(
            "c.organization_id, ?, c.id, ac.id, c.email, 'pending', 0, md5(random()::text || clock_timestamp()::text || c.id::text), now(), now()",
            [$campaign->id],
        );

        // ON CONFLICT DO NOTHING: the partial unique index (campaign_id, contact_id) protects re-runs.
        DB::table('campaign_recipients')->insertOrIgnoreUsing(
            ['organization_id', 'campaign_id', 'contact_id', 'subscription_id', 'email', 'status', 'attempts', 'token', 'created_at', 'updated_at'],
            $select,
        );

        $count = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->count();
        $campaign->forceFill(['recipients_count' => $count])->save();

        return $count;
    }

    /**
     * How many would receive it right now (for the review screen).
     */
    public function estimate(Campaign $campaign): int
    {
        return $campaign->audience_id === null ? 0 : $this->eligible($campaign)->count();
    }

    private function eligible(Campaign $campaign): Builder
    {
        $tagIds = array_map('intval', $campaign->tag_ids);
        $query = $this->segments->base((int) $campaign->audience_id)
            ->where('ac.status', 'subscribed')
            ->where('c.organization_id', $campaign->organization_id)
            ->whereNotExists(fn ($q) => $q->from('suppressions as s')
                ->whereColumn('s.organization_id', 'c.organization_id')
                ->whereColumn('s.email', 'c.email')
                ->where(fn ($w) => $w->whereNull('s.audience_id')->orWhereColumn('s.audience_id', 'ac.audience_id')))
            ->when($tagIds !== [], fn ($q) => $q->whereExists(fn ($e) => $e->from('contact_tag as ct')
                ->whereColumn('ct.contact_id', 'c.id')
                ->whereIn('ct.tag_id', $tagIds)));

        $segment = $campaign->segment_id !== null ? Segment::query()->find($campaign->segment_id) : null;

        if ($segment !== null && $segment->audience_id === $campaign->audience_id) {
            $this->segments->apply($query, $segment->match, $segment->conditions);
        }

        return $query;
    }
}
