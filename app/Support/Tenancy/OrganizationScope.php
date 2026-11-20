<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class OrganizationScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = app(CurrentOrganization::class)->id();

        if ($organizationId !== null) {
            $builder->where($model->qualifyColumn('organization_id'), $organizationId);
        }
    }
}
