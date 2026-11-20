<?php

namespace App\Mail\Transport;

use RuntimeException;
use Throwable;

/**
 * Sending failure. `retryable` distinguishes temporary problems (rate limit, 5xx, network)
 * from permanent ones (bad credentials, rejected recipient).
 */
class TransportException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
