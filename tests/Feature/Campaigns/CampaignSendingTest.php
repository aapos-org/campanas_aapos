<?php

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\RecipientStatus;
use App\Enums\Role;
use App\Enums\SuppressionReason;
use App\Jobs\SendCampaignBatch;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\Tag;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\RecipientFreezer;
use App\Services\Contacts\SuppressionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Campaigns\CampaignTestHelpers;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->org = $this->admin->organization;
    actAsOrganization($this->org);
});

function sentTo(): array
{
    return Http::recorded()
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'emails/transactional'))
        ->map(fn ($pair) => $pair[0]['Recipients']['To'][0])
        ->values()->all();
}

it('sends once to every subscribed, non-suppressed contact and completes the campaign', function () {
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm', 'TransactionID' => 't'])]);
    ['campaign' => $campaign, 'audience' => $audience] = CampaignTestHelpers::campaignWithSubscribers($this->org, 4);

    // One unsubscribed member, one globally bounced, one in another audience: none receive it.
    $unsub = Contact::factory()->create(['email' => 'baja@example.com']);
    Subscription::create(['audience_id' => $audience->id, 'contact_id' => $unsub->id, 'status' => ContactStatus::Unsubscribed]);
    app(SuppressionService::class)->suppress('persona4@example.com', SuppressionReason::HardBounce);

    app(CampaignSender::class)->start($campaign);

    $campaign->refresh();
    expect(sentTo())->toEqualCanonicalizing(['persona1@example.com', 'persona2@example.com', 'persona3@example.com'])
        ->and($campaign->status)->toBe(CampaignStatus::Sent)
        ->and($campaign->recipients_count)->toBe(3)
        ->and($campaign->sent_count)->toBe(3);

    // Every send carries a stable idempotency key and the RFC 8058 headers.
    Http::assertSent(fn (Request $r) => str_starts_with($r->header('Idempotency-Key')[0] ?? '', 'send-cr-')
        && $r['Content']['Headers']['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click');
});

it('never sends twice to the same recipient when a batch job runs again', function () {
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm'])]);
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 3);

    app(CampaignSender::class)->start($campaign);
    $ids = CampaignRecipient::query()->pluck('id')->all();

    // Simulates a duplicate delivery of the same job after the campaign is reopened.
    Campaign::query()->whereKey($campaign->id)->update(['status' => 'sending']);
    dispatch_sync(new SendCampaignBatch($campaign->id, $ids));

    expect(sentTo())->toHaveCount(3);
});

it('only sends to contacts with the selected tags', function () {
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm'])]);
    ['campaign' => $campaign, 'contacts' => $contacts] = CampaignTestHelpers::campaignWithSubscribers($this->org, 3);
    $vip = Tag::factory()->create(['name' => 'VIP']);
    $contacts[1]->tags()->attach($vip);
    $campaign->update(['tag_ids' => [$vip->id]]);

    app(CampaignSender::class)->start($campaign);

    expect(sentTo())->toBe(['persona2@example.com']);
});

it('pauses mid-send, keeps the rest pending, and resumes without resending', function () {
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 4);
    $calls = 0;
    Http::fake(function () use ($campaign, &$calls) {
        if (++$calls === 2) {
            Campaign::query()->whereKey($campaign->id)->update(['status' => 'paused']);
        }

        return Http::response(['MessageID' => 'm'.$calls]);
    });

    app(CampaignSender::class)->start($campaign);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and(CampaignRecipient::query()->where('status', 'sent')->count())->toBe(2)
        ->and(CampaignRecipient::query()->where('status', 'pending')->count())->toBe(2);

    app(CampaignSender::class)->resume($campaign->fresh());

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent)
        ->and(sentTo())->toHaveCount(4)
        ->and(array_unique(sentTo()))->toHaveCount(4);
});

it('pauses before exceeding the daily limit of a Gmail connection', function () {
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 3, ['daily_limit' => 2]);
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm'])]);

    app(CampaignSender::class)->start($campaign);

    $campaign->refresh();
    expect(sentTo())->toHaveCount(2)
        ->and($campaign->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->status_reason)->toContain('límite diario')
        ->and(CampaignRecipient::query()->where('status', 'pending')->count())->toBe(1);
});

it('puts recipients back as pending on temporary errors and fails them on permanent ones', function () {
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 2);
    Http::fake(function (Request $r) {
        return $r['Recipients']['To'][0] === 'persona1@example.com'
            ? Http::response(['Error' => 'Invalid recipient'], 400)
            : Http::response(['Error' => 'busy'], 503);
    });

    app(CampaignSender::class)->start($campaign);

    expect(CampaignRecipient::query()->where('email', 'persona1@example.com')->value('status'))->toBe(RecipientStatus::Failed)
        ->and(CampaignRecipient::query()->where('email', 'persona2@example.com')->value('status'))->toBe(RecipientStatus::Pending)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sending);
});

it('skips people who unsubscribed after the list was frozen', function () {
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm'])]);
    ['campaign' => $campaign, 'audience' => $audience] = CampaignTestHelpers::campaignWithSubscribers($this->org, 2);

    app(RecipientFreezer::class)->freeze($campaign);
    app(SuppressionService::class)->suppress('persona1@example.com', SuppressionReason::Unsubscribed, $audience);
    $campaign->update(['status' => 'sending']);

    dispatch_sync(new SendCampaignBatch($campaign->id, CampaignRecipient::query()->pluck('id')->all()));

    expect(sentTo())->toBe(['persona2@example.com'])
        ->and(CampaignRecipient::query()->where('email', 'persona1@example.com')->value('status'))->toBe(RecipientStatus::Skipped);
});

it('cancels: pending recipients are skipped and nothing else is sent', function () {
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 3);
    app(RecipientFreezer::class)->freeze($campaign);
    $campaign->update(['status' => 'sending']);

    app(CampaignSender::class)->cancel($campaign);
    Http::fake();
    dispatch_sync(new SendCampaignBatch($campaign->id, CampaignRecipient::query()->pluck('id')->all()));

    Http::assertNothingSent();
    expect(CampaignRecipient::query()->where('status', 'skipped')->count())->toBe(3)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Cancelled);
});
