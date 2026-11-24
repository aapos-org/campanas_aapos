<?php

namespace App\Services\Limits;

use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the organization's "contacts" limit when a contact is created (any source:
 * panel, import, form, API, migration). Scoped per request/job: the count is read once
 * and then tracked in memory, so bulk imports do not run one COUNT per row.
 */
class ContactLimit
{
    /** @var array<int, int> organization id => remaining contacts */
    private array $remaining = [];

    public function ensureCanCreate(int $organizationId): void
    {
        $organization = Organization::query()->find($organizationId);
        $limit = $organization?->limit('contacts');

        if ($organization === null || $limit === null) {
            return;
        }

        $this->remaining[$organizationId] ??= $limit - $this->count($organizationId);

        if ($this->remaining[$organizationId] <= 0) {
            throw ValidationException::withMessages([
                'email' => sprintf('Se alcanzó el límite de %s contactos de la organización. Un administrador puede ampliarlo en Administración → Organización.', number_format($limit, 0, '.', ',')),
            ]);
        }

        $this->remaining[$organizationId]--;
    }

    public function count(int $organizationId): int
    {
        return Contact::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->whereNull('deleted_at')->count();
    }
}
