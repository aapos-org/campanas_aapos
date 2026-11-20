<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * Bootstraps the first administrator on a fresh install (no public registration exists).
 * Prints a one-time link to set the password instead of asking for it on the console.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
        {email? : Email del administrador}
        {--name= : Nombre completo}
        {--organization= : Nombre de la organización (solo si aún no existe ninguna)}';

    protected $description = 'Crea un usuario administrador y muestra el enlace para definir su contraseña';

    public function handle(): int
    {
        $email = Str::lower(trim((string) ($this->argument('email') ?? text('Email del administrador', required: true))));
        $name = (string) ($this->option('name') ?? text('Nombre completo', required: true));

        $validator = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $organization = Organization::query()->oldest('id')->first();

        if ($organization === null) {
            $orgName = (string) ($this->option('organization') ?? text('Nombre de la organización', default: 'AAPOS', required: true));
            $organization = Organization::create([
                'name' => $orgName,
                'slug' => Str::slug($orgName),
            ]);
            $this->info("Organización creada: {$organization->name}");
        }

        $user = User::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => $email,
            'password' => Str::password(40),
            'email_verified_at' => now(),
        ]);

        setPermissionsTeamId($organization->id);
        $user->assignRole(Role::Admin->value);

        Audit::record('users.created', 'Administrador creado desde la consola', $user, organizationId: $organization->id);

        $token = Password::broker()->createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

        $this->info("Administrador creado: {$user->email}");
        $this->line('Abre este enlace para definir la contraseña (caduca en '.config('auth.passwords.users.expire').' minutos):');
        $this->line($url);
        $this->warn('Al iniciar sesión se le pedirá activar la autenticación en dos pasos.');

        return self::SUCCESS;
    }
}
