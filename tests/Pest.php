<?php

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Creates a user with the given role inside an organization.
 * Admins get confirmed 2FA by default (they cannot use the panel without it).
 */
function userWithRole(Role $role, ?Organization $organization = null): User
{
    $organization ??= Organization::factory()->create();

    $factory = User::factory()->for($organization)->role($role);

    if ($role === Role::Admin) {
        $factory = $factory->withTwoFactor();
    }

    return $factory->create();
}

/**
 * Sets the current tenant, as the web middleware would.
 */
function actAsOrganization(Organization $organization): void
{
    app(CurrentOrganization::class)->set($organization);
}
