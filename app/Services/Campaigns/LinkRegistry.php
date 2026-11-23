<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignLink;

/**
 * Extracts the trackable links of a campaign (http/https, excluding our own system links)
 * and stores them in campaign_links. The redirect target always comes from this table.
 */
class LinkRegistry
{
    /**
     * @return array<string, string> hash => url
     */
    public function extract(string $html): array
    {
        preg_match_all('/<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $matches);

        $links = [];
        foreach ($matches[2] as $raw) {
            $url = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (! preg_match('#^https?://#i', $url)) {
                continue; // mailto:, tel:, anchors and merge-tag-only links ({{ enlace_baja }})
            }

            $links[CampaignLink::hashFor($url)] = $url;
        }

        return $links;
    }

    /**
     * @return array<string, string> hash => url
     */
    public function register(Campaign $campaign): array
    {
        $links = $this->extract((string) $campaign->html);
        $position = 0;

        foreach ($links as $hash => $url) {
            CampaignLink::query()->updateOrCreate(
                ['campaign_id' => $campaign->id, 'hash' => $hash],
                ['url' => $url, 'position' => $position++],
            );
        }

        return $links;
    }
}
