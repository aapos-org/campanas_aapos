<?php

namespace App\Enums;

enum AutomationStepType: string
{
    case SendEmail = 'send_email';
    case Wait = 'wait';
    case AddTag = 'add_tag';
    case RemoveTag = 'remove_tag';
    case Condition = 'condition';

    public function label(): string
    {
        return match ($this) {
            self::SendEmail => 'Enviar correo',
            self::Wait => 'Esperar',
            self::AddTag => 'Agregar etiqueta',
            self::RemoveTag => 'Quitar etiqueta',
            self::Condition => 'Condición',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }

    /** "1 día", "3 horas"… for wait steps. */
    public static function duration(int $amount, string $unit): string
    {
        [$one, $many] = match ($unit) {
            'minutes' => ['minuto', 'minutos'],
            'hours' => ['hora', 'horas'],
            default => ['día', 'días'],
        };

        return $amount.' '.($amount === 1 ? $one : $many);
    }
}
