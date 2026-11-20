<?php

namespace App\Models;

use App\Enums\SuppressionReason;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property int|null $audience_id
 * @property SuppressionReason $reason
 * @property string|null $source
 * @property string|null $detail
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable(['organization_id', 'email', 'audience_id', 'reason', 'source', 'detail', 'created_by'])]
class Suppression extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
        ];
    }

    public function isGlobal(): bool
    {
        return $this->audience_id === null;
    }

    /**
     * @return BelongsTo<Audience, $this>
     */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }
}
