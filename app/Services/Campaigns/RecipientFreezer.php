<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * Freezes the recipient list when sending starts: subscribed contacts of the audience
 * (optionally with any of the selected tags), minus every global or audience suppression.
 * One INSERT … SELECT; re-running adds nobody twice (unique campaign_id + contact_id).
 */
class RecipientFreezer
{
    public function freeze(Campaign $campaign): int
    {
        $tagIds = array_map('intval', $campaign->tag_ids);
        $tagFilter = $tagIds === []
            ? ''
            : 'AND EXISTS (SELECT 1 FROM contact_tag ct WHERE ct.contact_id = c.id AND ct.tag_id IN ('.implode(',', array_fill(0, count($tagIds), '?')).'))';

        DB::insert(<<<SQL
            INSERT INTO campaign_recipients (organization_id, campaign_id, contact_id, subscription_id, email, status, attempts, token, created_at, updated_at)
            SELECT c.organization_id, ?, c.id, ac.id, c.email, 'pending', 0, md5(random()::text || clock_timestamp()::text || c.id::text), now(), now()
            FROM audience_contact ac
            JOIN contacts c ON c.id = ac.contact_id AND c.deleted_at IS NULL
            WHERE ac.audience_id = ?
              AND ac.status = 'subscribed'
              AND c.organization_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM suppressions s
                  WHERE s.organization_id = c.organization_id
                    AND s.email = c.email
                    AND (s.audience_id IS NULL OR s.audience_id = ac.audience_id)
              )
              {$tagFilter}
            ON CONFLICT (campaign_id, contact_id) DO NOTHING
            SQL, [$campaign->id, $campaign->audience_id, $campaign->organization_id, ...$tagIds]);

        $count = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->count();
        $campaign->forceFill(['recipients_count' => $count])->save();

        return $count;
    }

    /**
     * How many would receive it right now (for the review screen).
     */
    public function estimate(Campaign $campaign): int
    {
        if ($campaign->audience_id === null) {
            return 0;
        }

        $tagIds = array_map('intval', $campaign->tag_ids);

        return (int) DB::table('audience_contact as ac')
            ->join('contacts as c', fn ($j) => $j->on('c.id', '=', 'ac.contact_id')->whereNull('c.deleted_at'))
            ->where('ac.audience_id', $campaign->audience_id)
            ->where('ac.status', 'subscribed')
            ->whereNotExists(fn ($q) => $q->from('suppressions as s')
                ->whereColumn('s.organization_id', 'c.organization_id')
                ->whereColumn('s.email', 'c.email')
                ->where(fn ($w) => $w->whereNull('s.audience_id')->orWhereColumn('s.audience_id', 'ac.audience_id')))
            ->when($tagIds !== [], fn ($q) => $q->whereExists(fn ($e) => $e->from('contact_tag as ct')
                ->whereColumn('ct.contact_id', 'c.id')
                ->whereIn('ct.tag_id', $tagIds)))
            ->count();
    }
}
