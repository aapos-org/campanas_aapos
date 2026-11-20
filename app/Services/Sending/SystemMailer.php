<?php

namespace App\Services\Sending;

use App\Enums\ConnectionStatus;
use App\Mail\Transport\MailTransportFactory;
use App\Mail\Transport\OutgoingEmail;
use App\Mail\Transport\SendResult;
use App\Mail\Transport\TransportException;
use App\Models\MailConnection;

/**
 * Sends application emails to contacts (double opt-in confirmations) through the
 * organization's default sending connection, respecting its daily quota.
 */
class SystemMailer
{
    public function __construct(
        private readonly MailTransportFactory $transports,
        private readonly DailySendQuota $quota,
    ) {}

    public function connection(): ?MailConnection
    {
        return MailConnection::query()
            ->where('status', '!=', ConnectionStatus::Error->value)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @throws TransportException
     */
    public function send(OutgoingEmail $email, MailConnection $connection): SendResult
    {
        if (! $this->quota->reserve($connection)) {
            throw new TransportException('Se alcanzó el límite diario de envíos de la conexión.', true);
        }

        try {
            return $this->transports->for($connection)->send($email);
        } catch (TransportException $e) {
            $this->quota->release($connection);

            throw $e;
        }
    }
}
