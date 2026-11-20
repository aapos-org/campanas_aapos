<?php

namespace App\Mail\Transport;

/**
 * Common contract for every sending provider. Adding a provider (SES, Postmark...)
 * means implementing this interface and registering it in MailTransportFactory.
 */
interface MailTransportDriver
{
    /**
     * @throws TransportException
     */
    public function send(OutgoingEmail $email): SendResult;

    /**
     * Checks credentials/configuration without sending an email.
     */
    public function verify(): VerificationResult;
}
