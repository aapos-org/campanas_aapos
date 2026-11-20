<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;

it('rejects inactive users even with the right password', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('normalizes the email on login', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->post(route('login.store'), ['email' => '  ANA@Example.com ', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('locks the account for 15 minutes after 10 failures from different IPs', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
    }

    // Correct password from a brand-new IP is still blocked.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.99'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests();

    $this->assertGuest();

    // After the window expires the user can log in again.
    $this->travel(16)->minutes();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.99'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('records successful and failed logins in the audit log without credentials', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-secret']);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $failed = AuditLog::query()->withoutGlobalScopes()->where('event', 'auth.failed')->sole();
    expect($failed->properties->toArray())->toBe(['email' => $user->email])
        ->and(json_encode($failed->toArray()))->not->toContain('wrong-secret');

    $login = AuditLog::query()->withoutGlobalScopes()->where('event', 'auth.login')->sole();
    expect($login->organization_id)->toBe($user->organization_id)
        ->and($login->causer_id)->toBe($user->id)
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('records exactly one audit entry per authentication event', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    expect(AuditLog::query()->withoutGlobalScopes()->where('event', 'auth.login')->count())->toBe(1)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('event', 'auth.logout')->count())->toBe(1);
});

it('forces administrators without 2FA to the security page', function () {
    $admin = User::factory()->role(Role::Admin)->create(); // no 2FA

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertRedirect(route('security.edit'));
});

it('lets administrators with 2FA use the panel', function () {
    $admin = userWithRole(Role::Admin);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
});

it('does not force 2FA on editors', function () {
    $editor = userWithRole(Role::Editor);

    $this->actingAs($editor)->get(route('dashboard'))->assertOk();
});

it('logs out a user deactivated while signed in', function () {
    $user = userWithRole(Role::Editor);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->update(['is_active' => false]);

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('does not ask for the password again right after logging in', function () {
    $admin = User::factory()->role(Role::Admin)->create(); // no 2FA yet

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect();

    // Sent to security settings, which open without a second password prompt.
    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->get(route('security.edit'))->assertOk();
});
