<?php

namespace App\Mail\Transport;

final readonly class SendResult
{
    public function __construct(
        /** Message-ID or provider message id. */
        public ?string $messageId,
        /** Provider-specific transaction id (ElasticEmail TransactionID, Gmail message id...). */
        public ?string $providerId = null,
    ) {}
}
