<?php

namespace App\Http\Controllers\Connections;

use App\Enums\ConnectionStatus;
use App\Enums\MailDriver;
use App\Http\Controllers\Controller;
use App\Mail\Transport\TransportException;
use App\Models\MailConnection;
use App\Models\User;
use App\Services\Google\GoogleOAuthClient;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth consent flow for "Gmail (OAuth / API)" connections.
 * The `state` parameter is a random value bound to the session (CSRF protection).
 */
class GoogleOAuthController extends Controller
{
    private const string SESSION_KEY = 'google_oauth';

    public function __construct(private readonly GoogleOAuthClient $google) {}

    public function redirect(Request $request, MailConnection $connection): Response
    {
        abort_unless($connection->driver === MailDriver::GmailApi, 404);
        abort_unless($this->google->isConfigured(), 422, 'OAuth de Google no está configurado.');

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'connection_id' => $connection->id]);

        return Inertia::location($this->google->authorizationUrl($state, $connection->from_email));
    }

    public function callback(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        /** @var array{state?: string, connection_id?: int}|null $pending */
        $pending = $request->session()->pull(self::SESSION_KEY);
        $state = (string) $request->query('state', '');

        if (! is_array($pending) || ! isset($pending['state'], $pending['connection_id']) || ! hash_equals($pending['state'], $state)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'La autorización de Google no es válida o caducó. Inténtalo de nuevo.']);

            return to_route('connections.index');
        }

        $connection = MailConnection::query()->findOrFail($pending['connection_id']);

        if ($request->filled('error') || ! $request->filled('code')) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'No se concedió el acceso a Gmail.']);

            return to_route('connections.index');
        }

        try {
            $tokens = $this->google->exchangeCode((string) $request->query('code'));
        } catch (TransportException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('connections.index');
        }

        $credentials = array_merge($connection->credentials ?? [], array_filter($tokens, fn ($v) => $v !== null));
        $connection->forceFill(['credentials' => $credentials])->save();

        try {
            $account = $this->google->authenticatedEmail($connection);
        } catch (TransportException) {
            $account = null;
        }

        $matches = $account !== null && mb_strtolower($account) === mb_strtolower($connection->from_email);

        $connection->forceFill([
            'credentials' => array_merge($credentials, ['account' => $account]),
            'status' => $matches ? ConnectionStatus::Active : ConnectionStatus::Error,
            'last_verified_at' => now(),
            'last_error' => $matches ? null : "Se autorizó la cuenta {$account}, pero el remitente es {$connection->from_email}.",
        ])->save();

        Audit::record('connections.oauth_authorized', 'Cuenta de Gmail autorizada por OAuth', $connection, ['account' => $account], $user);

        Inertia::flash('toast', $matches
            ? ['type' => 'success', 'message' => "Gmail conectado como {$account}."]
            : ['type' => 'warning', 'message' => "Se autorizó {$account}, que no coincide con el remitente {$connection->from_email}."]);

        return to_route('connections.index');
    }
}
