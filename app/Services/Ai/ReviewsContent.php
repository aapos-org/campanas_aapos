<?php

namespace App\Services\Ai;

use App\Models\AiAssistantSetting;
use App\Models\Organization;
use App\Models\User;

/**
 * Reviews a campaign draft. Never throws: problems come back as `available: false`.
 */
interface ReviewsContent
{
    /**
     * @return array{available: bool, reason: string|null, limited: bool, deterministic: list<array<string, string>>, ai: list<array<string, string>>, metrics: array<string, int|float|bool>, cached: bool, truncated: bool}
     */
    public function review(string $subject, ?string $preheader, string $html, AiAssistantSetting $settings, Organization $organization, ?User $user = null, ?int $campaignId = null): array;
}
