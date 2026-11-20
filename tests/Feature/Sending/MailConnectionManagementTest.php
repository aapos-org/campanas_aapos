<?php

use App\Enums\ConnectionStatus;
use App\Enums\MailDriver;
use App\Enums\Role;
use App\Mail\Transport\Drivers\GmailSmtpDriver;
use App\Models\AuditLog;
use App\Models\MailConnection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    actAsOrganization($this->admin->organization);
});

afterEach(fn () => GmailSmtpDriver::$transportResolver = null);

it('only lets administrators manage connections', function (Role $role) {
    $user = userWithRole($role, $this->admin->organization);

    $this->actingAs($user)->get(route('connections.index'))->assertForbidden();
})->with([Role::Editor, Role::Viewer]);

it('creates an ElasticEmail connection, stores the key encrypted and verifies it', function () {
    Http::fake([
        'api.elasticemail.com/v4/account' => Http::response(['Email' => 'x']),
        'api.elasticemail.com/v4/domains' => Http::response([['Domain' => 'aapos.org', 'Spf' => true, 'Dkim' => true, 'DMARC' => true, 'MX' => true]]),
    ]);

    $this->actingAs($this->admin)->post(route('connections.store'), [
        'driver' => 'elastic_email',
        'name' => 'Boletín',
        'from_email' => 'Boletin@AAPOS.org',
        'from_name' => 'AAPOS',
        'api_key' => 'ABCDEF1234567890ABCDEF1234567890',
        'is_default' => true,
    ])->assertRedirect(route('connections.index'));

    $connection = MailConnection::query()->sole();

    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->from_email)->toBe('boletin@aapos.org')
        ->and($connection->is_default)->toBeTrue()
        ->and($connection->credential('api_key'))->toBe('ABCDEF1234567890ABCDEF1234567890')
        ->and(DB::table('mail_connections')->value('credentials'))->not->toContain('ABCDEF1234567890');

    expect(AuditLog::query()->where('event', 'connections.created')->exists())->toBeTrue();
});

it('never sends credentials to the browser', function () {
    MailConnection::factory()->create(['credentials' => ['api_key' => 'SUPERSECRET-KEY-0000000000001234']]);
    MailConnection::factory()->gmailSmtp()->create();

    $response = $this->actingAs($this->admin)->get(route('connections.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('connections.0.api_key_hint', '••••1234')->etc());

    expect($response->getContent())
        ->not->toContain('SUPERSECRET-KEY')
        ->not->toContain('abcdefghijklmnop')
        ->not->toContain('abcd efgh');
});

it('requires a daily limit for Gmail and keeps it at or below 2000', function () {
    $this->actingAs($this->admin)->post(route('connections.store'), [
        'driver' => 'gmail_smtp',
        'name' => 'Gmail',
        'from_email' => 'pruebas@example.org',
        'username' => 'pruebas@example.org',
        'password' => 'abcd efgh ijkl mnop',
        'daily_limit' => 5000,
    ])->assertSessionHasErrors('daily_limit');
});

it('keeps the stored secret when the field is left empty on edit', function () {
    Http::fake();
    $connection = MailConnection::factory()->create(['credentials' => ['api_key' => 'ORIGINAL-KEY-000000000000']]);

    $this->actingAs($this->admin)->put(route('connections.update', $connection), [
        'name' => 'Renombrada',
        'from_email' => $connection->from_email,
        'api_key' => '',
    ])->assertRedirect(route('connections.index'));

    expect($connection->fresh()->name)->toBe('Renombrada')
        ->and($connection->fresh()->credential('api_key'))->toBe('ORIGINAL-KEY-000000000000');
});

it('sends a test email and counts it against the daily limit', function () {
    $transport = new class extends AbstractTransport
    {
        public int $sent = 0;

        protected function doSend(SentMessage $message): void
        {
            $this->sent++;
        }

        public function __toString(): string
        {
            return 'fake://';
        }
    };
    GmailSmtpDriver::$transportResolver = fn () => $transport;

    $connection = MailConnection::factory()->gmailSmtp(dailyLimit: 1)->create();

    $this->actingAs($this->admin)
        ->post(route('connections.test', $connection), ['to' => 'yo@example.com'])
        ->assertSessionHasNoErrors();

    // Second test exceeds the limit of 1 and is refused without sending.
    $this->actingAs($this->admin)
        ->post(route('connections.test', $connection), ['to' => 'yo@example.com'])
        ->assertSessionHasErrors('to');

    expect($transport->sent)->toBe(1)
        ->and(AuditLog::query()->where('event', 'connections.test_sent')->count())->toBe(1);
});

it('rejects a Google OAuth callback with a forged state', function () {
    $connection = MailConnection::factory()->gmailApi()->create(['credentials' => []]);

    $this->actingAs($this->admin)
        ->withSession(['google_oauth' => ['state' => 'expected', 'connection_id' => $connection->id]])
        ->get(route('connections.google.callback', ['state' => 'forged', 'code' => 'x']))
        ->assertRedirect(route('connections.index'));

    expect($connection->fresh()->credentials)->toBe([]);
});

it('stores Gmail OAuth tokens after a valid callback', function () {
    config(['services.google.client_id' => 'cid', 'services.google.client_secret' => 'cs']);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.a', 'refresh_token' => '1//r', 'expires_in' => 3600]),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'pruebas@example.org']),
    ]);
    $connection = MailConnection::factory()->gmailApi()->create(['credentials' => [], 'status' => ConnectionStatus::Unverified]);

    $this->actingAs($this->admin)
        ->withSession(['google_oauth' => ['state' => 'ok-state', 'connection_id' => $connection->id]])
        ->get(route('connections.google.callback', ['state' => 'ok-state', 'code' => 'auth-code']))
        ->assertRedirect(route('connections.index'));

    $connection->refresh();
    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->credential('refresh_token'))->toBe('1//r')
        ->and($connection->credential('account'))->toBe('pruebas@example.org')
        ->and($connection->driver)->toBe(MailDriver::GmailApi);
});
