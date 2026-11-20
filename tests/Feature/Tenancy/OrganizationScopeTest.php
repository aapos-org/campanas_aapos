<?php

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

it('only returns records of the current organization', function () {
    $mine = Organization::factory()->create();
    $other = Organization::factory()->create();
    User::factory()->for($mine)->count(2)->create();
    User::factory()->for($other)->count(3)->create();

    actAsOrganization($mine);

    expect(User::query()->count())->toBe(2)
        ->and(User::query()->pluck('organization_id')->unique()->all())->toBe([$mine->id]);
});

it('fills organization_id from the current organization on create', function () {
    $org = Organization::factory()->create();
    actAsOrganization($org);

    $user = User::factory()->create(['organization_id' => null]);

    expect($user->organization_id)->toBe($org->id);
});

it('runs code as another organization and restores the previous one', function () {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $current = app(CurrentOrganization::class);
    $current->set($a);

    $inside = $current->runAs($b, fn () => $current->id());

    expect($inside)->toBe($b->id)->and($current->id())->toBe($a->id);
});

it('returns 404 when an admin edits a user from another organization', function () {
    $admin = userWithRole(Role::Admin);
    $foreign = User::factory()->for(Organization::factory())->create();

    $this->actingAs($admin)
        ->get(route('admin.users.edit', $foreign))
        ->assertNotFound();
});

it('scopes role assignments per organization', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = User::factory()->for($orgA)->role(Role::Admin)->create();

    setPermissionsTeamId($orgA->id);
    expect($user->fresh()->hasRole(Role::Admin->value))->toBeTrue();

    setPermissionsTeamId($orgB->id);
    expect($user->fresh()->hasRole(Role::Admin->value))->toBeFalse();
});
