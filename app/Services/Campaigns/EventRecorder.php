<?php

namespace App\Services\Campaigns;

use App\Enums\EventType;
use App\Enums\WebhookEvent;
use App\Models\CampaignRecipient;
use App\Services\Webhooks\OutgoingWebhooks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point to write email_events. With a dedupe key the same event
 * (e.g. a webhook delivered twice) is stored only once.
 */
class EventRecorder
{
    /**
     * @param  array<string, mixed>  $meta
     * @return bool false if it was a duplicate
     */
    public function record(
        EventType $type,
        CampaignRecipient $recipient,
        ?Carbon $occurredAt = null,
        ?string $dedupeKey = null,
        ?int $linkId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $meta = [],
    ): bool {
        $recorded = DB::transaction(function () use ($type, $recipient, $occurredAt, $dedupeKey, $linkId, $ip, $userAgent, $meta) {
            if ($dedupeKey !== null) {
                $key = mb_substr($dedupeKey, 0, 191);

                if (DB::table('email_event_keys')->insertOrIgnore(['dedupe_key' => $key, 'created_at' => now()]) === 0) {
                    return false;
                }
            }

            DB::table('email_events')->insert([
                'organization_id' => $recipient->organization_id,
                'campaign_id' => $recipient->campaign_id,
                'campaign_recipient_id' => $recipient->id,
                'contact_id' => $recipient->contact_id,
                'campaign_link_id' => $linkId,
                'type' => $type->value,
                'occurred_at' => ($occurredAt ?? now())->utc(),
                'dedupe_key' => $dedupeKey !== null ? mb_substr($dedupeKey, 0, 191) : null,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
                'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return true;
        });

        if ($recorded && in_array($type, [EventType::Click, EventType::HardBounce, EventType::SoftBounce], true)) {
            $this->notify($type, $recipient, $linkId);
        }

        return $recorded;
    }

    /**
     * Outgoing webhooks (email.clicked, email.bounced).
     */
    private function notify(EventType $type, CampaignRecipient $recipient, ?int $linkId): void
    {
        $campaign = DB::table('campaigns')->where('id', $recipient->campaign_id)->first(['id', 'name', 'kind']);

        app(OutgoingWebhooks::class)->dispatch($recipient->organization_id, $type === EventType::Click ? WebhookEvent::EmailClicked : WebhookEvent::EmailBounced, array_filter([
            'email' => $recipient->email,
            'contact_id' => $recipient->contact_id,
            'campaign' => $campaign !== null ? ['id' => (int) $campaign->id, 'name' => (string) $campaign->name, 'kind' => (string) $campaign->kind] : null,
            'bounce_type' => match ($type) {
                EventType::HardBounce => 'hard',
                EventType::SoftBounce => 'soft',
                default => null,
            },
            'url' => $linkId !== null ? DB::table('campaign_links')->where('id', $linkId)->value('url') : null,
        ], fn ($v) => $v !== null));
    }
}
