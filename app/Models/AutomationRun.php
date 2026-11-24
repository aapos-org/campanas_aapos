<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One contact going through an automation.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $automation_id
 * @property int $contact_id
 * @property int|null $subscription_id
 * @property string $trigger_key
 * @property string $status
 * @property int|null $next_step_id
 * @property CarbonImmutable|null $next_run_at
 * @property CarbonImmutable $entered_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $exit_reason
 * @property-read Automation|null $automation
 * @property-read Contact|null $contact
 */
#[Fillable(['organization_id', 'automation_id', 'contact_id', 'subscription_id', 'trigger_key', 'status', 'next_step_id', 'next_run_at', 'entered_at', 'finished_at', 'exit_reason'])]
class AutomationRun extends Model
{
    use BelongsToOrganization;

    public const array STATUS_LABELS = [
        'active' => 'En curso',
        'completed' => 'Completado',
        'exited' => 'Salió',
        'failed' => 'Falló',
    ];

    protected function casts(): array
    {
        return ['next_run_at' => 'datetime', 'entered_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<AutomationRunLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AutomationRunLog::class)->orderBy('id');
    }

    public function log(string $message, ?int $stepId = null): void
    {
        $this->logs()->create(['message' => mb_substr($message, 0, 500), 'automation_step_id' => $stepId, 'created_at' => now()]);
    }
}
