<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(CurrentOrganization::class)->id() ?? Organization::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Assign a role (scoped to the user's organization) after creation.
     */
    public function role(Role $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $previous = getPermissionsTeamId();
            setPermissionsTeamId($user->organization_id);
            $user->assignRole($role->value);
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        });
    }

    public function admin(): static
    {
        return $this->role(Role::Admin)->withTwoFactor();
    }
}
