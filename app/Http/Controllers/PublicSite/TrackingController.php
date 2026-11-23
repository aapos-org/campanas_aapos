<?php

namespace App\Http\Controllers\PublicSite;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Models\Organization;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\EventRecorder;
use App\Services\Campaigns\TrackingLinks;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Open pixel and click redirect. Signed links (HMAC); the redirect target is read from
 * campaign_links, so the endpoint cannot be used as an open redirect.
 */
class TrackingController extends Controller
{
    /** 1×1 transparent GIF. */
    private const string PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private readonly TrackingLinks $links,
        private readonly EventRecorder $events,
        private readonly CurrentOrganization $current,
    ) {}

    public function open(Request $request, string $token, string $signature): Response
    {
        if ($this->links->valid($token, 'open', $signature) && ($recipient = $this->recipient($token)) !== null) {
            $this->events->record(EventType::Open, $recipient, ip: $request->ip(), userAgent: $request->userAgent());
        }

        // Always the pixel, even for invalid links (reveals nothing).
        return response((string) base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $token, string $link, string $signature, CampaignRenderer $renderer): RedirectResponse
    {
        abort_unless($this->links->valid($token, 'click:'.$link, $signature), 404);

        $recipient = $this->recipient($token) ?? abort(404);
        $campaignLink = CampaignLink::query()->where('campaign_id', $recipient->campaign_id)->where('hash', $link)->first() ?? abort(404);

        $this->events->record(EventType::Click, $recipient, linkId: $campaignLink->id, ip: $request->ip(), userAgent: $request->userAgent());

        $url = $campaignLink->url;

        // Links that contain personalization variables ({{ email }}…) are resolved per recipient, URL-encoded.
        if (str_contains($url, '{{') && $recipient->contact !== null) {
            $contact = $recipient->contact;
            $values = ['first_name' => $contact->first_name, 'last_name' => $contact->last_name, 'email' => $contact->email, 'phone' => $contact->phone];
            foreach ($contact->fields as $key => $value) {
                $values[$key] ??= is_scalar($value) ? (string) $value : null;
            }
            $url = $renderer->replace($url, array_map(fn ($v) => $v === null ? null : rawurlencode((string) $v), $values), escapeHtml: false);
        }

        return redirect()->away($url);
    }

    private function recipient(string $token): ?CampaignRecipient
    {
        $recipient = CampaignRecipient::query()->withoutGlobalScope(OrganizationScope::class)->with('contact')->where('token', $token)->first();

        if ($recipient !== null) {
            $this->current->set(Organization::query()->find($recipient->organization_id));
        }

        return $recipient;
    }
}
