<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailConnection;
use App\Models\User;
use App\Services\Limits\ContactLimit;
use App\Services\Limits\MonthlySendQuota;
use App\Services\Sending\DailySendQuota;
use App\Support\Audit;
use App\Support\Tenancy\CurrentOrganization;
use DateTimeZone;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization profile (name, physical address, default sender, timezone) and usage limits.
 * Billing is not implemented: limits are set by an administrator (see docs/MULTITENANT.md).
 */
class OrganizationController extends Controller
{
    public const array LIMIT_KEYS = ['contacts', 'monthly_sends', 'daily_sends_per_connection'];

    public function __construct(private readonly CurrentOrganization $current) {}

    public function edit(): Response
    {
        $organization = $this->current->getOrFail();

        return Inertia::render('admin/organization/Edit', [
            'organization' => $organization->only(['name', 'physical_address', 'default_from_name', 'default_from_email', 'timezone']) + [
                'limits' => collect(self::LIMIT_KEYS)->mapWithKeys(fn (string $k) => [$k => $organization->limit($k)]),
            ],
            'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::AMERICA | DateTimeZone::EUROPE | DateTimeZone::UTC),
        ]);
    }

    public function update(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $organization = $this->current->getOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'physical_address' => ['nullable', 'string', 'max:500'],
            'default_from_name' => ['nullable', 'string', 'max:100'],
            'default_from_email' => ['nullable', 'email:rfc', 'max:255'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'limits' => ['array'],
            'limits.contacts' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'limits.monthly_sends' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'limits.daily_sends_per_connection' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ], [], [
            'name' => 'nombre', 'physical_address' => 'dirección física', 'default_from_name' => 'nombre del remitente',
            'default_from_email' => 'correo del remitente', 'timezone' => 'zona horaria', 'limits.contacts' => 'límite de contactos',
            'limits.monthly_sends' => 'límite de envíos por mes', 'limits.daily_sends_per_connection' => 'límite diario por conexión',
        ]);

        $limits = collect(self::LIMIT_KEYS)->mapWithKeys(fn (string $k) => [$k => isset($data['limits'][$k]) ? (int) $data['limits'][$k] : null])->all();
        $before = $organization->only(['name', 'physical_address', 'timezone', 'limits']);

        unset($data['limits']);
        $organization->update($data + ['limits' => $limits]);

        Audit::record('organization.updated', 'Datos de la organización modificados', $organization, [
            'before' => $before,
            'after' => $organization->only(['name', 'physical_address', 'timezone', 'limits']),
        ], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Datos de la organización guardados.']);

        return back();
    }

    public function usage(ContactLimit $contacts, MonthlySendQuota $monthly, DailySendQuota $daily): Response
    {
        $organization = $this->current->getOrFail();

        return Inertia::render('admin/organization/Usage', [
            'contacts' => ['used' => $contacts->count($organization->id), 'limit' => $organization->limit('contacts')],
            'monthly' => ['used' => $monthly->used($organization), 'limit' => $organization->limit('monthly_sends'), 'month' => $monthly->month($organization)],
            'history' => $monthly->history($organization),
            'connections' => MailConnection::query()->orderBy('name')->get()->map(fn (MailConnection $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'driver' => $c->driver->label(),
                'status' => $c->status->value,
                'used' => $daily->usedToday($c),
                'limit' => $c->daily_limit,
            ]),
        ]);
    }
}
