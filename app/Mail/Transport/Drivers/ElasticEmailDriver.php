<?php

namespace App\Mail\Transport\Drivers;

use App\Mail\Transport\MailTransportDriver;
use App\Mail\Transport\OutgoingEmail;
use App\Mail\Transport\SendResult;
use App\Mail\Transport\TransportException;
use App\Mail\Transport\VerificationResult;
use App\Models\MailConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * ElasticEmail REST API v4.
 *
 *  - Send: POST /emails/transactional (one recipient per call, content already personalized).
 *    Uses the `Idempotency-Key` header so a retried job never sends twice.
 *  - Open/click tracking of ElasticEmail is disabled: the app tracks with signed links (D10).
 *  - Verify: GET /account (key works) + GET /domains (SPF/DKIM/DMARC/MX of the sender domain).
 */
class ElasticEmailDriver implements MailTransportDriver
{
    public function __construct(private readonly MailConnection $connection) {}

    public function send(OutgoingEmail $email): SendResult
    {
        $from = $email->fromName ? sprintf('%s <%s>', $this->sanitizeName($email->fromName), $email->fromEmail) : $email->fromEmail;

        $body = [
            'Recipients' => ['To' => [$email->to]],
            'Content' => array_filter([
                'From' => $from,
                'ReplyTo' => $email->replyTo,
                'Subject' => $email->subject,
                'Body' => $email->text !== null
                    ? [
                        ['ContentType' => 'HTML', 'Content' => $email->html, 'Charset' => 'utf-8'],
                        ['ContentType' => 'PlainText', 'Content' => $email->text, 'Charset' => 'utf-8'],
                    ]
                    : [['ContentType' => 'HTML', 'Content' => $email->html, 'Charset' => 'utf-8']],
                'Headers' => $email->headers !== [] ? $email->headers : null,
                'Postback' => $email->reference,
            ], fn ($value) => $value !== null),
            'Options' => [
                'TrackOpens' => false,
                'TrackClicks' => false,
            ],
        ];

        $request = $this->client();

        if ($email->reference !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => mb_substr('send-'.$email->reference, 0, 255)]);
        }

        try {
            $response = $request->post('emails/transactional', $body);
        } catch (ConnectionException $e) {
            throw new TransportException('No se pudo conectar con ElasticEmail.', true, $e);
        }

        if ($response->failed()) {
            throw new TransportException($this->errorMessage($response), $this->isRetryable($response));
        }

        return new SendResult(
            $response->json('MessageID') !== null ? (string) $response->json('MessageID') : null,
            $response->json('TransactionID') !== null ? (string) $response->json('TransactionID') : null,
        );
    }

    public function verify(): VerificationResult
    {
        try {
            $account = $this->client()->get('account');
        } catch (ConnectionException) {
            return new VerificationResult(false, 'No se pudo conectar con ElasticEmail.');
        }

        if ($account->failed()) {
            return new VerificationResult(false, $this->errorMessage($account));
        }

        $domain = $this->senderDomain();
        $details = ['domain' => $domain, 'records' => null];

        try {
            $domains = $this->client()->get('domains');
        } catch (ConnectionException) {
            $domains = null;
        }

        if ($domains !== null && $domains->successful()) {
            foreach ((array) $domains->json() as $item) {
                if (is_array($item) && mb_strtolower((string) ($item['Domain'] ?? '')) === $domain) {
                    $details['records'] = [
                        'spf' => $this->bool($item['Spf'] ?? null),
                        'dkim' => $this->bool($item['Dkim'] ?? null),
                        'dmarc' => $this->bool($item['DMARC'] ?? null),
                        'mx' => $this->bool($item['MX'] ?? null),
                        'tracking' => $item['TrackingStatus'] ?? null,
                    ];
                }
            }
        }

        if ($details['records'] === null) {
            return new VerificationResult(true, "La clave de API es válida, pero el dominio {$domain} no está agregado en ElasticEmail.", $details);
        }

        $records = $details['records'];
        $ok = $records['spf'] && $records['dkim'];

        return new VerificationResult(
            true,
            $ok ? "Clave válida y dominio {$domain} verificado (SPF y DKIM)." : "Clave válida, pero faltan registros DNS del dominio {$domain}.",
            $details,
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.elasticemail.base_url'), '/').'/')
            ->withHeaders(['X-ElasticEmail-ApiKey' => (string) $this->connection->credential('api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10);
    }

    private function senderDomain(): string
    {
        return mb_strtolower((string) substr(strrchr($this->connection->from_email, '@') ?: '', 1));
    }

    private function isRetryable(Response $response): bool
    {
        return in_array($response->status(), [408, 409, 429, 503], true) || $response->serverError();
    }

    private function errorMessage(Response $response): string
    {
        $detail = $response->json('Error') ?? $response->json('error') ?? $response->json('message') ?? $response->json('title') ?? mb_substr($response->body(), 0, 200);

        return match (true) {
            in_array($response->status(), [401, 403], true) => 'ElasticEmail rechazó la clave de API (o no tiene los permisos necesarios: SendHttp, ViewAccount, ViewSettings).',
            $response->status() === 429 => 'ElasticEmail limitó las peticiones; se reintentará más tarde.',
            default => 'ElasticEmail respondió '.$response->status().': '.(is_string($detail) ? $detail : json_encode($detail)),
        };
    }

    private function bool(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1;
    }

    private function sanitizeName(string $name): string
    {
        return trim(str_replace(['<', '>', '"', "\r", "\n"], '', $name));
    }
}
