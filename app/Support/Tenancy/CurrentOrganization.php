<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use Closure;

/**
 * Holds the organization (tenant) of the current request or job.
 * Registered as a scoped singleton so it resets between requests/jobs.
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($organization?->id);
        }
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    /**
     * For code that only runs inside an authenticated panel request or a runAs() block.
     */
    public function getOrFail(): Organization
    {
        return $this->organization ?? throw new \RuntimeException('No hay una organización activa.');
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    /**
     * Run a callback in the context of the given organization (e.g. inside queued jobs).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Organization $organization, Closure $callback): mixed
    {
        $previous = $this->organization;
        $this->set($organization);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
