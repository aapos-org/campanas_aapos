<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of an A/B test. Null fields fall back to the campaign's own values.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string $label
 * @property string|null $subject
 * @property string|null $preheader
 * @property string|null $from_name
 * @property string|null $mjml
 * @property array<string, mixed>|null $design
 * @property string|null $html
 * @property Carbon|null $updated_at
 */
#[Fillable(['campaign_id', 'label', 'subject', 'preheader', 'from_name', 'mjml', 'design', 'html'])]
class CampaignVariant extends Model
{
    protected function casts(): array
    {
        return ['design' => 'array'];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
