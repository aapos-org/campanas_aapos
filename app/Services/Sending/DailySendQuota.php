<?php

namespace App\Services\Sending;

use App\Models\MailConnection;
use App\Models\MailConnectionDailyUsage;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Per-connection daily send counter. Reservations are atomic (single conditional UPDATE),
 * so concurrent queue workers can never exceed the configured limit.
 *
 * "Day" is the calendar day in the organization's timezone.
 */
class DailySendQuota
{
    /**
     * Reserves $count sends for today. Returns false (and reserves nothing) if the limit would be exceeded.
     */
    public function reserve(MailConnection $connection, int $count = 1): bool
    {
        $day = $this->today($connection);

        DB::table('mail_connection_daily_usage')->insertOrIgnore([
            'mail_connection_id' => $connection->id,
            'day' => $day,
            'sent_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = DB::table('mail_connection_daily_usage')
            ->where('mail_connection_id', $connection->id)
            ->where('day', $day);

        if ($connection->daily_limit !== null) {
            $query->whereRaw('sent_count + ? <= ?', [$count, $connection->daily_limit]);
        }

        return $query->increment('sent_count', max(0, $count), ['updated_at' => now()]) === 1;
    }

    /**
     * Gives back reservations that were not used (send failed permanently).
     */
    public function release(MailConnection $connection, int $count = 1): void
    {
        $count = max(0, $count);
        $query = DB::table('mail_connection_daily_usage')
            ->where('mail_connection_id', $connection->id)
            ->where('day', $this->today($connection));

        // Never below zero.
        if ((clone $query)->where('sent_count', '>=', $count)->decrement('sent_count', $count, ['updated_at' => now()]) === 0) {
            $query->update(['sent_count' => 0, 'updated_at' => now()]);
        }
    }

    public function usedToday(MailConnection $connection): int
    {
        return (int) MailConnectionDailyUsage::query()
            ->where('mail_connection_id', $connection->id)
            ->where('day', $this->today($connection))
            ->value('sent_count');
    }

    public function remainingToday(MailConnection $connection): ?int
    {
        return $connection->daily_limit === null
            ? null
            : max(0, $connection->daily_limit - $this->usedToday($connection));
    }

    private function today(MailConnection $connection): string
    {
        $timezone = Organization::query()->whereKey($connection->organization_id)->value('timezone')
            ?? config('app.display_timezone');

        return CarbonImmutable::now((string) $timezone)->toDateString();
    }
}
