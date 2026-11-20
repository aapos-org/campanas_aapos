<?php

namespace App\Enums;

enum SuppressionReason: string
{
    case Unsubscribed = 'unsubscribed';
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case Cleaned = 'cleaned';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Unsubscribed => 'Se dio de baja',
            self::HardBounce => 'Rebote duro',
            self::Complaint => 'Queja de spam',
            self::Cleaned => 'Limpiado (rebotes)',
            self::Manual => 'Agregado manualmente',
        };
    }

    /** Bounces and complaints always apply to every audience. */
    public function isAlwaysGlobal(): bool
    {
        return in_array($this, [self::HardBounce, self::Complaint, self::Cleaned], true);
    }

    /** Only manual suppressions can be lifted by an administrator. */
    public function isRemovable(): bool
    {
        return $this === self::Manual;
    }
}
