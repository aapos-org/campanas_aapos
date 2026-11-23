<?php

use App\Enums\ContactStatus;
use App\Enums\RecipientStatus;
use App\Enums\Role;
use App\Enums\SuppressionReason;
use App\Models\CampaignRecipient;
use App\Models\Subscription;
use App\Models\Suppression;
use App\Models\WebhookCall;
use App\Services\Campaigns\RecipientFreezer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Campaigns\CampaignTestHelpers;

beforeEach(function () {
    config(['services.elasticemail.webhook_token' => 'secreto-de-prueba']);
    $admin = userWithRole(Role::Admin);
    actAsOrganization($admin->organization);
    ['campaign' => $this->campaign, 'audience' => $this->audience] = CampaignTestHelpers::campaignWithSubscribers($admin->organization, 2);
    app(RecipientFreezer::class)->freeze($this->campaign);
    CampaignRecipient::query()->update(['status' => RecipientStatus::Sent, 'sent_at' => now()]);
    $this->r1 = CampaignRecipient::query()->where('email', 'persona1@example.com')->sole();
    $this->r1->update(['message_id' => 'MSG-1', 'provider_id' => 'TX-1']);
    app(CurrentOrganization::class)->set(null);
});

function hook(array $payload, string $method = 'post')
{
    $url = route('webhooks.elasticemail', ['token' => 'secreto-de-prueba']);

    return $method === 'get' ? test()->get($url.'?'.http_build_query($payload)) : test()->postJson($url, $payload);
}

function subscriptionStatus1(): ContactStatus
{
    return Subscription::query()->withoutGlobalScopes()->whereKey(test()->r1->subscription_id)->sole()->status;
}

it('rejects requests without the secret token', function () {
    $this->postJson(route('webhooks.elasticemail', ['token' => 'otro']), ['status' => 'Sent'])->assertNotFound();
    expect(WebhookCall::query()->count())->toBe(0);
});

it('turns a hard bounce into a global suppression (POST JSON, matched by message id)', function () {
    hook(['Status' => 'Error', 'Category' => 'NoMailbox', 'MsgID' => 'MSG-1', 'To' => 'persona1@example.com', 'Date' => now()->toIso8601String()])->assertOk();

    expect(Suppression::query()->withoutGlobalScopes()->where('email', 'persona1@example.com')->sole())
        ->reason->toBe(SuppressionReason::HardBounce)
        ->audience_id->toBeNull()
        ->and(subscriptionStatus1())->toBe(ContactStatus::Cleaned)
        ->and(DB::table('email_events')->where('type', 'bounce_hard')->count())->toBe(1)
        ->and(WebhookCall::query()->sole()->status)->toBe('processed');
});

it('accepts GET notifications and complaints become global suppressions', function () {
    hook(['status' => 'AbuseReport', 'transaction' => 'TX-1', 'to' => 'persona1@example.com'], 'get')->assertOk();

    expect(Suppression::query()->withoutGlobalScopes()->sole()->reason)->toBe(SuppressionReason::Complaint);
});

it('applies the same notification only once', function () {
    $payload = ['status' => 'Unsubscribed', 'messageid' => 'MSG-1', 'to' => 'persona1@example.com', 'date' => '2026-11-20T10:00:00Z'];
    $this->travelTo('2026-11-21 00:00:00');

    hook($payload);
    hook($payload);

    expect(DB::table('email_events')->where('type', 'unsubscribe')->count())->toBe(1)
        ->and(Suppression::query()->withoutGlobalScopes()->sole()->audience_id)->toBe($this->audience->id)
        ->and(WebhookCall::query()->latest('id')->first()->result)->toContain('duplicado');
});

it('cleans the address after repeated soft bounces, and a delivery resets the counter', function () {
    foreach (range(1, 4) as $i) {
        hook(['status' => 'Error', 'category' => 'Throttled', 'messageid' => 'MSG-1', 'date' => now()->addMinutes($i)->toIso8601String()]);
    }
    hook(['status' => 'Sent', 'messageid' => 'MSG-1', 'date' => now()->addMinutes(10)->toIso8601String()]);
    hook(['status' => 'Error', 'category' => 'Throttled', 'messageid' => 'MSG-1', 'date' => now()->addMinutes(11)->toIso8601String()]);

    expect(subscriptionStatus1())->toBe(ContactStatus::Subscribed)
        ->and(Suppression::query()->withoutGlobalScopes()->count())->toBe(0);

    foreach (range(20, 23) as $i) {
        hook(['status' => 'Error', 'category' => 'Timeout', 'messageid' => 'MSG-1', 'date' => now()->addMinutes($i)->toIso8601String()]);
    }

    expect(Suppression::query()->withoutGlobalScopes()->sole()->reason)->toBe(SuppressionReason::Cleaned)
        ->and(subscriptionStatus1())->toBe(ContactStatus::Cleaned);
});

it('ignores provider opens and clicks (the app tracks them itself) and stores the raw call', function () {
    hook(['status' => 'Opened', 'messageid' => 'MSG-1']);
    hook([['status' => 'Clicked', 'messageid' => 'MSG-1'], ['status' => 'Sent', 'messageid' => 'MSG-1']]);

    expect(DB::table('email_events')->whereIn('type', ['open', 'click'])->count())->toBe(0)
        ->and(DB::table('email_events')->where('type', 'delivered')->count())->toBe(1)
        ->and(WebhookCall::query()->count())->toBe(2)
        ->and(WebhookCall::query()->oldest('id')->first()->status)->toBe('ignored');
});

it('ignores replayed notifications older than 30 days', function () {
    hook(['status' => 'Error', 'category' => 'NoMailbox', 'messageid' => 'MSG-1', 'date' => now()->subDays(45)->toIso8601String()]);

    expect(Suppression::query()->withoutGlobalScopes()->count())->toBe(0);
});
