<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Thin wrapper around activitylog for explicit audit events
 * (logins, sends, imports, connection changes...).
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?Model $causer = null,
        ?int $organizationId = null,
    ): ?AuditLog {
        $logger = activity('audit')->event($event)->withProperties($properties);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        if ($organizationId !== null) {
            $logger->tap(function (AuditLog $log) use ($organizationId): void {
                $log->organization_id = $organizationId;
            });
        }

        $log = $logger->log($description);

        return $log instanceof AuditLog ? $log : null;
    }
}
