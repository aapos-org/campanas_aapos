<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Http\SafeUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POSTs one webhook delivery, signed with HMAC-SHA256:
 *   X-AAPOS-Signature: t=<timestamp>,v1=hex(hmac_sha256(secret, "<timestamp>.<body>"))
 * Retries with increasing waits; every attempt is recorded on the delivery.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);
        $endpoint = $delivery?->endpoint;

        if ($delivery === null || $delivery->status === 'delivered') {
            return;
        }

        if ($endpoint === null || ! $endpoint->is_active) {
            $delivery->forceFill(['status' => 'failed', 'error' => 'El destino está desactivado o fue eliminado.'])->save();

            return;
        }

        $delivery->increment('attempts');

        if (! SafeUrl::isPublic($endpoint->url)) {
            // Never retried: the address points to a private network or does not resolve.
            $this->markFailed($delivery, $endpoint, 'La URL no apunta a una dirección pública de internet.');

            return;
        }

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::timeout(15)->withoutRedirecting()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Campanas-AAPOS-Webhooks/1.0',
                    'X-AAPOS-Event' => $delivery->event,
                    'X-AAPOS-Delivery' => (string) $delivery->id,
                    'X-AAPOS-Signature' => self::signature($endpoint->secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $e) {
            $this->retryOrFail($delivery, $endpoint, 'Sin respuesta: '.mb_substr($e->getMessage(), 0, 300));

            return;
        }

        $delivery->forceFill([
            'response_status' => $response->status(),
            'response_body' => mb_substr($response->body(), 0, 2000),
        ]);

        if ($response->successful()) {
            $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'error' => null])->save();
            $endpoint->forceFill(['consecutive_failures' => 0, 'last_success_at' => now()])->save();

            return;
        }

        $this->retryOrFail($delivery, $endpoint, "El destino respondió {$response->status()}.");
    }

    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function retryOrFail(WebhookDelivery $delivery, WebhookEndpoint $endpoint, string $error): void
    {
        if ($this->attempts() < $this->tries) {
            $delivery->forceFill(['error' => $error])->save();
            $this->release($this->backoff()[$this->attempts() - 1] ?? 21600);

            return;
        }

        $this->markFailed($delivery, $endpoint, $error);
    }

    private function markFailed(WebhookDelivery $delivery, WebhookEndpoint $endpoint, string $error): void
    {
        $delivery->forceFill(['status' => 'failed', 'error' => $error])->save();
        $failures = $endpoint->consecutive_failures + 1;

        $endpoint->forceFill([
            'consecutive_failures' => $failures,
            'last_failure_at' => now(),
            // Stop sending to a destination that keeps failing; it can be re-enabled.
            'is_active' => $failures < WebhookEndpoint::DISABLE_AFTER_FAILURES,
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        WebhookDelivery::query()->whereKey($this->deliveryId)->where('status', 'pending')
            ->update(['status' => 'failed', 'error' => mb_substr((string) $e?->getMessage(), 0, 500) ?: 'Falló', 'updated_at' => now()]);
    }
}
