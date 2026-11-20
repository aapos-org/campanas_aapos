<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Editor => 'Editor',
            self::Viewer => 'Solo lectura',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Acceso total: usuarios, conexiones de envío, configuración y auditoría. Requiere 2FA.',
            self::Editor => 'Gestiona contactos, plantillas y campañas, y puede enviarlas.',
            self::Viewer => 'Puede consultar contactos, campañas y reportes sin modificarlos.',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Editor => [
                Permission::ContactsView,
                Permission::ContactsManage,
                Permission::CampaignsView,
                Permission::CampaignsManage,
                Permission::CampaignsSend,
                Permission::ReportsView,
            ],
            self::Viewer => [
                Permission::ContactsView,
                Permission::CampaignsView,
                Permission::ReportsView,
            ],
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
        ], self::cases());
    }
}
