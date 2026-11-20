<?php

namespace Database\Factories;

use App\Enums\FieldType;
use App\Models\MergeField;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MergeField>
 */
class MergeFieldFactory extends Factory
{
    public function definition(): array
    {
        $label = fake()->unique()->word();

        return [
            'organization_id' => fn () => app(CurrentOrganization::class)->id() ?? Organization::factory(),
            'key' => Str::snake($label),
            'label' => ucfirst($label),
            'type' => FieldType::Text,
            'options' => [],
            'required' => false,
        ];
    }
}
