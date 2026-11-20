<?php

namespace App\Mail\Transport;

use App\Enums\MailDriver;
use App\Mail\Transport\Drivers\ElasticEmailDriver;
use App\Mail\Transport\Drivers\GmailApiDriver;
use App\Mail\Transport\Drivers\GmailSmtpDriver;
use App\Models\MailConnection;
use App\Services\Google\GoogleOAuthClient;

class MailTransportFactory
{
    public function __construct(
        private readonly MimeMessageBuilder $mime,
        private readonly GoogleOAuthClient $google,
    ) {}

    public function for(MailConnection $connection): MailTransportDriver
    {
        return match ($connection->driver) {
            MailDriver::GmailSmtp => new GmailSmtpDriver($connection, $this->mime),
            MailDriver::GmailApi => new GmailApiDriver($connection, $this->mime, $this->google),
            MailDriver::ElasticEmail => new ElasticEmailDriver($connection),
        };
    }
}
