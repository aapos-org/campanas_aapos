<?php

namespace App\Services\Sending;

use App\Enums\ConnectionStatus;
use App\Mail\Transport\MailTransportFactory;
use App\Mail\Transport\OutgoingEmail;
use App\Mail\Transport\TransportException;
use App\Mail\Transport\VerificationResult;
use App\Models\MailConnection;
use App\Models\User;
use App\Support\Audit;

/**
 * Verifies a connection and sends test emails, updating its status.
 */
class ConnectionTester
{
    public function __construct(
        private readonly MailTransportFactory $transports,
        private readonly DailySendQuota $quota,
    ) {}

    public function verify(MailConnection $connection, ?User $by = null): VerificationResult
    {
        $result = $this->transports->for($connection)->verify();

        $connection->forceFill([
            'status' => $result->ok ? ConnectionStatus::Active : ConnectionStatus::Error,
            'last_verified_at' => now(),
            'last_error' => $result->ok ? null : $result->message,
            'meta' => array_merge($connection->meta, ['verification' => $result->details]),
        ])->save();

        Audit::record('connections.verified', $result->ok ? 'Conexión de envío verificada' : 'Falló la verificación de la conexión de envío', $connection, [
            'ok' => $result->ok,
            'message' => $result->message,
        ], $by);

        return $result;
    }

    /**
     * @throws TransportException
     */
    public function sendTest(MailConnection $connection, string $to, ?User $by = null): void
    {
        if (! $this->quota->reserve($connection)) {
            throw new TransportException('Se alcanzó el límite diario de envíos de esta conexión. Intenta mañana o aumenta el límite.');
        }

        $appName = (string) config('app.name');
        $sentAt = now()->setTimezone((string) config('app.display_timezone'))->format('d/m/Y H:i');

        $email = new OutgoingEmail(
            fromEmail: $connection->from_email,
            fromName: $connection->from_name,
            to: $to,
            toName: null,
            subject: "Correo de prueba de {$appName}",
            html: '<p>¡Hola!</p><p>Este es un correo de prueba enviado desde <strong>'.e($appName).'</strong> con la conexión «'.e($connection->name).'» ('.e($connection->driver->label()).').</p><p>Si lo recibiste, la conexión funciona correctamente.</p><p style="color:#6b7280;font-size:12px">Enviado el '.$sentAt.'.</p>',
            replyTo: $connection->reply_to,
            headers: ['X-Campanas-Test' => '1'],
        );

        try {
            $result = $this->transports->for($connection)->send($email);
        } catch (TransportException $e) {
            $this->quota->release($connection);
            $connection->forceFill(['status' => ConnectionStatus::Error, 'last_error' => $e->getMessage()])->save();
            Audit::record('connections.test_failed', 'Falló el correo de prueba', $connection, ['to' => $to, 'error' => $e->getMessage()], $by);

            throw $e;
        }

        $connection->forceFill(['status' => ConnectionStatus::Active, 'last_error' => null, 'last_verified_at' => now()])->save();

        Audit::record('connections.test_sent', 'Correo de prueba enviado', $connection, [
            'to' => $to,
            'message_id' => $result->messageId,
        ], $by);
    }
}
