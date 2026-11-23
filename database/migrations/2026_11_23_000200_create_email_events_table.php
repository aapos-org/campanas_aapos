<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unified event log (sent, delivered, open, click, bounces, complaints, unsubscribes),
 * partitioned by month on occurred_at (PostgreSQL declarative partitioning).
 * Partitions are created ahead by `php artisan events:partitions` (scheduled monthly);
 * rows outside any partition land in email_events_default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE email_events (
                id bigserial NOT NULL,
                organization_id bigint NOT NULL,
                campaign_id bigint NULL,
                campaign_recipient_id bigint NULL,
                contact_id bigint NULL,
                campaign_link_id bigint NULL,
                type varchar(30) NOT NULL,
                occurred_at timestamp(0) without time zone NOT NULL,
                -- Provider event id or our own dedupe key: the same event is never stored twice
                dedupe_key varchar(191) NULL,
                ip varchar(45) NULL,
                user_agent varchar(500) NULL,
                meta jsonb NULL,
                created_at timestamp(0) without time zone NULL,
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
            SQL);

        DB::statement('CREATE TABLE email_events_default PARTITION OF email_events DEFAULT');
        DB::statement('CREATE INDEX email_events_campaign_type ON email_events (campaign_id, type)');
        DB::statement('CREATE INDEX email_events_recipient ON email_events (campaign_recipient_id)');
        DB::statement('CREATE INDEX email_events_contact ON email_events (contact_id, occurred_at)');
        DB::statement('CREATE INDEX email_events_org_time ON email_events (organization_id, occurred_at)');

        // Dedupe keys live in a small side table (a unique index on a partitioned table
        // must include the partition key, which would not prevent cross-month duplicates).
        DB::statement('CREATE TABLE email_event_keys (dedupe_key varchar(191) PRIMARY KEY, created_at timestamp(0) without time zone NULL)');

        // Monthly partitions: 24 months back (Mailchimp history) to 3 months ahead.
        $month = CarbonImmutable::now()->startOfMonth()->subMonths(24);
        $last = CarbonImmutable::now()->startOfMonth()->addMonths(3);
        while ($month <= $last) {
            $name = 'email_events_'.$month->format('Y_m');
            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF email_events FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $month->toDateString(),
                $month->addMonth()->toDateString(),
            ));
            $month = $month->addMonth();
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS email_event_keys');
        DB::statement('DROP TABLE IF EXISTS email_events CASCADE');
    }
};
