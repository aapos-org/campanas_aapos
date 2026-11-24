<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case ContactSubscribed = 'contact.subscribed';
    case ContactUnsubscribed = 'contact.unsubscribed';
    case EmailBounced = 'email.bounced';
    case EmailClicked = 'email.clicked';
    case CampaignSent = 'campaign.sent';

    public function label(): string
    {
        return match ($this) {
            self::ContactSubscribed => 'Suscripción',
            self::ContactUnsubscribed => 'Baja',
            self::EmailBounced => 'Rebote',
            self::EmailClicked => 'Clic en un enlace',
            self::CampaignSent => 'Campaña enviada',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
