<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(CurrentOrganization::class)->id() ?? Organization::factory(),
            'name' => 'Boletín '.fake()->monthName(),
            'subject' => 'Novedades de {{ nombre | "nuestra comunidad" }}',
            'preheader' => 'Lo más importante del mes',
            'from_name' => 'AAPOS',
            'from_email' => 'boletin@example.org',
            'editor' => 'html',
            'html' => '<html><head><style>p{color:#333}</style></head><body><h1>Hola {{ nombre | "amigo" }}</h1><p>Texto</p><p><a href="https://example.org/noticia?x=1&y=2">Leer más</a> <a href="mailto:info@example.org">Escríbenos</a></p></body></html>',
            'status' => CampaignStatus::Draft,
        ];
    }
}
