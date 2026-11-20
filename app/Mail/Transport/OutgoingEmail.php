<?php

namespace App\Mail\Transport;

/**
 * A fully rendered email for one recipient, independent of the provider.
 */
final readonly class OutgoingEmail
{
    /**
     * @param  array<string, string>  $headers  extra headers (List-Unsubscribe, X-Campaign...)
     * @param  string|null  $reference  stable id of this send (e.g. campaign recipient id);
     *                                  used as idempotency key so retries never send twice
     */
    public function __construct(
        public string $fromEmail,
        public ?string $fromName,
        public string $to,
        public ?string $toName,
        public string $subject,
        public string $html,
        public ?string $text = null,
        public ?string $replyTo = null,
        public array $headers = [],
        public ?string $reference = null,
    ) {}
}
