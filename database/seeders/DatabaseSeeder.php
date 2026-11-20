<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds roles everywhere, and demo data only outside production.
     *
     * Local demo accounts (password: "password"):
     *  - admin@campanas.test  (Administrador, must enable 2FA on first login)
     *  - editor@campanas.test (Editor)
     *  - lector@campanas.test (Solo lectura)
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->isProduction()) {
            return;
        }

        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'aapos'],
            [
                'name' => 'AAPOS',
                'timezone' => 'America/Guatemala',
                'locale' => 'es',
                'physical_address' => 'Dirección de ejemplo, Ciudad de Guatemala, Guatemala',
            ],
        );

        $accounts = [
            ['admin@campanas.test', 'Administradora Demo', Role::Admin],
            ['editor@campanas.test', 'Editor Demo', Role::Editor],
            ['lector@campanas.test', 'Lector Demo', Role::Viewer],
        ];

        setPermissionsTeamId($organization->id);

        foreach ($accounts as [$email, $name, $role]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'organization_id' => $organization->id,
                    'name' => $name,
                    'password' => 'password',
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$role->value]);
        }
    }
}
