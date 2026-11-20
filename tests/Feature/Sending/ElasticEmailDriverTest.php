<?php

use App\Mail\Transport\MailTransportFactory;
use App\Mail\Transport\OutgoingEmail;
use App\Mail\Transport\TransportException;
use App\Models\MailConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function elasticEmail(): OutgoingEmail
{
    return new OutgoingEmail(
        fromEmail: 'boletin@example.org',
        fromName: 'AAPOS "Boletín"',
        to: 'ana@example.com',
        toName: 'Ana',
        subject: 'Hola',
        html: '<p>Hola Ana</p>',
        replyTo: 'respuestas@example.org',
        headers: ['List-Unsubscribe' => '<https://x.test/u/1>'],
        reference: 'cr-123',
    );
}

it('sends a transactional email with tracking disabled and an idempotency key', function () {
    Http::fake([
        'api.elasticemail.com/v4/emails/transactional' => Http::response(['TransactionID' => 'tx-1', 'MessageID' => 'msg-1']),
    ]);

    $connection = MailConnection::factory()->create(['credentials' => ['api_key' => 'secret-key-1234567890']]);

    $result = app(MailTransportFactory::class)->for($connection)->send(elasticEmail());

    expect($result->messageId)->toBe('msg-1')->and($result->providerId)->toBe('tx-1');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.elasticemail.com/v4/emails/transactional'
            && $request->hasHeader('X-ElasticEmail-ApiKey', 'secret-key-1234567890')
            && $request->hasHeader('Idempotency-Key', 'send-cr-123')
            && $request['Recipients']['To'] === ['ana@example.com']
            && $request['Content']['From'] === 'AAPOS Boletín <boletin@example.org>'
            && $request['Content']['Subject'] === 'Hola'
            && $request['Content']['Headers']['List-Unsubscribe'] === '<https://x.test/u/1>'
            && $request['Content']['Postback'] === 'cr-123'
            && $request['Options']['TrackOpens'] === false
            && $request['Options']['TrackClicks'] === false;
    });
});

it('marks rate limits and server errors as retryable, and auth errors as permanent', function (int $status, bool $retryable) {
    Http::fake(['*' => Http::response(['Error' => 'x'], $status)]);
    $connection = MailConnection::factory()->create();

    try {
        app(MailTransportFactory::class)->for($connection)->send(elasticEmail());
        $this->fail('Expected exception');
    } catch (TransportException $e) {
        expect($e->retryable)->toBe($retryable);
    }
})->with([
    'rate limit' => [429, true],
    'server error' => [502, true],
    'invalid key' => [401, false],
    'bad request' => [400, false],
]);

it('verifies the key and reports the DNS status of the sender domain', function () {
    Http::fake([
        'api.elasticemail.com/v4/account' => Http::response(['Email' => 'x']),
        'api.elasticemail.com/v4/domains' => Http::response([
            ['Domain' => 'otro.org', 'Spf' => true, 'Dkim' => true],
            ['Domain' => 'example.org', 'Spf' => 'true', 'Dkim' => false, 'DMARC' => true, 'MX' => true, 'TrackingStatus' => 'Validated'],
        ]),
    ]);

    $result = app(MailTransportFactory::class)->for(MailConnection::factory()->create())->verify();

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toContain('faltan registros DNS')
        ->and($result->details['records'])->toBe(['spf' => true, 'dkim' => false, 'dmarc' => true, 'mx' => true, 'tracking' => 'Validated']);
});

it('fails verification with an invalid key', function () {
    Http::fake(['api.elasticemail.com/v4/account' => Http::response([], 401)]);

    $result = app(MailTransportFactory::class)->for(MailConnection::factory()->create())->verify();

    expect($result->ok)->toBeFalse()->and($result->message)->toContain('rechazó la clave');
});

it('retries without tracking options when the account does not allow changing them', function () {
    Http::fakeSequence('api.elasticemail.com/v4/emails/transactional')
        ->push(['Error' => 'You cannot change the tracking options'], 400)
        ->push(['TransactionID' => 'tx-2', 'MessageID' => 'msg-2'])
        ->push(['TransactionID' => 'tx-3', 'MessageID' => 'msg-3']);

    $connection = MailConnection::factory()->create();
    $driver = app(MailTransportFactory::class)->for($connection);

    expect($driver->send(elasticEmail())->messageId)->toBe('msg-2')
        ->and($connection->fresh()->meta['provider_tracking_locked'])->toBeTrue();

    // Next sends skip the options straight away (one request each).
    app(MailTransportFactory::class)->for($connection->fresh())->send(elasticEmail());

    $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
    expect($requests)->toHaveCount(3)
        ->and($requests[0]->data())->toHaveKey('Options')
        ->and($requests[1]->data())->not->toHaveKey('Options')
        ->and($requests[2]->data())->not->toHaveKey('Options')
        // The retry uses a different idempotency key (same key + different body = 422).
        ->and($requests[0]->header('Idempotency-Key')[0])->toBe('send-cr-123')
        ->and($requests[1]->header('Idempotency-Key')[0])->toBe('send-cr-123-nt');
});

it('does not retry other 400 errors', function () {
    Http::fake(['*' => Http::response(['Error' => 'Invalid recipient'], 400)]);
    $connection = MailConnection::factory()->create();

    expect(fn () => app(MailTransportFactory::class)->for($connection)->send(elasticEmail()))
        ->toThrow(TransportException::class);

    Http::assertSentCount(1);
    expect($connection->fresh()->meta)->not->toHaveKey('provider_tracking_locked');
});
