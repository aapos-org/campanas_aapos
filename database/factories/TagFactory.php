<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Tag;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(CurrentOrganization::class)->id() ?? Organization::factory(),
            'name' => fake()->unique()->word(),
        ];
    }
}
