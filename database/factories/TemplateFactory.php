<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Template;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(CurrentOrganization::class)->id() ?? Organization::factory(),
            'name' => 'Plantilla '.fake()->word(),
            'editor' => 'html',
            'html' => '<html><body><h1>Hola {{ nombre }}</h1><p><a href="https://example.org/noticia">Leer</a></p></body></html>',
        ];
    }
}
