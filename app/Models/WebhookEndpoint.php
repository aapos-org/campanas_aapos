<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Outgoing webhook destination configured by the organization.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable(['organization_id', 'name', 'url', 'secret', 'events', 'is_active', 'consecutive_failures', 'last_success_at', 'last_failure_at', 'created_by'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    use BelongsToOrganization;

    /** Disabled automatically after this many deliveries failed in a row. */
    public const int DISABLE_AFTER_FAILURES = 20;

    /** @var array<string, mixed> */
    protected $attributes = ['events' => '[]', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
