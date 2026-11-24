<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    /** A/B test sample sent; waiting to pick the winner and send it to the rest. */
    case Testing = 'testing';
    /** Backing campaign of an automation email step (sent one by one by the automation). */
    case Automation = 'automation';
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
            self::Testing => 'Prueba A/B en curso',
            self::Automation => 'Automatización',
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
