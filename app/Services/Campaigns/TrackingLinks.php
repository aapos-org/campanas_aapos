<?php

namespace App\Services\Campaigns;

use App\Models\CampaignRecipient;

/**
 * Signed tracking URLs (HMAC-SHA256 with APP_KEY). The click redirect target is always
 * read from campaign_links, never from the URL: no open redirect.
 */
class TrackingLinks
{
    public function openUrl(CampaignRecipient $recipient): string
    {
        return route('track.open', ['token' => $recipient->token, 'signature' => $this->sign($recipient->token, 'open')]);
    }

    public function clickUrl(CampaignRecipient $recipient, string $linkHash): string
    {
        return route('track.click', [
            'token' => $recipient->token,
            'link' => $linkHash,
            'signature' => $this->sign($recipient->token, 'click:'.$linkHash),
        ]);
    }

    public function valid(string $token, string $purpose, string $signature): bool
    {
        return hash_equals($this->sign($token, $purpose), $signature);
    }

    private function sign(string $token, string $purpose): string
    {
        return substr(hash_hmac('sha256', $token.'|'.$purpose, (string) config('app.key')), 0, 24);
    }
}
