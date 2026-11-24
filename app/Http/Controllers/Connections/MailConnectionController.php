<?php

namespace App\Http\Controllers\Connections;

use App\Enums\MailDriver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\MailConnectionRequest;
use App\Mail\Transport\TransportException;
use App\Models\MailConnection;
use App\Models\User;
use App\Services\Google\GoogleOAuthClient;
use App\Services\Sending\ConnectionTester;
use App\Services\Sending\DailySendQuota;
use App\Support\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MailConnectionController extends Controller
{
    public function __construct(
        private readonly DailySendQuota $quota,
        private readonly ConnectionTester $tester,
    ) {}

    public function index(): Response
    {
        $connections = MailConnection::query()->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (MailConnection $c) => $this->present($c));

        return Inertia::render('connections/Index', [
            'connections' => $connections,
        ]);
    }

    public function create(GoogleOAuthClient $google): Response
    {
        return Inertia::render('connections/Form', [
            'connection' => null,
            'drivers' => MailDriver::options(),
            'googleConfigured' => $google->isConfigured(),
            'suggestedLimits' => collect(MailDriver::cases())->mapWithKeys(fn (MailDriver $d) => [$d->value => $d->suggestedDailyLimit()]),
        ]);
    }

    public function store(MailConnectionRequest $request, #[CurrentUser] User $user, GoogleOAuthClient $google): RedirectResponse
    {
        $data = $request->validated();
        $driver = MailDriver::from($data['driver']);

        if ($driver === MailDriver::GmailApi && ! $google->isConfigured()) {
            throw ValidationException::withMessages(['driver' => 'Falta configurar GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el servidor para usar OAuth.']);
        }

        $connection = DB::transaction(function () use ($data, $driver) {
            $connection = MailConnection::create([
                'name' => $data['name'],
                'driver' => $driver,
                'from_email' => $data['from_email'],
                'from_name' => $data['from_name'] ?? null,
                'reply_to' => $data['reply_to'] ?? null,
                'daily_limit' => $this->cappedDailyLimit($data['daily_limit'] ?? null),
                'credentials' => $this->credentialsFrom($driver, $data, []),
            ]);

            $this->syncDefault($connection, (bool) ($data['is_default'] ?? false));

            return $connection;
        });

        Audit::record('connections.created', 'Conexión de envío creada', $connection, ['driver' => $driver->value], $user);

        if ($driver === MailDriver::GmailApi) {
            return to_route('connections.google.redirect', $connection);
        }

        $result = $this->tester->verify($connection, $user);
        Inertia::flash('toast', ['type' => $result->ok ? 'success' : 'warning', 'message' => $result->message]);

        return to_route('connections.index');
    }

    public function edit(MailConnection $connection, GoogleOAuthClient $google): Response
    {
        return Inertia::render('connections/Form', [
            'connection' => $this->present($connection),
            'drivers' => MailDriver::options(),
            'googleConfigured' => $google->isConfigured(),
            'suggestedLimits' => [],
        ]);
    }

    public function update(MailConnectionRequest $request, MailConnection $connection, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validated();
        $credentialsChanged = filled($data['password'] ?? null) || filled($data['api_key'] ?? null)
            || ($connection->driver === MailDriver::GmailSmtp && ($data['username'] ?? null) !== $connection->credential('username'));

        DB::transaction(function () use ($connection, $data) {
            $connection->update([
                'name' => $data['name'],
                'from_email' => $data['from_email'],
                'from_name' => $data['from_name'] ?? null,
                'reply_to' => $data['reply_to'] ?? null,
                'daily_limit' => $this->cappedDailyLimit($data['daily_limit'] ?? null),
                'credentials' => $this->credentialsFrom($connection->driver, $data, $connection->credentials ?? []),
            ]);
            $this->syncDefault($connection, (bool) ($data['is_default'] ?? $connection->is_default));
        });

        Audit::record('connections.updated', 'Conexión de envío modificada', $connection, [
            'credentials_changed' => $credentialsChanged,
        ], $user);

        if ($credentialsChanged) {
            $result = $this->tester->verify($connection, $user);
            Inertia::flash('toast', ['type' => $result->ok ? 'success' : 'warning', 'message' => $result->message]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Conexión actualizada.']);
        }

        return to_route('connections.index');
    }

    public function destroy(MailConnection $connection, #[CurrentUser] User $user): RedirectResponse
    {
        $connection->delete();

        Audit::record('connections.deleted', 'Conexión de envío eliminada', $connection, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conexión eliminada.']);

        return to_route('connections.index');
    }

    public function verify(MailConnection $connection, #[CurrentUser] User $user): RedirectResponse
    {
        $result = $this->tester->verify($connection, $user);
        Inertia::flash('toast', ['type' => $result->ok ? 'success' : 'error', 'message' => $result->message]);

        return back();
    }

    public function test(Request $request, MailConnection $connection, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(
            ['to' => ['required', 'email:rfc', 'max:255']],
            [],
            ['to' => 'destinatario'],
        );

        try {
            $this->tester->sendTest($connection, mb_strtolower(trim($data['to'])), $user);
        } catch (TransportException $e) {
            throw ValidationException::withMessages(['to' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Correo de prueba enviado a {$data['to']}."]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function credentialsFrom(MailDriver $driver, array $data, array $current): array
    {
        return match ($driver) {
            MailDriver::GmailSmtp => [
                'username' => $data['username'] ?? $current['username'] ?? null,
                'password' => filled($data['password'] ?? null) ? $data['password'] : ($current['password'] ?? null),
            ],
            MailDriver::ElasticEmail => [
                'api_key' => filled($data['api_key'] ?? null) ? $data['api_key'] : ($current['api_key'] ?? null),
            ],
            MailDriver::GmailApi => $current,
        };
    }

    private function syncDefault(MailConnection $connection, bool $isDefault): void
    {
        if ($isDefault) {
            MailConnection::query()->whereKeyNot($connection->id)->update(['is_default' => false]);
        }

        $connection->forceFill(['is_default' => $isDefault])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MailConnection $connection): array
    {
        $credentials = $connection->credentials ?? [];

        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'driver' => $connection->driver->value,
            'driver_label' => $connection->driver->label(),
            'is_gmail' => $connection->driver->isGmail(),
            'from_email' => $connection->from_email,
            'from_name' => $connection->from_name,
            'reply_to' => $connection->reply_to,
            'daily_limit' => $connection->daily_limit,
            'used_today' => $this->quota->usedToday($connection),
            'is_default' => $connection->is_default,
            'status' => $connection->status->value,
            'status_label' => $connection->status->label(),
            'last_verified_at' => $connection->last_verified_at?->toIso8601String(),
            'last_error' => $connection->last_error,
            'verification' => $connection->meta['verification'] ?? null,
            'provider_tracking_locked' => (bool) ($connection->meta['provider_tracking_locked'] ?? false),
            // Only presence flags and a masked hint — never the secret itself.
            'username' => $connection->driver === MailDriver::GmailSmtp ? ($credentials['username'] ?? null) : null,
            'has_password' => filled($credentials['password'] ?? null),
            'api_key_hint' => filled($credentials['api_key'] ?? null) ? '••••'.mb_substr((string) $credentials['api_key'], -4) : null,
            'oauth_connected' => filled($credentials['refresh_token'] ?? null),
            'oauth_account' => $credentials['account'] ?? null,
        ];
    }

    /**
     * Applies the organization's "daily_sends_per_connection" limit as a ceiling.
     */
    private function cappedDailyLimit(mixed $requested): ?int
    {
        $requested = $requested !== null ? (int) $requested : null;
        $ceiling = app(CurrentOrganization::class)->get()?->limit('daily_sends_per_connection');

        if ($ceiling === null) {
            return $requested;
        }

        return $requested === null ? $ceiling : min($requested, $ceiling);
    }
}
