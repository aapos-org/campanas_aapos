<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /** @var array<string, int>|null */
    public ?array $stats = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subject' => $this->subject,
            'audience_id' => $this->audience_id,
            /** draft | scheduled | sending | testing | paused | sent | cancelled | failed */
            'status' => $this->status->value,
            'is_historic' => $this->is_historic,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'recipients_count' => $this->recipients_count,
            'sent_count' => $this->sent_count,
            'stats' => $this->when($this->stats !== null, fn () => $this->stats),
        ];
    }
}
