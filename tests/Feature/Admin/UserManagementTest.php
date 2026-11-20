<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    actAsOrganization($this->admin->organization);
});

it('denies user management to editors and viewers', function (Role $role) {
    $user = userWithRole($role, $this->admin->organization);

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.users.store'), [])->assertForbidden();
    $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();
})->with([Role::Editor, Role::Viewer]);

it('lists users of the organization', function () {
    User::factory()->for($this->admin->organization)->create(['name' => 'Beatriz Pérez']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['search' => 'beatriz']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/Index')
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Beatriz Pérez'));
});

it('creates a user with a role, sends an invitation and audits it', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'name' => 'Carlos Ruiz',
            'email' => ' Carlos@Example.com ',
            'role' => Role::Editor->value,
        ])
        ->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'carlos@example.com')->sole();

    expect($user->organization_id)->toBe($this->admin->organization_id)
        ->and($user->hasRole(Role::Editor->value))->toBeTrue();

    Notification::assertSentTo($user, UserInvitation::class);

    expect(AuditLog::query()->where('event', 'users.created')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('validates the user form', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), ['name' => '', 'email' => 'no-es-email', 'role' => 'superuser'])
        ->assertSessionHasErrors(['name', 'email', 'role']);
});

it('changes the role and audits the change', function () {
    $user = userWithRole(Role::Viewer, $this->admin->organization);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => Role::Editor->value,
            'is_active' => true,
        ])
        ->assertRedirect(route('admin.users.index'));

    expect($user->fresh()->hasRole(Role::Editor->value))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'users.role_changed')->exists())->toBeTrue();
});

it('prevents an admin from demoting or deactivating themselves', function () {
    userWithRole(Role::Admin, $this->admin->organization); // another admin exists

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->admin), [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'role' => Role::Viewer->value,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('role');

    expect($this->admin->fresh()->isAdmin())->toBeTrue();
});

it('allows deactivating another administrator while one remains active', function () {
    $otherAdmin = userWithRole(Role::Admin, $this->admin->organization);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $otherAdmin), [
            'name' => $otherAdmin->name,
            'email' => $otherAdmin->email,
            'role' => Role::Admin->value,
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($otherAdmin->fresh()->is_active)->toBeFalse()
        ->and(User::query()->role(Role::Admin->value)->where('is_active', true)->count())->toBe(1);
});

it('accepts a valid invitation link and rejects tampered ones', function () {
    $user = User::factory()->for($this->admin->organization)->create();

    $valid = URL::temporarySignedRoute('invitation.accept', now()->addHour(), ['user' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($valid)->assertRedirectContains('/reset-password/');

    $wrongHash = URL::temporarySignedRoute('invitation.accept', now()->addHour(), ['user' => $user->id, 'hash' => sha1('otro@example.com')]);
    $this->get($wrongHash)->assertForbidden();

    $expired = URL::temporarySignedRoute('invitation.accept', now()->subMinute(), ['user' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($expired)->assertForbidden();
});

it('shows the audit log to administrators', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/audit/Index'));
});
