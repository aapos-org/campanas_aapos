<?php

namespace App\Enums;

/**
 * Status of a contact within an audience (audience_contact.status).
 */
enum ContactStatus: string
{
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
    case Cleaned = 'cleaned';
    case Pending = 'pending';
    case NonSubscribed = 'non_subscribed';

    public function label(): string
    {
        return match ($this) {
            self::Subscribed => 'Suscrito',
            self::Unsubscribed => 'Dado de baja',
            self::Cleaned => 'Rebotado (limpiado)',
            self::Pending => 'Pendiente de confirmar',
            self::NonSubscribed => 'No suscrito',
        };
    }

    /** Statuses that receive campaigns. */
    public function isSendable(): bool
    {
        return $this === self::Subscribed;
    }

    /**
     * Terminal statuses: never changed back to subscribed by imports or automatic processes.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Unsubscribed, self::Cleaned], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
