<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: creates/syncs the global roles (organization_id = null) and their permissions.
 * Safe to run on every deploy.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId(null);

        foreach (Role::cases() as $role) {
            $model = RoleModel::query()
                ->whereNull(config('permission.column_names.team_foreign_key'))
                ->where('name', $role->value)
                ->where('guard_name', 'web')
                ->first() ?? RoleModel::create(['name' => $role->value, 'guard_name' => 'web']);

            $model->syncPermissions(array_map(fn (Permission $p) => $p->value, $role->permissions()));
        }

        setPermissionsTeamId($previousTeam);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
