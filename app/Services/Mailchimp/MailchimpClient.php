<?php

namespace App\Services\Mailchimp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only Mailchimp Marketing API v3.0 client.
 *
 * Mailchimp API keys have full access (there are no read-only keys), so this client
 * exposes ONLY `get()` — there is no code path that can modify the Mailchimp account
 * (guaranteed by tests/Feature/Mailchimp/MailchimpClientTest.php).
 */
class MailchimpClient
{
    public const int PAGE_SIZE = 1000;

    private const int MAX_ATTEMPTS = 6;

    public function __construct(private readonly string $apiKey)
    {
        if (! preg_match('/^[0-9a-f]{32}-[a-z]+\d+$/', $apiKey)) {
            throw new RuntimeException('La API key de Mailchimp no tiene el formato esperado (xxxxxxxx…-us21).');
        }
    }

    public static function fromConfig(): self
    {
        return new self((string) config('services.mailchimp.api_key'));
    }

    public function baseUrl(): string
    {
        $datacenter = substr((string) strrchr($this->apiKey, '-'), 1);

        return "https://{$datacenter}.api.mailchimp.com/3.0/";
    }

    /**
     * GET with retries and exponential backoff on 429/5xx/network errors.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $path = ltrim($path, '/');

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::baseUrl($this->baseUrl())
                    ->withBasicAuth('campanas', $this->apiKey)
                    ->acceptJson()
                    ->timeout(120)
                    ->connectTimeout(15)
                    ->get($path, array_filter($query, fn ($v) => $v !== null));
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException("Sin conexión con Mailchimp tras {$attempt} intentos ({$path}).", 0, $e);
                }

                $this->backoff($attempt);

                continue;
            }

            if ($response->successful()) {
                /** @var array<string, mixed> $json */
                $json = $response->json() ?? [];

                return $json;
            }

            if ($this->isRetryable($response) && $attempt < self::MAX_ATTEMPTS) {
                $this->backoff($attempt, (int) $response->header('Retry-After'));

                continue;
            }

            throw new RuntimeException(sprintf(
                'Mailchimp respondió %d en %s: %s',
                $response->status(),
                $path,
                (string) ($response->json('detail') ?? $response->json('title') ?? mb_substr($response->body(), 0, 200)),
            ));
        }
    }

    private function isRetryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function backoff(int $attempt, int $retryAfter = 0): void
    {
        $seconds = max($retryAfter, min(60, 2 ** $attempt));

        if (! app()->runningUnitTests()) {
            sleep($seconds);
        }
    }
}
