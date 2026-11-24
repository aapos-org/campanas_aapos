<?php

use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\MergeField;
use App\Models\Organization;

it('creates fields with normalized keys unique within the organization', function () {
    $admin = userWithRole(Role::Admin);
    actAsOrganization($admin->organization);

    // Another organization's key does not count.
    MergeField::factory()->for(Organization::factory())->create(['key' => 'numero_de_telefono']);

    $this->actingAs($admin)->post(route('fields.store'), ['label' => 'NÚMERO DE TELÉFONO', 'type' => FieldType::Text->value])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('fields.store'), ['label' => 'Número de teléfono', 'type' => FieldType::Text->value])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('fields.store'), ['label' => 'Email', 'type' => FieldType::Text->value])->assertSessionHasNoErrors();

    expect(MergeField::query()->orderBy('id')->pluck('key')->all())->toBe(['numero_de_telefono', 'numero_de_telefono_2', 'email_2']);
});
