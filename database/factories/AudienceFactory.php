<?php

namespace Database\Factories;

use App\Models\Audience;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Audience>
 */
class AudienceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(CurrentOrganization::class)->id() ?? Organization::factory(),
            'name' => 'Audiencia '.fake()->unique()->word(),
            'description' => fake()->sentence(),
            'default_from_name' => 'AAPOS',
            'default_from_email' => 'boletin@example.org',
            'permission_reminder' => 'Recibes este correo porque te suscribiste en nuestro sitio.',
            'double_opt_in' => true,
        ];
    }
}
