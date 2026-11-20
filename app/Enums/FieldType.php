<?php

namespace App\Enums;

enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Birthday = 'birthday';
    case Select = 'select';
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto',
            self::Number => 'Número',
            self::Date => 'Fecha',
            self::Birthday => 'Cumpleaños (día y mes)',
            self::Select => 'Lista de opciones',
            self::Phone => 'Teléfono',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
