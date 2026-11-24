<?php

namespace App\Services\Segments;

use App\Enums\ContactStatus;
use App\Enums\FieldType;
use App\Models\Campaign;
use App\Models\MergeField;
use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Catalog of segment conditions and their validation. A condition is
 * {field, op, value}; every field and operator is whitelisted here and the value is
 * normalized to a scalar, so SegmentQuery only ever binds parameters.
 */
class SegmentRules
{
    public const int MAX_CONDITIONS = 20;

    /** @var array<string, string> */
    public const array OPERATORS = [
        'is' => 'es',
        'is_not' => 'no es',
        'contains' => 'contiene',
        'not_contains' => 'no contiene',
        'ends_with' => 'termina en',
        'blank' => 'está vacío',
        'not_blank' => 'no está vacío',
        'gt' => 'mayor que',
        'lt' => 'menor que',
        'before' => 'antes de',
        'after' => 'después de',
        'within_days' => 'en los últimos (días)',
        'not_within_days' => 'no en los últimos (días)',
        'month_is' => 'el mes es',
        'has' => 'tiene',
        'not_has' => 'no tiene',
        'opened' => 'abrió',
        'not_opened' => 'no abrió',
        'clicked' => 'hizo clic en',
        'not_clicked' => 'no hizo clic en',
        'received' => 'recibió',
        'not_received' => 'no recibió',
        'opened_within_days' => 'abrió alguna campaña en los últimos (días)',
        'not_opened_within_days' => 'no abrió ninguna campaña en los últimos (días)',
        'clicked_within_days' => 'hizo clic en alguna campaña en los últimos (días)',
        'not_clicked_within_days' => 'no hizo clic en ninguna campaña en los últimos (días)',
        'url_contains' => 'hizo clic en un enlace que contiene',
    ];

    /** Operators that take no value. */
    private const array NO_VALUE = ['blank', 'not_blank'];

    private const array TEXT_OPS = ['is', 'is_not', 'contains', 'not_contains', 'blank', 'not_blank'];

    /**
     * Fields available for an organization, with their allowed operators and value type
     * (for the builder UI and for validation).
     *
     * @param  Collection<int, MergeField>|null  $mergeFields
     * @return list<array{key: string, label: string, group: string, ops: list<string>, input: string, options?: list<array{value: string, label: string}>}>
     */
    public function catalog(?Collection $mergeFields = null): array
    {
        $mergeFields ??= MergeField::query()->orderBy('position')->get();

        $fields = [
            ['key' => 'email', 'label' => 'Correo', 'group' => 'Contacto', 'ops' => ['is', 'contains', 'not_contains', 'ends_with'], 'input' => 'text'],
            ['key' => 'first_name', 'label' => 'Nombre', 'group' => 'Contacto', 'ops' => self::TEXT_OPS, 'input' => 'text'],
            ['key' => 'last_name', 'label' => 'Apellido', 'group' => 'Contacto', 'ops' => self::TEXT_OPS, 'input' => 'text'],
            ['key' => 'phone', 'label' => 'Teléfono', 'group' => 'Contacto', 'ops' => self::TEXT_OPS, 'input' => 'text'],
            ['key' => 'status', 'label' => 'Estado en la audiencia', 'group' => 'Suscripción', 'ops' => ['is', 'is_not'], 'input' => 'select',
                'options' => array_map(fn (ContactStatus $s) => ['value' => $s->value, 'label' => $s->label()], ContactStatus::cases())],
            ['key' => 'subscribed_at', 'label' => 'Fecha de alta', 'group' => 'Suscripción', 'ops' => ['before', 'after', 'within_days', 'not_within_days'], 'input' => 'date'],
            ['key' => 'tag', 'label' => 'Etiqueta', 'group' => 'Suscripción', 'ops' => ['has', 'not_has'], 'input' => 'tag'],
            ['key' => 'campaign', 'label' => 'Campaña', 'group' => 'Actividad', 'ops' => ['received', 'not_received', 'opened', 'not_opened', 'clicked', 'not_clicked'], 'input' => 'campaign'],
            ['key' => 'activity', 'label' => 'Actividad reciente', 'group' => 'Actividad', 'ops' => ['opened_within_days', 'not_opened_within_days', 'clicked_within_days', 'not_clicked_within_days'], 'input' => 'number'],
            ['key' => 'link', 'label' => 'Enlace', 'group' => 'Actividad', 'ops' => ['url_contains'], 'input' => 'text'],
        ];

        foreach ($mergeFields as $field) {
            [$ops, $input] = match ($field->type) {
                FieldType::Number => [['is', 'gt', 'lt', 'blank', 'not_blank'], 'number'],
                FieldType::Date => [['before', 'after', 'within_days', 'blank', 'not_blank'], 'date'],
                FieldType::Birthday => [['month_is', 'blank', 'not_blank'], 'month'],
                FieldType::Select => [['is', 'is_not', 'blank', 'not_blank'], 'select'],
                default => [self::TEXT_OPS, 'text'],
            };

            $entry = ['key' => 'field:'.$field->key, 'label' => $field->label, 'group' => 'Campos personalizados', 'ops' => $ops, 'input' => $input];

            if ($field->type === FieldType::Select) {
                $entry['options'] = array_values(array_map(fn ($o) => ['value' => (string) $o, 'label' => (string) $o], (array) $field->options));
            }

            $fields[] = $entry;
        }

        return $fields;
    }

    /**
     * Validates and normalizes conditions. Throws a Spanish ValidationException on the
     * first problem (under the key "conditions").
     *
     * @return list<array{field: string, op: string, value: string|int|null}>
     */
    public function validate(mixed $conditions): array
    {
        if (! is_array($conditions) || $conditions === []) {
            throw ValidationException::withMessages(['conditions' => 'Agrega al menos una condición.']);
        }

        if (count($conditions) > self::MAX_CONDITIONS) {
            throw ValidationException::withMessages(['conditions' => 'Máximo '.self::MAX_CONDITIONS.' condiciones por segmento.']);
        }

        $catalog = collect($this->catalog())->keyBy('key');
        $normalized = [];

        foreach (array_values($conditions) as $i => $condition) {
            $n = $i + 1;
            $field = is_array($condition) ? (string) ($condition['field'] ?? '') : '';
            $op = is_array($condition) ? (string) ($condition['op'] ?? '') : '';
            $raw = is_array($condition) ? ($condition['value'] ?? null) : null;
            $definition = $catalog->get($field);

            if ($definition === null) {
                throw ValidationException::withMessages(['conditions' => "Condición {$n}: el campo no existe."]);
            }

            if (! in_array($op, $definition['ops'], true)) {
                throw ValidationException::withMessages(['conditions' => "Condición {$n}: la comparación no es válida para «{$definition['label']}»."]);
            }

            $normalized[] = ['field' => $field, 'op' => $op, 'value' => $this->value($definition, $op, $raw, $n)];
        }

        return $normalized;
    }

    /**
     * @param  array{key: string, label: string, input: string, options?: list<array{value: string, label: string}>}  $definition
     */
    private function value(array $definition, string $op, mixed $raw, int $n): string|int|null
    {
        if (in_array($op, self::NO_VALUE, true)) {
            return null;
        }

        $fail = fn (string $message) => throw ValidationException::withMessages(['conditions' => "Condición {$n} ({$definition['label']}): {$message}"]);
        $text = is_scalar($raw) ? trim((string) $raw) : '';

        if ($text === '') {
            $fail('falta el valor.');
        }

        if (in_array($op, ['within_days', 'not_within_days', 'opened_within_days', 'not_opened_within_days', 'clicked_within_days', 'not_clicked_within_days'], true)) {
            return ctype_digit($text) && (int) $text >= 1 && (int) $text <= 3650 ? (int) $text : $fail('indica un número de días entre 1 y 3650.');
        }

        return match ($definition['input']) {
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) && checkdate((int) substr($text, 5, 2), (int) substr($text, 8, 2), (int) substr($text, 0, 4))
                ? $text : $fail('la fecha no es válida.'),
            'month' => ctype_digit($text) && (int) $text >= 1 && (int) $text <= 12 ? (int) $text : $fail('el mes debe estar entre 1 y 12.'),
            'number' => is_numeric($text) ? $text : $fail('debe ser un número.'),
            'select' => in_array($text, array_column($definition['options'] ?? [], 'value'), true) ? $text : $fail('elige una opción de la lista.'),
            'tag' => ctype_digit($text) && Tag::query()->whereKey((int) $text)->exists() ? (int) $text : $fail('la etiqueta no existe.'),
            'campaign' => ctype_digit($text) && Campaign::query()->whereKey((int) $text)->exists() ? (int) $text : $fail('la campaña no existe.'),
            default => mb_strlen($text) <= 255 ? $text : $fail('el texto es demasiado largo.'),
        };
    }

    /**
     * Human-readable summary ("Etiqueta tiene «Voluntario» y …").
     *
     * @param  list<array{field: string, op: string, value: mixed}>  $conditions
     */
    public function describe(string $match, array $conditions): string
    {
        $catalog = collect($this->catalog())->keyBy('key');
        $tags = Tag::query()->pluck('name', 'id');
        $campaigns = Campaign::query()->whereIn('id', collect($conditions)->where('field', 'campaign')->pluck('value'))->pluck('name', 'id');

        $parts = array_map(function (array $c) use ($catalog, $tags, $campaigns) {
            $definition = $catalog->get($c['field']);
            $value = match ($c['field']) {
                'tag' => $tags[$c['value']] ?? '¿?',
                'campaign' => $campaigns[$c['value']] ?? '¿?',
                'status' => ContactStatus::tryFrom((string) $c['value'])?->label() ?? $c['value'],
                default => $c['value'],
            };

            return trim(($definition['label'] ?? $c['field']).' '.(self::OPERATORS[$c['op']] ?? $c['op']).($value !== null ? ' «'.$value.'»' : ''));
        }, $conditions);

        return implode($match === 'any' ? ' o ' : ' y ', $parts);
    }
}
