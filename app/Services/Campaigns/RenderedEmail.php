<?php

namespace App\Services\Campaigns;

final readonly class RenderedEmail
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
        public array $headers,
    ) {}
}
