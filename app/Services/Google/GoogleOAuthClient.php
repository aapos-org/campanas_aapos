<?php

namespace App\Services\Google;

use App\Mail\Transport\TransportException;
use App\Models\MailConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal OAuth2 client for Gmail (authorization code + refresh token).
 * Fixed Google endpoints only (no user-provided URLs → no SSRF surface).
 */
class GoogleOAuthClient
{
    public const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const string USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** Sending only + identity of the authorized account. */
    public const array SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/gmail.send'];

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        return self::AUTH_URL.'?'.http_build_query(array_filter([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            // Forces a refresh token even if the account authorized the app before.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'login_hint' => $loginHint,
        ]));
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null}
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);

        return [
            'access_token' => (string) $response['access_token'],
            'refresh_token' => isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            'expires_at' => time() + (int) ($response['expires_in'] ?? 3600),
            'scope' => isset($response['scope']) ? (string) $response['scope'] : null,
        ];
    }

    /**
     * Returns a valid access token, refreshing (and persisting) it when about to expire.
     *
     * @throws TransportException
     */
    public function accessToken(MailConnection $connection): string
    {
        $credentials = $connection->credentials ?? [];
        $expiresAt = (int) ($credentials['expires_at'] ?? 0);

        if (filled($credentials['access_token'] ?? null) && $expiresAt > time() + 60) {
            return (string) $credentials['access_token'];
        }

        if (blank($credentials['refresh_token'] ?? null)) {
            throw new TransportException('La conexión de Gmail no tiene autorización. Vuelve a conectar la cuenta.');
        }

        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $credentials['refresh_token'],
        ]);

        $credentials['access_token'] = (string) $response['access_token'];
        $credentials['expires_at'] = time() + (int) ($response['expires_in'] ?? 3600);
        $connection->forceFill(['credentials' => $credentials])->save();

        return $credentials['access_token'];
    }

    /**
     * @throws TransportException
     */
    public function authenticatedEmail(MailConnection $connection): ?string
    {
        try {
            $response = Http::withToken($this->accessToken($connection))->acceptJson()->timeout(15)->get(self::USERINFO_URL);
        } catch (ConnectionException $e) {
            throw new TransportException('No se pudo conectar con Google.', true, $e);
        }

        if ($response->failed()) {
            throw new TransportException('Google rechazó la autorización ('.$response->status().'). Vuelve a conectar la cuenta.');
        }

        $email = $response->json('email');

        return is_string($email) ? $email : null;
    }

    public function redirectUri(): string
    {
        return (string) (config('services.google.redirect') ?: route('connections.google.callback'));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    private function tokenRequest(array $params): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN_URL, $params + [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
            ]);
        } catch (ConnectionException $e) {
            throw new TransportException('No se pudo conectar con Google.', true, $e);
        }

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            $error = (string) $response->json('error', 'error');

            throw new TransportException(
                $error === 'invalid_grant'
                    ? 'Google revocó o caducó la autorización de Gmail. Vuelve a conectar la cuenta.'
                    : "Google rechazó la solicitud de token ({$error}).",
                $response->serverError(),
            );
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }
}
