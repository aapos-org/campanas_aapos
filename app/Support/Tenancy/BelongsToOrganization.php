<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the current organization and fills `organization_id` on create.
 *
 * When no organization is set (console commands, login), the scope is not applied:
 * code running outside a request must call CurrentOrganization::runAs().
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', app(CurrentOrganization::class)->id());
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
