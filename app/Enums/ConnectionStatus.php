<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Unverified = 'unverified';
    case Active = 'active';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Sin verificar',
            self::Active => 'Activa',
            self::Error => 'Con error',
        };
    }
}
