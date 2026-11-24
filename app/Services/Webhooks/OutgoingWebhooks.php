<?php

namespace App\Services\Webhooks;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Queues an outgoing webhook for every active endpoint of the organization that listens
 * to the event. Jobs are dispatched after the current transaction commits, so a rolled
 * back change never notifies anybody.
 */
class OutgoingWebhooks
{
    /** @var array<int, list<WebhookEndpoint>> endpoints per organization, cached per request/job */
    private array $endpoints = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(int $organizationId, WebhookEvent $event, array $data): void
    {
        foreach ($this->endpointsFor($organizationId) as $endpoint) {
            if (! in_array($event->value, $endpoint->events, true)) {
                continue;
            }

            $delivery = WebhookDelivery::query()->create([
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event->value,
                'payload' => [
                    'id' => (string) Str::uuid(),
                    'event' => $event->value,
                    'occurred_at' => now()->toIso8601String(),
                    'data' => $data,
                ],
            ]);

            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }
    }

    public function forget(int $organizationId): void
    {
        unset($this->endpoints[$organizationId]);
    }

    /**
     * @return list<WebhookEndpoint>
     */
    private function endpointsFor(int $organizationId): array
    {
        return $this->endpoints[$organizationId] ??= array_values(WebhookEndpoint::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)->where('is_active', true)->get()->all());
    }
}
