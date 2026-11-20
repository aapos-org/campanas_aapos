<?php

namespace App\Mail\Transport\Drivers;

use App\Mail\Transport\MailTransportDriver;
use App\Mail\Transport\MimeMessageBuilder;
use App\Mail\Transport\OutgoingEmail;
use App\Mail\Transport\SendResult;
use App\Mail\Transport\TransportException;
use App\Mail\Transport\VerificationResult;
use App\Models\MailConnection;
use Closure;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Gmail via SMTP (smtp.gmail.com:587, STARTTLS) with an app password.
 * Requires 2-Step Verification on the Google account.
 */
class GmailSmtpDriver implements MailTransportDriver
{
    public const string HOST = 'smtp.gmail.com';

    public const int PORT = 587;

    /** Test hook: replaces the real SMTP transport. */
    public static ?Closure $transportResolver = null;

    public function __construct(
        private readonly MailConnection $connection,
        private readonly MimeMessageBuilder $mime,
    ) {}

    public function send(OutgoingEmail $email): SendResult
    {
        try {
            $sent = $this->transport()->send($this->mime->build($email));
        } catch (TransportExceptionInterface $e) {
            throw new TransportException($this->describe($e), $this->isRetryable($e), $e);
        }

        return new SendResult($sent?->getMessageId());
    }

    public function verify(): VerificationResult
    {
        $transport = $this->transport();

        try {
            if ($transport instanceof EsmtpTransport) {
                $transport->start();
                $transport->stop();
            }
        } catch (TransportExceptionInterface $e) {
            return new VerificationResult(false, $this->describe($e));
        }

        return new VerificationResult(true, 'Conexión SMTP con Gmail verificada.');
    }

    private function transport(): TransportInterface
    {
        if (self::$transportResolver !== null) {
            return (self::$transportResolver)($this->connection);
        }

        $transport = new EsmtpTransport(self::HOST, self::PORT, false);
        $transport->setUsername((string) $this->connection->credential('username'));
        // App passwords are shown with spaces ("abcd efgh ijkl mnop"); Google ignores them.
        $transport->setPassword(str_replace(' ', '', (string) $this->connection->credential('password')));

        return $transport;
    }

    private function describe(TransportExceptionInterface $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, '535') || str_contains($message, 'Username and Password not accepted')) {
            return 'Gmail rechazó el usuario o la contraseña de aplicación. Revisa que la cuenta tenga la verificación en dos pasos y que la contraseña de aplicación sea correcta.';
        }

        if (str_contains($message, '550') && str_contains($message, 'Daily user sending limit')) {
            return 'Gmail alcanzó su límite diario de envío para esta cuenta.';
        }

        return 'Error SMTP de Gmail: '.mb_substr($message, 0, 300);
    }

    private function isRetryable(TransportExceptionInterface $e): bool
    {
        // 4xx SMTP replies are temporary; 5xx are permanent.
        return (bool) preg_match('/\b4\d\d\b/', $e->getMessage()) || str_contains($e->getMessage(), 'Connection');
    }
}
