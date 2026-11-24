<?php

namespace App\Support\Api;

use App\Enums\Permission;

/**
 * Token abilities of the public API. A token can only receive abilities its owner's role
 * allows, and each request is checked against both the token and the owner's role.
 */
enum ApiAbility: string
{
    case AudiencesRead = 'audiences:read';
    case ContactsRead = 'contacts:read';
    case ContactsWrite = 'contacts:write';
    case SubscriptionsWrite = 'subscriptions:write';
    case TagsRead = 'tags:read';
    case CampaignsRead = 'campaigns:read';

    public function label(): string
    {
        return match ($this) {
            self::AudiencesRead => 'Leer audiencias',
            self::ContactsRead => 'Leer contactos',
            self::ContactsWrite => 'Crear y modificar contactos y sus etiquetas',
            self::SubscriptionsWrite => 'Suscribir y dar de baja',
            self::TagsRead => 'Leer etiquetas',
            self::CampaignsRead => 'Leer campañas y sus estadísticas',
        };
    }

    public function permission(): Permission
    {
        return match ($this) {
            self::AudiencesRead, self::ContactsRead, self::TagsRead => Permission::ContactsView,
            self::ContactsWrite, self::SubscriptionsWrite => Permission::ContactsManage,
            self::CampaignsRead => Permission::CampaignsView,
        };
    }

    /**
     * @return list<array{value: string, label: string, permission: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $a) => ['value' => $a->value, 'label' => $a->label(), 'permission' => $a->permission()->value], self::cases());
    }
}
