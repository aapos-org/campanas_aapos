<?php

use App\Mail\Transport\Drivers\GmailSmtpDriver;
use App\Mail\Transport\MailTransportFactory;
use App\Mail\Transport\OutgoingEmail;
use App\Models\MailConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

afterEach(fn () => GmailSmtpDriver::$transportResolver = null);

function simpleEmail(): OutgoingEmail
{
    return new OutgoingEmail('pruebas@example.org', 'Pruebas', 'ana@example.com', null, 'Asunto', '<p>Hola <b>Ana</b></p>', headers: ['X-Test' => '1']);
}

it('sends through SMTP with a MIME message that has html, text and custom headers', function () {
    $captured = new class extends AbstractTransport
    {
        public ?string $raw = null;

        protected function doSend(SentMessage $message): void
        {
            $this->raw = $message->toString();
        }

        public function __toString(): string
        {
            return 'capture://';
        }
    };
    GmailSmtpDriver::$transportResolver = fn () => $captured;

    $connection = MailConnection::factory()->gmailSmtp()->create();
    app(MailTransportFactory::class)->for($connection)->send(simpleEmail());

    expect($captured->raw)
        ->toContain('Subject: Asunto')
        ->toContain('X-Test: 1')
        ->toContain('text/html')
        ->toContain('text/plain');
});

it('sends with the Gmail API using a base64url raw message', function () {
    Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'g-1', 'threadId' => 't-1'])]);

    $connection = MailConnection::factory()->gmailApi()->create();
    $result = app(MailTransportFactory::class)->for($connection)->send(simpleEmail());

    expect($result->messageId)->toBe('g-1');

    Http::assertSent(function (Request $request) {
        $raw = base64_decode(strtr($request['raw'], '-_', '+/'));

        return $request->hasHeader('Authorization', 'Bearer ya29.test')
            && ! str_contains($request['raw'], '+') && ! str_contains($request['raw'], '/')
            && str_contains($raw, 'Subject: Asunto');
    });
});

it('refreshes an expired Gmail access token and stores it encrypted', function () {
    config(['services.google.client_id' => 'cid', 'services.google.client_secret' => 'csecret']);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.new', 'expires_in' => 3599]),
        'gmail.googleapis.com/*' => Http::response(['id' => 'g-2']),
    ]);

    $connection = MailConnection::factory()->gmailApi()->create([
        'credentials' => ['access_token' => 'old', 'refresh_token' => '1//refresh', 'expires_at' => time() - 10],
    ]);

    app(MailTransportFactory::class)->for($connection)->send(simpleEmail());

    expect($connection->fresh()->credential('access_token'))->toBe('ya29.new');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'refresh_token');

    $raw = DB::table('mail_connections')->where('id', $connection->id)->value('credentials');
    expect($raw)->not->toContain('ya29.new')->not->toContain('1//refresh');
});

it('flags a Gmail API connection whose authorized account differs from the sender', function () {
    Http::fake(['openidconnect.googleapis.com/*' => Http::response(['email' => 'otra@example.org'])]);

    $result = app(MailTransportFactory::class)->for(MailConnection::factory()->gmailApi()->create())->verify();

    expect($result->ok)->toBeFalse()->and($result->message)->toContain('otra@example.org');
});
