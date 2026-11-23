<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Paused = 'paused';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Scheduled => 'Programada',
            self::Sending => 'Enviando',
            self::Paused => 'En pausa',
            self::Sent => 'Enviada',
            self::Cancelled => 'Cancelada',
            self::Failed => 'Falló',
        };
    }

    /** Content and settings can only be edited before sending. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Sent, self::Cancelled, self::Failed], true);
    }
}
