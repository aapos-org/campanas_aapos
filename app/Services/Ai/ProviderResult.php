<?php

namespace App\Services\Ai;

/**
 * Outcome of a provider call. Errors carry a readable Spanish message (never the key or
 * request headers); $authFailed marks invalid/revoked keys so the settings show it.
 */
final readonly class ProviderResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    private function __construct(
        public bool $ok,
        public ?array $data = null,
        public ?string $error = null,
        public bool $authFailed = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function success(?array $data = null): self
    {
        return new self(true, $data);
    }

    public static function failure(string $error, bool $authFailed = false): self
    {
        return new self(false, null, $error, $authFailed);
    }
}
