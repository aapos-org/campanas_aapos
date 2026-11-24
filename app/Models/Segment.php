<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dynamic segment: conditions evaluated at query time (never stored SQL).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $audience_id
 * @property string $name
 * @property string $match
 * @property list<array{field: string, op: string, value: mixed}> $conditions
 * @property string|null $mailchimp_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Audience|null $audience
 */
#[Fillable(['organization_id', 'audience_id', 'name', 'match', 'conditions', 'mailchimp_id', 'created_by'])]
class Segment extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return ['conditions' => 'array'];
    }

    /**
     * @return BelongsTo<Audience, $this>
     */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }
}
