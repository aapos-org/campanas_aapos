<?php

namespace App\Http\Resources;

use App\Models\Contact;
use App\Models\Subscription;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            /** Custom fields by key (see GET /audiences for the available keys). */
            'fields' => (object) $this->fields,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn (Tag $t) => $t->name)->values()),
            'subscriptions' => $this->whenLoaded('subscriptions', fn () => $this->subscriptions->map(fn (Subscription $s) => [
                'audience_id' => $s->audience_id,
                /** subscribed | unsubscribed | cleaned | pending | non_subscribed */
                'status' => $s->status->value,
                'subscribed_at' => $s->subscribed_at?->toIso8601String(),
                'unsubscribed_at' => $s->unsubscribed_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
