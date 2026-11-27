<?php

namespace App\Services\Ai;

use App\Models\AiAssistantSetting;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily and monthly AI-review caps per organization (organization timezone). A review is
 * reserved before calling the provider and released if the call fails, so concurrent
 * requests can never exceed the caps (row lock on today's counter).
 */
class AiReviewQuota
{
    /**
     * Returns null when reserved, or the Spanish reason when a cap is reached.
     */
    public function reserve(AiAssistantSetting $settings, Organization $organization): ?string
    {
        $day = $this->today($organization);

        return DB::transaction(function () use ($settings, $organization, $day) {
            DB::table('ai_review_usage')->insertOrIgnore([
                'organization_id' => $organization->id, 'day' => $day, 'reviews' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $today = (int) DB::table('ai_review_usage')->where('organization_id', $organization->id)->where('day', $day)->lockForUpdate()->value('reviews');

            if ($settings->daily_limit !== null && $today >= $settings->daily_limit) {
                return "Se alcanzó el límite diario de {$settings->daily_limit} revisiones con IA. Mañana se reanuda.";
            }

            if ($settings->monthly_limit !== null && $this->month($organization) >= $settings->monthly_limit) {
                return "Se alcanzó el límite mensual de {$settings->monthly_limit} revisiones con IA.";
            }

            DB::table('ai_review_usage')->where('organization_id', $organization->id)->where('day', $day)
                ->update(['reviews' => DB::raw('reviews + 1'), 'updated_at' => now()]);

            return null;
        });
    }

    public function release(Organization $organization): void
    {
        DB::table('ai_review_usage')->where('organization_id', $organization->id)->where('day', $this->today($organization))
            ->where('reviews', '>', 0)->update(['reviews' => DB::raw('reviews - 1'), 'updated_at' => now()]);
    }

    /**
     * @return array{day: int, month: int, daily_limit: int|null, monthly_limit: int|null}
     */
    public function usage(AiAssistantSetting $settings, Organization $organization): array
    {
        return [
            'day' => (int) DB::table('ai_review_usage')->where('organization_id', $organization->id)->where('day', $this->today($organization))->value('reviews'),
            'month' => $this->month($organization),
            'daily_limit' => $settings->daily_limit,
            'monthly_limit' => $settings->monthly_limit,
        ];
    }

    private function month(Organization $organization): int
    {
        $start = CarbonImmutable::now($organization->timezone ?: (string) config('app.display_timezone'))->startOfMonth()->toDateString();

        return (int) DB::table('ai_review_usage')->where('organization_id', $organization->id)->where('day', '>=', $start)->sum('reviews');
    }

    private function today(Organization $organization): string
    {
        return CarbonImmutable::now($organization->timezone ?: (string) config('app.display_timezone'))->toDateString();
    }
}
