<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outgoing webhooks: destinations, signing secrets and the delivery log.
 */
class OutgoingWebhookController extends Controller
{
    public function index(Request $request): Response
    {
        $endpoints = WebhookEndpoint::query()->orderBy('name')->get();
        $endpointId = $request->integer('endpoint') ?: null;

        return Inertia::render('admin/outgoing-webhooks/Index', [
            'endpoints' => $endpoints->map(fn (WebhookEndpoint $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'url' => $e->url,
                'secret' => $e->secret,
                'events' => $e->events,
                'is_active' => $e->is_active,
                'consecutive_failures' => $e->consecutive_failures,
                'last_success_at' => $e->last_success_at?->toIso8601String(),
                'last_failure_at' => $e->last_failure_at?->toIso8601String(),
            ]),
            'deliveries' => WebhookDelivery::query()
                ->whereIn('webhook_endpoint_id', $endpoints->pluck('id'))
                ->when($endpointId, fn ($q, $id) => $q->where('webhook_endpoint_id', $id))
                ->latest('id')->paginate(30)->withQueryString()
                ->through(fn (WebhookDelivery $d) => [
                    'id' => $d->id,
                    'endpoint' => $endpoints->firstWhere('id', $d->webhook_endpoint_id)?->name,
                    'event' => $d->event,
                    'status' => $d->status,
                    'attempts' => $d->attempts,
                    'response_status' => $d->response_status,
                    'error' => $d->error,
                    'payload' => $d->payload,
                    'response_body' => $d->response_body,
                    'created_at' => $d->created_at?->toIso8601String(),
                    'delivered_at' => $d->delivered_at?->toIso8601String(),
                ]),
            'eventOptions' => WebhookEvent::options(),
            'filters' => ['endpoint' => $endpointId],
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $endpoint = WebhookEndpoint::query()->create($this->validated($request) + [
            'secret' => 'whsec_'.Str::random(40),
            'created_by' => $user->id,
        ]);

        Audit::record('webhooks.endpoint_created', 'Webhook saliente creado', $endpoint, ['url' => $endpoint->url, 'events' => $endpoint->events], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Destino creado. Copia el secreto para verificar las firmas.']);

        return back();
    }

    public function update(Request $request, WebhookEndpoint $endpoint, #[CurrentUser] User $user): RedirectResponse
    {
        $endpoint->update($this->validated($request) + ['consecutive_failures' => 0]);
        Audit::record('webhooks.endpoint_updated', 'Webhook saliente modificado', $endpoint, ['url' => $endpoint->url, 'events' => $endpoint->events, 'active' => $endpoint->is_active], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Destino guardado.']);

        return back();
    }

    public function destroy(WebhookEndpoint $endpoint, #[CurrentUser] User $user): RedirectResponse
    {
        $endpoint->delete();
        Audit::record('webhooks.endpoint_deleted', 'Webhook saliente eliminado', $endpoint, ['url' => $endpoint->url], $user);

        return back();
    }

    /**
     * Sends a "ping" event to check the destination and its signature verification.
     */
    public function test(WebhookEndpoint $endpoint): RedirectResponse
    {
        $delivery = $endpoint->deliveries()->create([
            'event' => 'ping',
            'payload' => ['id' => (string) Str::uuid(), 'event' => 'ping', 'occurred_at' => now()->toIso8601String(), 'data' => ['message' => 'Prueba desde Campañas AAPOS']],
        ]);
        DeliverWebhook::dispatch($delivery->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Prueba enviada: revisa el registro de entregas.']);

        return back();
    }

    public function retry(WebhookDelivery $delivery): RedirectResponse
    {
        abort_unless(WebhookEndpoint::query()->whereKey($delivery->webhook_endpoint_id)->exists(), 404);

        $delivery->forceFill(['status' => 'pending', 'error' => null])->save();
        DeliverWebhook::dispatch($delivery->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entrega reenviada.']);

        return back();
    }

    /**
     * @return array{name: string, url: string, events: list<string>, is_active: bool}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:https,http', 'max:1000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::enum(WebhookEvent::class)],
            'is_active' => ['boolean'],
        ], [], ['name' => 'nombre', 'url' => 'URL', 'events' => 'eventos']);

        return [
            'name' => (string) $data['name'],
            'url' => (string) $data['url'],
            'events' => array_values(array_unique($data['events'])),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
