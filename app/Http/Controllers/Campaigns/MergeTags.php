<?php

namespace App\Http\Controllers\Campaigns;

use App\Models\MergeField;

/**
 * Personalization variables offered in the editors.
 */
class MergeTags
{
    /**
     * @return list<array{tag: string, label: string}>
     */
    public static function all(): array
    {
        $tags = [
            ['tag' => '{{ nombre | "amigo" }}', 'label' => 'Nombre (con texto si está vacío)'],
            ['tag' => '{{ apellido }}', 'label' => 'Apellido'],
            ['tag' => '{{ correo }}', 'label' => 'Correo electrónico'],
            ['tag' => '{{ telefono }}', 'label' => 'Teléfono'],
        ];

        foreach (MergeField::query()->orderBy('position')->get() as $field) {
            $tags[] = ['tag' => '{{ '.$field->key.' }}', 'label' => $field->label];
        }

        return [...$tags,
            ['tag' => '{{ enlace_baja }}', 'label' => 'Enlace para darse de baja'],
            ['tag' => '{{ preferencias }}', 'label' => 'Enlace a preferencias'],
            ['tag' => '{{ direccion_fisica }}', 'label' => 'Dirección física (obligatoria en el pie)'],
            ['tag' => '{{ nombre_organizacion }}', 'label' => 'Nombre de la organización'],
            ['tag' => '{{ recordatorio_permiso }}', 'label' => 'Recordatorio de permiso de la audiencia'],
        ];
    }
}
