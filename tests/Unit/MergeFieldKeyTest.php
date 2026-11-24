<?php

use App\Models\MergeField;

it('normalizes labels into readable keys', function (string $label, string $key) {
    expect(MergeField::keyFor($label))->toBe($key);
})->with([
    'mayúsculas' => ['NÚMERO DE TELÉFONO', 'numero_de_telefono'],
    'signos' => ['¿Cómo supiste de nosotros?', 'como_supiste_de_nosotros'],
    'paréntesis' => ['Creado a las (UTC+0)', 'creado_a_las_utc_0'],
    'barra' => ['Organización / Empresa', 'organizacion_empresa'],
    'camelCase' => ['FechaNacimiento', 'fechanacimiento'],
    'acentos y ñ' => ['Año de afiliación', 'ano_de_afiliacion'],
    'números' => ['Zona 10', 'zona_10'],
]);

it('limits keys to 50 characters without a trailing underscore', function () {
    $key = MergeField::keyFor(str_repeat('palabra ', 20));

    expect(strlen($key))->toBeLessThanOrEqual(50)
        ->and($key)->not->toEndWith('_');
});

it('avoids reserved keys', function () {
    expect(MergeField::keyFor('Email'))->toBe('email_2')
        ->and(MergeField::keyFor('Teléfono'))->toBe('telefono')
        ->and(MergeField::keyFor('Phone'))->toBe('phone_2')
        ->and(MergeField::keyFor('Status', ['status_2']))->toBe('status_3');
});

it('adds a numeric suffix to duplicated keys', function () {
    expect(MergeField::keyFor('Ciudad', ['ciudad']))->toBe('ciudad_2')
        ->and(MergeField::keyFor('CIUDAD', ['ciudad', 'ciudad_2']))->toBe('ciudad_3')
        ->and(MergeField::keyFor(str_repeat('a', 60), [str_repeat('a', 50)]))->toBe(str_repeat('a', 48).'_2');
});

it('falls back when the label has no letters or digits', function () {
    expect(MergeField::keyFor('¿?', [], 'MMERGE7'))->toBe('mmerge7')
        ->and(MergeField::keyFor('***'))->toBe('campo');
});
