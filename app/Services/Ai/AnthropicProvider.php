<?php

namespace App\Services\Ai;

use App\Models\AiAssistantSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Anthropic Messages API over Laravel's HTTP client (raw HTTP by design: the key comes from
 * the organization's encrypted settings and every call is fakeable in tests).
 *
 * - JSON answers use structured outputs (output_config.format): forced tool_choice returns a
 *   400 on current models such as Claude Opus 5.5 / Sonnet 5.5.
 * - Errors never include the key or the request headers.
 */
class AnthropicProvider implements AiProvider
{
    public function verify(AiAssistantSetting $settings): ProviderResult
    {
        if (! $settings->hasKey()) {
            return ProviderResult::failure('Falta la API key.');
        }

        try {
            // Models API: validates key and model without consuming tokens.
            $response = $this->client($settings, 15)->get('/v1/models/'.rawurlencode($settings->model));
        } catch (ConnectionException) {
            return ProviderResult::failure('El proveedor no respondió a tiempo. Intenta de nuevo en unos minutos.');
        } catch (Throwable) {
            return ProviderResult::failure('No se pudo contactar al proveedor.');
        }

        if ($response->successful()) {
            return ProviderResult::success(['model' => $response->json('display_name') ?? $settings->model]);
        }

        if ($response->status() === 404) {
            return ProviderResult::failure("El modelo «{$settings->model}» no existe o la API key no tiene acceso a él.");
        }

        return $this->errorFrom($response);
    }

    public function structured(AiAssistantSetting $settings, string $system, string $user, array $schema): ProviderResult
    {
        if (! $settings->hasKey()) {
            return ProviderResult::failure('Falta la API key.');
        }

        $body = [
            'model' => $settings->model,
            'max_tokens' => 8000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => array_filter([
                // Fast reviews: low effort on models that support it.
                'effort' => $this->supportsEffort($settings->model) ? 'low' : null,
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ]),
        ];

        $client = $this->client($settings, $settings->timeout_seconds);

        // Safety-classifier declines are re-run on a fallback model by the API itself.
        if ($this->supportsFallback($settings->model)) {
            $body['fallbacks'] = 'default';
            $client = $client->withHeaders(['anthropic-beta' => 'server-side-fallback-2026-07-01']);
        }

        try {
            $response = $client->post('/v1/messages', $body);
        } catch (ConnectionException) {
            return ProviderResult::failure('La revisión tardó demasiado. Se intentará de nuevo con el próximo cambio.');
        } catch (Throwable) {
            return ProviderResult::failure('No se pudo contactar al proveedor.');
        }

        if (! $response->successful()) {
            return $this->errorFrom($response);
        }

        return match ($response->json('stop_reason')) {
            'refusal' => ProviderResult::failure('El proveedor declinó revisar este contenido.'),
            'max_tokens' => ProviderResult::failure('La respuesta del asistente quedó incompleta.'),
            default => $this->decode($response),
        };
    }

    private function decode(Response $response): ProviderResult
    {
        foreach ((array) $response->json('content') as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $data = json_decode((string) ($block['text'] ?? ''), true);

                return is_array($data)
                    ? ProviderResult::success($data)
                    : ProviderResult::failure('La respuesta del asistente no tiene el formato esperado.');
            }
        }

        return ProviderResult::failure('La respuesta del asistente no tiene el formato esperado.');
    }

    private function errorFrom(Response $response): ProviderResult
    {
        return match (true) {
            in_array($response->status(), [401, 403], true) => ProviderResult::failure('La API key no es válida o fue revocada.', authFailed: true),
            $response->status() === 429 => ProviderResult::failure('El proveedor limitó las solicitudes por ahora. Intenta en unos minutos.'),
            $response->status() === 400 => ProviderResult::failure('El proveedor rechazó la solicitud: '.mb_substr((string) $response->json('error.message', 'solicitud inválida'), 0, 200)),
            $response->serverError() => ProviderResult::failure('El proveedor tiene problemas temporales. Intenta más tarde.'),
            default => ProviderResult::failure("El proveedor respondió con un error ({$response->status()})."),
        };
    }

    private function client(AiAssistantSetting $settings, int $timeout): PendingRequest
    {
        return Http::baseUrl((string) config('services.anthropic.base_url'))
            ->timeout($timeout)
            ->connectTimeout(5)
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => (string) $settings->api_key,
                'anthropic-version' => (string) config('services.anthropic.version'),
            ]);
    }

    private function supportsEffort(string $model): bool
    {
        return (bool) preg_match('/^claude-(opus|sonnet|haiku|fable|mythos)-5|^claude-opus-4-[678]/', $model);
    }

    private function supportsFallback(string $model): bool
    {
        return (bool) preg_match('/^claude-(opus-5|sonnet-5-5|fable-5)/', $model);
    }
}
