<?php

namespace App\Services\Ai;

use App\Models\AiAssistantSetting;

/**
 * A language-model provider behind the writing assistant (only Anthropic for now).
 */
interface AiProvider
{
    /**
     * Cheap connectivity check of the key and model (no tokens consumed when possible).
     */
    public function verify(AiAssistantSetting $settings): ProviderResult;

    /**
     * Sends one request whose answer must follow $schema (JSON). Returns the decoded data.
     *
     * @param  array<string, mixed>  $schema
     */
    public function structured(AiAssistantSetting $settings, string $system, string $user, array $schema): ProviderResult;
}
