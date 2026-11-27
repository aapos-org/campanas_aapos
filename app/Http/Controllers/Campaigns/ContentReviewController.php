<?php

namespace App\Http\Controllers\Campaigns;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AiAssistantSetting;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Ai\AiReviewQuota;
use App\Services\Ai\ReviewsContent;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Live, advisory review of the draft being edited (subject and preheader as saved, the
 * HTML as it is in the editor right now). It never blocks saving or sending.
 */
class ContentReviewController extends Controller
{
    public function __invoke(Request $request, Campaign $campaign, ReviewsContent $reviewer, AiReviewQuota $quota, CurrentOrganization $current, #[CurrentUser] User $user): JsonResponse
    {
        $data = $request->validate([
            'html' => ['nullable', 'string', 'max:2000000'],
            'variant' => ['nullable', Rule::in(['A', 'B'])],
            // The editor's «Aplicar Smart Brevity» switch can turn it off for this review.
            'smart_brevity' => ['nullable', 'boolean'],
        ]);

        $subject = (string) $campaign->subject;
        $preheader = $campaign->preheader;

        if (($data['variant'] ?? null) === 'B' && $campaign->isAbTest()) {
            $variant = $campaign->variants()->where('label', 'B')->first();
            $subject = (string) ($variant?->subject ?: $subject);
            $preheader = $variant?->preheader ?: $preheader;
        }

        $settings = AiAssistantSetting::current();
        $organization = $current->getOrFail();

        // The editor's switch can turn Smart Brevity off for this review only (a copy, never saved).
        $reviewSettings = clone $settings;

        if (($data['smart_brevity'] ?? true) === false) {
            $reviewSettings->smart_brevity_enabled = false;
        }

        return response()->json(
            $reviewer->review($subject, $preheader, (string) ($data['html'] ?? ''), $reviewSettings, $organization, $user, $campaign->id)
            + ['usage' => $quota->usage($settings, $organization), 'config' => self::publicConfig($settings->exists ? $settings->refresh() : $settings, $user)],
        );
    }

    /**
     * What the editor needs to drive the live review. No secrets.
     *
     * @return array<string, mixed>
     */
    public static function publicConfig(AiAssistantSetting $settings, ?User $user): array
    {
        return [
            'ai_available' => $settings->isUsable() && $settings->status !== 'error',
            'live_review_enabled' => $settings->live_review_enabled,
            'manual_review_enabled' => $settings->manual_review_enabled,
            'smart_brevity_enabled' => $settings->smart_brevity_enabled,
            'smart_brevity_max_words' => $settings->smart_brevity_max_words,
            'debounce_ms' => $settings->debounce_ms,
            'min_chars_change' => $settings->min_chars_change,
            'can_configure' => $user?->can(Permission::SettingsManage->value) ?? false,
        ];
    }
}
