<?php

namespace App\Enums;

enum EventType: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Open = 'open';
    case Click = 'click';
    case HardBounce = 'bounce_hard';
    case SoftBounce = 'bounce_soft';
    case Complaint = 'complaint';
    case Unsubscribe = 'unsubscribe';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Enviado',
            self::Delivered => 'Entregado',
            self::Open => 'Apertura',
            self::Click => 'Clic',
            self::HardBounce => 'Rebote duro',
            self::SoftBounce => 'Rebote suave',
            self::Complaint => 'Queja de spam',
            self::Unsubscribe => 'Baja',
            self::Failed => 'Error de envío',
        };
    }
}
