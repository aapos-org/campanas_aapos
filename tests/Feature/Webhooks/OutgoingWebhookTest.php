<?php

use App\Enums\ContactStatus;
use App\Enums\EventType;
use App\Enums\Role;
use App\Jobs\DeliverWebhook;
use App\Models\Audience;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\EventRecorder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Campaigns\CampaignTestHelpers;

// A public IP literal: SafeUrl accepts it without DNS lookups.
const HOOK_URL = 'https://93.184.216.34/hooks/aapos';

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->org = $this->admin->organization;
    actAsOrganization($this->org);
});

function endpoint(array $attributes = []): WebhookEndpoint
{
    return WebhookEndpoint::query()->create($attributes + [
        'name' => 'CRM', 'url' => HOOK_URL, 'secret' => 'whsec_prueba', 'events' => ['contact.subscribed', 'contact.unsubscribed', 'email.clicked', 'email.bounced', 'campaign.sent'],
    ]);
}

it('sends signed subscription events that the receiver can verify', function () {
    Http::fake(['93.184.216.34/*' => Http::response('ok')]);
    endpoint();
    $contact = Contact::factory()->create(['email' => 'ana@example.com']);
    $audience = Audience::factory()->create(['name' => 'Boletín']);

    $subscription = Subscription::create(['audience_id' => $audience->id, 'contact_id' => $contact->id, 'status' => ContactStatus::Subscribed]);
    $subscription->update(['status' => ContactStatus::Unsubscribed]);

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) {
        [$t, $v1] = array_map(fn ($part) => explode('=', $part, 2)[1], explode(',', $request->header('X-AAPOS-Signature')[0]));

        return $request->header('X-AAPOS-Event')[0] === 'contact.subscribed'
            && hash_equals(hash_hmac('sha256', $t.'.'.$request->body(), 'whsec_prueba'), $v1)
            && $request['data']['contact']['email'] === 'ana@example.com'
            && $request['data']['audience']['name'] === 'Boletín';
    });
    expect(WebhookDelivery::query()->where('status', 'delivered')->count())->toBe(2);
});

it('notifies clicks, bounces and finished campaigns', function () {
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm']), '93.184.216.34/*' => Http::response('ok')]);
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);
    endpoint(['events' => ['email.clicked', 'email.bounced', 'campaign.sent']]);

    app(CampaignSender::class)->start($campaign);
    $recipient = CampaignRecipient::query()->sole();
    $link = $campaign->links()->create(['url' => 'https://amigosproobras.org/donar', 'hash' => 'h1']);
    app(EventRecorder::class)->record(EventType::Click, $recipient, linkId: $link->id);
    app(EventRecorder::class)->record(EventType::HardBounce, $recipient);

    expect(WebhookDelivery::query()->orderBy('id')->pluck('event')->all())->toBe(['campaign.sent', 'email.clicked', 'email.bounced'])
        ->and(WebhookDelivery::query()->where('event', 'email.clicked')->sole()->payload['data']['url'])->toBe('https://amigosproobras.org/donar')
        ->and(WebhookDelivery::query()->where('event', 'email.bounced')->sole()->payload['data']['bounce_type'])->toBe('hard');
});

it('refuses private network addresses and records failing responses', function () {
    Http::fake(['93.184.216.34/*' => Http::response('error', 500)]);
    $private = endpoint(['url' => 'http://127.0.0.1:8080/hook', 'events' => ['contact.subscribed']]);
    $failing = endpoint(['name' => 'Caído', 'events' => ['contact.subscribed']]);

    Subscription::create(['audience_id' => Audience::factory()->create()->id, 'contact_id' => Contact::factory()->create()->id, 'status' => ContactStatus::Subscribed]);

    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $private->id)->sole())
        ->status->toBe('failed')
        ->error->toBe('La URL no apunta a una dirección pública de internet.')
        ->and(WebhookDelivery::query()->where('webhook_endpoint_id', $failing->id)->sole())
        ->response_status->toBe(500)
        ->error->toBe('El destino respondió 500.');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '127.0.0.1'));
});

it('disables a destination after too many failures in a row', function () {
    $endpoint = endpoint(['url' => 'http://10.0.0.5/hook', 'consecutive_failures' => WebhookEndpoint::DISABLE_AFTER_FAILURES - 1]);
    $delivery = $endpoint->deliveries()->create(['event' => 'ping', 'payload' => ['event' => 'ping']]);

    DeliverWebhook::dispatchSync($delivery->id);

    expect($endpoint->refresh()->is_active)->toBeFalse();
});

it('manages destinations from the panel and sends a test ping', function () {
    Http::fake(['93.184.216.34/*' => Http::response('ok')]);

    $this->actingAs($this->admin)->post(route('admin.outgoing-webhooks.store'), ['name' => 'CRM', 'url' => HOOK_URL, 'events' => ['contact.subscribed']])
        ->assertSessionHasNoErrors();
    $endpoint = WebhookEndpoint::query()->sole();
    expect($endpoint->secret)->toStartWith('whsec_');

    $this->actingAs($this->admin)->post(route('admin.outgoing-webhooks.test', $endpoint))->assertRedirect();
    expect(WebhookDelivery::query()->sole())->event->toBe('ping')->status->toBe('delivered');

    $this->actingAs($this->admin)->get(route('admin.outgoing-webhooks.index'))
        ->assertInertia(fn ($page) => $page->has('endpoints', 1)->has('deliveries.data', 1));

    $this->actingAs(userWithRole(Role::Editor, $this->org))->get(route('admin.outgoing-webhooks.index'))->assertForbidden();
});
