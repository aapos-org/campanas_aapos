<?php

use App\Enums\Role;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\LinkRegistry;
use App\Services\Campaigns\TrackingLinks;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Campaigns\CampaignTestHelpers;

beforeEach(function () {
    $admin = userWithRole(Role::Admin);
    actAsOrganization($admin->organization);
    ['campaign' => $this->campaign, 'contacts' => [$this->contact]] = CampaignTestHelpers::campaignWithSubscribers($admin->organization, 1);
    $this->campaign->update(['html' => '<a href="https://example.org/noticia?e={{ correo }}">Leer</a>']);
    app(LinkRegistry::class)->register($this->campaign->fresh());
    $this->recipient = CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'contact_id' => $this->contact->id, 'email' => $this->contact->email, 'token' => str_repeat('b', 32), 'status' => 'sent']);
    $this->link = CampaignLink::query()->sole();
    app(CurrentOrganization::class)->set(null);
});

it('records opens and always answers with a GIF', function () {
    $links = app(TrackingLinks::class);

    $this->get($links->openUrl($this->recipient))->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->get(str_replace('/t/o/'.str_repeat('b', 32).'/', '/t/o/'.str_repeat('b', 32).'/0', substr($links->openUrl($this->recipient), 0, -5)).'.gif');

    expect(DB::table('email_events')->where('type', 'open')->count())->toBe(1);
});

it('redirects clicks to the stored URL with variables resolved, and records them', function () {
    $this->get(app(TrackingLinks::class)->clickUrl($this->recipient, $this->link->hash))
        ->assertRedirect('https://example.org/noticia?e=persona1%40example.com');

    $event = DB::table('email_events')->where('type', 'click')->sole();
    expect($event->campaign_link_id)->toBe($this->link->id)->and($event->contact_id)->toBe($this->contact->id);
});

it('rejects tampered click links (no open redirect)', function () {
    $url = app(TrackingLinks::class)->clickUrl($this->recipient, $this->link->hash);

    $this->get(substr($url, 0, -1).(str_ends_with($url, 'a') ? 'b' : 'a'))->assertNotFound();
    $this->get(str_replace($this->link->hash, str_repeat('0', 16), $url))->assertNotFound();
    expect(DB::table('email_events')->count())->toBe(0);
});
