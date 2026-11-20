<?php

use App\Enums\Permission;
use App\Enums\Role;

it('gives administrators every permission', function () {
    expect(Role::Admin->permissions())->toEqualCanonicalizing(Permission::cases());
});

it('does not let editors manage users, connections, settings or the audit log', function () {
    expect(Role::Editor->permissions())
        ->not->toContain(Permission::UsersManage)
        ->not->toContain(Permission::ConnectionsManage)
        ->not->toContain(Permission::SettingsManage)
        ->not->toContain(Permission::AuditView)
        ->not->toContain(Permission::SystemMonitor)
        ->toContain(Permission::CampaignsSend);
});

it('only gives read-only users view permissions', function () {
    foreach (Role::Viewer->permissions() as $permission) {
        expect($permission->value)->toEndWith('.view');
    }
});

it('labels every role in Spanish', function () {
    expect(array_column(Role::options(), 'label'))->toBe(['Administrador', 'Editor', 'Solo lectura']);
});
