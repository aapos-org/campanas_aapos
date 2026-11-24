<?php

namespace App\Enums;

enum AutomationTrigger: string
{
    case Subscribed = 'subscribed';
    case TagAdded = 'tag_added';
    case DateField = 'date_field';

    public function label(): string
    {
        return match ($this) {
            self::Subscribed => 'Se suscribe a la audiencia',
            self::TagAdded => 'Se le agrega una etiqueta',
            self::DateField => 'Fecha de un campo (p. ej. cumpleaños)',
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
