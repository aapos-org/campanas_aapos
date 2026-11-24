<?php

namespace App\Services\Limits;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Per-organization monthly send counter (limit "monthly_sends"). Reservations are a single
 * conditional UPDATE, so concurrent workers can never exceed the limit.
 */
class MonthlySendQuota
{
    public function reserve(Organization $organization, int $count = 1): bool
    {
        $month = $this->month($organization);

        DB::table('organization_monthly_usage')->insertOrIgnore([
            'organization_id' => $organization->id,
            'month' => $month,
            'emails_sent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = DB::table('organization_monthly_usage')
            ->where('organization_id', $organization->id)
            ->where('month', $month);

        $limit = $organization->limit('monthly_sends');

        if ($limit !== null) {
            $query->whereRaw('emails_sent + ? <= ?', [$count, $limit]);
        }

        return $query->increment('emails_sent', max(0, $count), ['updated_at' => now()]) === 1;
    }

    public function release(Organization $organization, int $count = 1): void
    {
        $count = max(0, $count);
        $query = DB::table('organization_monthly_usage')
            ->where('organization_id', $organization->id)
            ->where('month', $this->month($organization));

        if ((clone $query)->where('emails_sent', '>=', $count)->decrement('emails_sent', $count, ['updated_at' => now()]) === 0) {
            $query->update(['emails_sent' => 0, 'updated_at' => now()]);
        }
    }

    public function used(Organization $organization, ?string $month = null): int
    {
        return (int) DB::table('organization_monthly_usage')
            ->where('organization_id', $organization->id)
            ->where('month', $month ?? $this->month($organization))
            ->value('emails_sent');
    }

    public function remaining(Organization $organization): ?int
    {
        $limit = $organization->limit('monthly_sends');

        return $limit === null ? null : max(0, $limit - $this->used($organization));
    }

    /**
     * Emails sent in the last $months months, oldest first.
     *
     * @return list<array{month: string, sent: int}>
     */
    public function history(Organization $organization, int $months = 6): array
    {
        $now = CarbonImmutable::now($organization->timezone)->startOfMonth();
        $rows = DB::table('organization_monthly_usage')->where('organization_id', $organization->id)
            ->where('month', '>=', $now->subMonths($months - 1)->format('Y-m'))
            ->pluck('emails_sent', 'month');

        $history = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $now->subMonths($i)->format('Y-m');
            $history[] = ['month' => $month, 'sent' => (int) ($rows[$month] ?? 0)];
        }

        return $history;
    }

    public function month(Organization $organization): string
    {
        return CarbonImmutable::now($organization->timezone ?: (string) config('app.display_timezone'))->format('Y-m');
    }
}
