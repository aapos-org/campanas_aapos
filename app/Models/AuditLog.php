<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit trail entry. Extends the activitylog model to live in `audit_logs`
 * and to record organization, IP and user agent.
 *
 * @property int|null $organization_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class AuditLog extends Activity
{
    use BelongsToOrganization;

    protected $table = 'audit_logs';

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            if (! app()->runningInConsole() || app()->runningUnitTests()) {
                $request = request();
                $log->ip_address ??= $request->ip();
                $log->user_agent ??= mb_substr((string) $request->userAgent(), 0, 500) ?: null;
            }
        });
    }
}
