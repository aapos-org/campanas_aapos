<?php

use App\Enums\FieldType;
use App\Services\Contacts\FieldNormalizer;

it('normalizes values by type', function (FieldType $type, mixed $input, mixed $expected, array $options = []) {
    [$value, $error] = (new FieldNormalizer)->normalizeType($type, $input, $options);

    expect($error)->toBeNull()->and($value)->toBe($expected);
})->with([
    'gt phone local' => [FieldType::Phone, '5555-1234', '+50255551234'],
    'intl phone' => [FieldType::Phone, '+1 (650) 253-0000', '+16502530000'],
    'date dmy' => [FieldType::Date, '5/3/2026', '2026-03-05'],
    'date iso' => [FieldType::Date, '2026-03-05', '2026-03-05'],
    'date excel' => [FieldType::Date, '2026-03-05 00:00:00', '2026-03-05'],
    'birthday dd/mm' => [FieldType::Birthday, '31/12', '12-31'],
    'birthday mailchimp' => [FieldType::Birthday, '12-31', '12-31'],
    'birthday full date' => [FieldType::Birthday, '29/02/2000', '02-29'],
    'number comma' => [FieldType::Number, '1234,5', 1234.5],
    'select case' => [FieldType::Select, 'socio', 'Socio', ['Socio', 'Invitado']],
    'empty' => [FieldType::Text, '  ', null],
]);

it('rejects invalid values with a Spanish message', function (FieldType $type, string $input, array $options = []) {
    [$value, $error] = (new FieldNormalizer)->normalizeType($type, $input, $options, 'dato');

    expect($value)->toBeNull()->and($error)->toBeString()->not->toBeEmpty();
})->with([
    [FieldType::Phone, '123'],
    [FieldType::Date, '31/02/2026'],
    [FieldType::Date, 'ayer'],
    [FieldType::Birthday, '31/04'],
    [FieldType::Number, 'doce'],
    [FieldType::Select, 'Otro', ['Socio']],
]);
