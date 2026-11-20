<?php

namespace App\Enums;

/**
 * Application permissions. New modules add their cases here and to Role::permissions();
 * `php artisan db:seed --class=RolesAndPermissionsSeeder` syncs them.
 */
enum Permission: string
{
    case UsersManage = 'users.manage';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
    case ConnectionsManage = 'connections.manage';
    case SystemMonitor = 'system.monitor';
    case ContactsView = 'contacts.view';
    case ContactsManage = 'contacts.manage';
    case CampaignsView = 'campaigns.view';
    case CampaignsManage = 'campaigns.manage';
    case CampaignsSend = 'campaigns.send';
    case ReportsView = 'reports.view';
}
