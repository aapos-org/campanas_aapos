<?php

namespace App\Mail\Transport;

final readonly class VerificationResult
{
    /**
     * @param  array<string, mixed>  $details  provider details shown in the UI (e.g. domain SPF/DKIM status)
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public array $details = [],
    ) {}
}
