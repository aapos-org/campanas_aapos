<?php

namespace App\Models;

use App\Enums\AutomationTrigger;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property int $audience_id
 * @property AutomationTrigger $trigger_type
 * @property array<string, mixed> $trigger_config
 * @property string $status
 * @property bool $allow_reentry
 * @property int|null $mail_connection_id
 * @property string|null $from_name
 * @property string|null $from_email
 * @property string|null $reply_to
 * @property Carbon|null $activated_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Audience|null $audience
 * @property-read MailConnection|null $mailConnection
 * @property-read Collection<int, AutomationStep> $steps
 */
#[Fillable(['organization_id', 'name', 'audience_id', 'trigger_type', 'trigger_config', 'status', 'allow_reentry', 'mail_connection_id', 'from_name', 'from_email', 'reply_to', 'activated_at', 'created_by'])]
class Automation extends Model
{
    use BelongsToOrganization;

    /** @var array<string, mixed> */
    protected $attributes = [
        'trigger_config' => '{}',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'trigger_type' => AutomationTrigger::class,
            'trigger_config' => 'array',
            'allow_reentry' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Audience, $this>
     */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    /**
     * @return BelongsTo<MailConnection, $this>
     */
    public function mailConnection(): BelongsTo
    {
        return $this->belongsTo(MailConnection::class);
    }

    /**
     * @return HasMany<AutomationStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class)->orderBy('position');
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
