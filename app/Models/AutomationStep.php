<?php

namespace App\Models;

use App\Enums\AutomationStepType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $automation_id
 * @property int $position
 * @property AutomationStepType $type
 * @property array<string, mixed> $config
 * @property int|null $campaign_id
 * @property-read Automation|null $automation
 * @property-read Campaign|null $campaign
 */
#[Fillable(['automation_id', 'position', 'type', 'config', 'campaign_id'])]
class AutomationStep extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['config' => '{}'];

    protected function casts(): array
    {
        return ['type' => AutomationStepType::class, 'config' => 'array'];
    }

    /**
     * Conditions of a condition step, normalized.
     *
     * @return list<array{field: string, op: string, value: mixed}>
     */
    public function conditionList(): array
    {
        $list = [];

        foreach ((array) ($this->config['conditions'] ?? []) as $condition) {
            if (is_array($condition)) {
                $list[] = ['field' => (string) ($condition['field'] ?? ''), 'op' => (string) ($condition['op'] ?? ''), 'value' => $condition['value'] ?? null];
            }
        }

        return $list;
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScopes();
    }
}
