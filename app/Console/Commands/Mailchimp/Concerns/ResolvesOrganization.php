<?php

namespace App\Console\Commands\Mailchimp\Concerns;

use App\Models\Organization;
use RuntimeException;

trait ResolvesOrganization
{
    protected function organization(): Organization
    {
        $slug = $this->option('organization');

        $organization = is_string($slug) && $slug !== ''
            ? Organization::query()->where('slug', $slug)->first()
            : Organization::query()->oldest('id')->first();

        return $organization ?? throw new RuntimeException('No existe la organización. Crea una con php artisan app:create-admin.');
    }
}
