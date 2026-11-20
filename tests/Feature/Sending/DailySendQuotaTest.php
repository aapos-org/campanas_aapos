<?php

use App\Models\MailConnection;
use App\Services\Sending\DailySendQuota;

it('reserves sends up to the daily limit and never beyond', function () {
    $connection = MailConnection::factory()->gmailSmtp(dailyLimit: 3)->create();
    $quota = app(DailySendQuota::class);

    expect($quota->reserve($connection, 2))->toBeTrue()
        ->and($quota->reserve($connection, 2))->toBeFalse()
        ->and($quota->reserve($connection))->toBeTrue()
        ->and($quota->reserve($connection))->toBeFalse()
        ->and($quota->usedToday($connection))->toBe(3)
        ->and($quota->remainingToday($connection))->toBe(0);

    $quota->release($connection);

    expect($quota->remainingToday($connection))->toBe(1);
});

it('starts a new count each day in the organization timezone', function () {
    $connection = MailConnection::factory()->gmailSmtp(dailyLimit: 1)->create();
    $quota = app(DailySendQuota::class);

    $this->travelTo(now('America/Guatemala')->setTime(23, 50));
    expect($quota->reserve($connection))->toBeTrue()->and($quota->reserve($connection))->toBeFalse();

    $this->travel(20)->minutes(); // past midnight in Guatemala
    expect($quota->reserve($connection))->toBeTrue();
});

it('does not limit connections without a daily limit', function () {
    $connection = MailConnection::factory()->create(['daily_limit' => null]);

    expect(app(DailySendQuota::class)->reserve($connection, 100000))->toBeTrue();
});
