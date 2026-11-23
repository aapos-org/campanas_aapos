<?php

use Illuminate\Support\Facades\Schedule;

// Scheduled campaigns (checked every minute).
Schedule::command('campaigns:dispatch-scheduled')->everyMinute()->withoutOverlapping();

// Monthly partitions of email_events, created ahead.
Schedule::command('events:partitions --months=3')->monthlyOn(1, '02:00');

// Horizon metrics snapshots.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
