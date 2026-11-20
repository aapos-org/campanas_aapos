<?php

namespace App\Mail\Transport\Drivers;

use App\Mail\Transport\MailTransportDriver;
use App\Mail\Transport\MimeMessageBuilder;
use App\Mail\Transport\OutgoingEmail;
use App\Mail\Transport\SendResult;
use App\Mail\Transport\TransportException;
use App\Mail\Transport\VerificationResult;
use App\Models\MailConnection;
use App\Services\Google\GoogleOAuthClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gmail API (users.messages.send) with OAuth2 tokens. Access tokens are refreshed
 * automatically with the stored refresh token.
 */
class GmailApiDriver implements MailTransportDriver
{
    public const string SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function __construct(
        private readonly MailConnection $connection,
        private readonly MimeMessageBuilder $mime,
        private readonly GoogleOAuthClient $google,
    ) {}

    public function send(OutgoingEmail $email): SendResult
    {
        $raw = rtrim(strtr(base64_encode($this->mime->build($email)->toString()), '+/', '-_'), '=');

        try {
            $response = Http::withToken($this->google->accessToken($this->connection))
                ->acceptJson()
                ->timeout(30)
                ->post(self::SEND_URL, ['raw' => $raw]);
        } catch (ConnectionException $e) {
            throw new TransportException('No se pudo conectar con la API de Gmail.', true, $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $reason = (string) $response->json('error.message', 'error desconocido');

            throw new TransportException(
                "La API de Gmail respondió {$status}: {$reason}",
                $status === 429 || $status >= 500,
            );
        }

        return new SendResult((string) $response->json('id'), (string) $response->json('threadId'));
    }

    public function verify(): VerificationResult
    {
        try {
            $email = $this->google->authenticatedEmail($this->connection);
        } catch (TransportException $e) {
            return new VerificationResult(false, $e->getMessage());
        }

        if ($email !== null && mb_strtolower($email) !== mb_strtolower($this->connection->from_email)) {
            return new VerificationResult(
                false,
                "La cuenta autorizada es {$email}, pero el remitente configurado es {$this->connection->from_email}. Gmail solo envía como la cuenta autorizada o sus alias.",
                ['account' => $email],
            );
        }

        return new VerificationResult(true, 'Autorización de Gmail verificada.', ['account' => $email]);
    }
}
