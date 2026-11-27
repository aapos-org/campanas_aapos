<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Writing assistant configuration of an organization. The API key is encrypted at rest,
 * hidden from serialization and never sent to the browser (only its last 4 characters).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $provider
 * @property string|null $api_key
 * @property string $model
 * @property bool $enabled
 * @property bool $live_review_enabled
 * @property bool $manual_review_enabled
 * @property int $debounce_ms
 * @property int $min_chars_change
 * @property list<string> $categories
 * @property int $max_findings
 * @property bool $smart_brevity_enabled
 * @property int $smart_brevity_max_words
 * @property string|null $tone_guidelines
 * @property int|null $daily_limit
 * @property int|null $monthly_limit
 * @property int $timeout_seconds
 * @property string $status
 * @property Carbon|null $last_verified_at
 * @property string|null $last_error
 */
#[Fillable(['organization_id', 'provider', 'api_key', 'model', 'enabled', 'live_review_enabled', 'manual_review_enabled', 'debounce_ms', 'min_chars_change', 'categories', 'max_findings', 'smart_brevity_enabled', 'smart_brevity_max_words', 'tone_guidelines', 'daily_limit', 'monthly_limit', 'timeout_seconds', 'status', 'last_verified_at', 'last_error'])]
#[Hidden(['api_key'])]
class AiAssistantSetting extends Model
{
    use BelongsToOrganization;

    /** AI review categories (Smart Brevity has its own switch as well). */
    public const array CATEGORIES = [
        'ortografia' => 'Ortografía y gramática',
        'tono' => 'Tono (según la guía de la organización)',
        'claridad' => 'Claridad y llamada a la acción',
        'spam' => 'Frases tipo spam y promesas que no se pueden sostener',
        'coherencia' => 'Coherencia entre asunto y cuerpo',
        'smart_brevity' => 'Smart Brevity (método de Axios)',
    ];

    public const string DEFAULT_MODEL = 'claude-opus-5-5';

    /** @var array<string, mixed> */
    protected $attributes = [
        'categories' => '["ortografia","tono","claridad","spam","coherencia","smart_brevity"]',
        'status' => 'unverified',
        'provider' => 'anthropic',
        'model' => self::DEFAULT_MODEL,
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'categories' => 'array',
            'enabled' => 'boolean',
            'live_review_enabled' => 'boolean',
            'manual_review_enabled' => 'boolean',
            'smart_brevity_enabled' => 'boolean',
            'debounce_ms' => 'integer',
            'min_chars_change' => 'integer',
            'max_findings' => 'integer',
            'smart_brevity_max_words' => 'integer',
            'daily_limit' => 'integer',
            'monthly_limit' => 'integer',
            'timeout_seconds' => 'integer',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * Settings of the current organization (a default, unsaved row if none exists yet).
     */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    public function hasKey(): bool
    {
        return filled($this->api_key);
    }

    /** Masked key for the UI: "••••abcd". */
    public function maskedKey(): ?string
    {
        return $this->hasKey() ? '••••'.mb_substr((string) $this->api_key, -4) : null;
    }

    /** Last 4 characters only, for the audit log. */
    public function keyHint(): ?string
    {
        return $this->hasKey() ? mb_substr((string) $this->api_key, -4) : null;
    }

    /** Ready to review: switched on, with a key. */
    public function isUsable(): bool
    {
        return $this->enabled && $this->hasKey();
    }

    /**
     * Active AI categories, with Smart Brevity following its own switch.
     *
     * @return list<string>
     */
    public function activeCategories(): array
    {
        $active = array_values(array_intersect(array_keys(self::CATEGORIES), $this->categories));

        return $this->smart_brevity_enabled
            ? array_values(array_unique([...$active, 'smart_brevity']))
            : array_values(array_diff($active, ['smart_brevity']));
    }
}
