<?php

namespace App\Models;

use App\Enums\EventType;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Row of the month-partitioned email_events table. Write through EventRecorder.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $campaign_id
 * @property int|null $campaign_recipient_id
 * @property int|null $contact_id
 * @property int|null $campaign_link_id
 * @property EventType $type
 * @property Carbon $occurred_at
 * @property string|null $dedupe_key
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $meta
 */
#[Fillable(['organization_id', 'campaign_id', 'campaign_recipient_id', 'contact_id', 'campaign_link_id', 'type', 'occurred_at', 'dedupe_key', 'ip', 'user_agent', 'meta', 'created_at'])]
class EmailEvent extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
