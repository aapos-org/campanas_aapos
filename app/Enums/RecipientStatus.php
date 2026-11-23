<?php

namespace App\Enums;

enum RecipientStatus: string
{
    case Pending = 'pending';
    /** Claimed by a worker; a crash leaves it here and it is never re-sent automatically. */
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Sending => 'Enviando',
            self::Sent => 'Enviado',
            self::Failed => 'Falló',
            self::Skipped => 'Omitido',
        };
    }
}
