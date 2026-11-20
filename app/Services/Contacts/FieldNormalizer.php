<?php

namespace App\Services\Contacts;

use App\Enums\FieldType;
use App\Models\MergeField;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Validates and normalizes custom field values (from forms and imports).
 * Returns [normalizedValue, errorMessage|null]. Empty input normalizes to null.
 */
class FieldNormalizer
{
    /** Region used for phone numbers written without international prefix. */
    public const string DEFAULT_PHONE_REGION = 'GT';

    /**
     * @return array{0: string|int|float|null, 1: string|null}
     */
    public function normalize(MergeField $field, mixed $value): array
    {
        return $this->normalizeType($field->type, $value, $field->options, $field->label);
    }

    /**
     * @param  list<string>  $options
     * @return array{0: string|int|float|null, 1: string|null}
     */
    public function normalizeType(FieldType $type, mixed $value, array $options = [], string $label = 'campo'): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return [null, null];
        }

        $raw = is_scalar($value) ? trim((string) $value) : '';

        return match ($type) {
            FieldType::Text => [mb_substr($raw, 0, 1000), null],
            FieldType::Number => $this->number($raw, $label),
            FieldType::Date => $this->date($raw, $label),
            FieldType::Birthday => $this->birthday($raw, $label),
            FieldType::Select => $this->select($raw, $options, $label),
            FieldType::Phone => $this->phone($raw, $label),
        };
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    public function phone(string $raw, string $label = 'teléfono'): array
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($raw, self::DEFAULT_PHONE_REGION);
        } catch (NumberParseException) {
            return [null, "El {$label} «{$raw}» no es un teléfono válido."];
        }

        if (! $util->isValidNumber($number)) {
            return [null, "El {$label} «{$raw}» no es un teléfono válido."];
        }

        return [$util->format($number, PhoneNumberFormat::E164), null];
    }

    /**
     * @return array{0: int|float|null, 1: string|null}
     */
    private function number(string $raw, string $label): array
    {
        $normalized = str_replace([' ', ','], ['', '.'], $raw);

        if (! is_numeric($normalized)) {
            return [null, "El campo {$label} debe ser un número («{$raw}»)."];
        }

        return [str_contains($normalized, '.') ? (float) $normalized : (int) $normalized, null];
    }

    /**
     * Accepts ISO (2026-03-31), d/m/Y and d-m-Y (Guatemalan convention).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function date(string $raw, string $label): array
    {
        // Excel/ISO timestamps such as "2026-03-31 00:00:00" or "2026-03-31T00:00:00Z".
        $raw = (string) preg_replace('/[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', '', $raw);

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $raw, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})$/', $raw, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $year = $year < 100 ? ($year > (int) date('y') + 1 ? 1900 + $year : 2000 + $year) : $year;
        } else {
            return [null, "El campo {$label} debe ser una fecha válida (dd/mm/aaaa), recibido «{$raw}»."];
        }

        if (! checkdate($month, $day, $year)) {
            return [null, "El campo {$label} tiene una fecha imposible («{$raw}»)."];
        }

        return [sprintf('%04d-%02d-%02d', $year, $month, $day), null];
    }

    /**
     * Stored as MM-DD. Accepts dd/mm, dd-mm, MM-DD (Mailchimp) and full dates.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function birthday(string $raw, string $label): array
    {
        if (preg_match('/^(\d{1,2})[\/](\d{1,2})$/', $raw, $m)) {
            [$day, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d{2})-(\d{2})$/', $raw, $m)) {
            [$month, $day] = [(int) $m[1], (int) $m[2]];
        } else {
            [$date] = $this->date($raw, $label);

            if ($date === null) {
                return [null, "El campo {$label} debe tener día y mes (dd/mm), recibido «{$raw}»."];
            }

            [$month, $day] = [(int) substr($date, 5, 2), (int) substr($date, 8, 2)];
        }

        if (! checkdate($month, $day, 2000)) {
            return [null, "El campo {$label} tiene una fecha imposible («{$raw}»)."];
        }

        return [sprintf('%02d-%02d', $month, $day), null];
    }

    /**
     * @param  list<string>  $options
     * @return array{0: string|null, 1: string|null}
     */
    private function select(string $raw, array $options, string $label): array
    {
        foreach ($options as $option) {
            if (mb_strtolower($option) === mb_strtolower($raw)) {
                return [$option, null];
            }
        }

        return [null, "«{$raw}» no es una opción válida de {$label}."];
    }
}
