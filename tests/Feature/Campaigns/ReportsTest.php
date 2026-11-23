<?php

use App\Enums\CampaignStatus;
use App\Enums\EventType;
use App\Enums\RecipientStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\EventRecorder;
use App\Services\Campaigns\RecipientFreezer;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Campaigns\CampaignTestHelpers;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    actAsOrganization($this->admin->organization);
    ['campaign' => $this->campaign] = CampaignTestHelpers::campaignWithSubscribers($this->admin->organization, 3);
    app(RecipientFreezer::class)->freeze($this->campaign);
    CampaignRecipient::query()->update(['status' => RecipientStatus::Sent, 'sent_at' => now()]);
    $this->campaign->update(['status' => CampaignStatus::Sent, 'sent_count' => 3, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);

    [$r1, $r2] = CampaignRecipient::query()->orderBy('email')->get()->all();
    $link = CampaignLink::query()->create(['campaign_id' => $this->campaign->id, 'url' => 'https://example.org/a', 'hash' => CampaignLink::hashFor('https://example.org/a')]);
    $events = app(EventRecorder::class);
    $events->record(EventType::Open, $r1);
    $events->record(EventType::Open, $r1);
    $events->record(EventType::Click, $r1, linkId: $link->id);
    $events->record(EventType::HardBounce, $r2);
});

it('shows campaign metrics, links, timeline and recipients with activity filters', function () {
    $this->actingAs($this->admin)->get(route('campaigns.show', $this->campaign))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.unique_opens', 1)
            ->where('stats.opens', 2)
            ->where('stats.unique_clicks', 1)
            ->where('stats.hard_bounces', 1)
            ->where('links.0.clicks', 1)
            ->has('timeline.points', 1)
            ->where('timeline.points.0.opens', 2)
            ->has('recipients.data', 3));

    $this->actingAs($this->admin)->get(route('campaigns.show', [$this->campaign, 'activity' => 'clicked']))
        ->assertInertia(fn (Assert $page) => $page->has('recipients.data', 1)->where('recipients.data.0.email', 'persona1@example.com')->where('recipients.data.0.opens', 2));

    $this->actingAs($this->admin)->get(route('campaigns.show', [$this->campaign, 'activity' => 'not_opened']))
        ->assertInertia(fn (Assert $page) => $page->has('recipients.data', 2));

    $this->actingAs($this->admin)->get(route('campaigns.show', [$this->campaign, 'activity' => 'bounced']))
        ->assertInertia(fn (Assert $page) => $page->where('recipients.data.0.bounce', 'duro'));
});

it('exports the recipient activity as CSV and audits it', function () {
    $response = $this->actingAs($this->admin)->get(route('campaigns.export', $this->campaign));

    $csv = $response->assertOk()->streamedContent();
    expect($csv)->toContain('correo,nombre')
        ->toContain('persona1@example.com')
        ->and(substr_count(trim($csv), "\n"))->toBe(3)
        ->and(DB::table('audit_logs')->where('event', 'campaigns.report_exported')->count())->toBe(1);
});

it('shows the overview report with growth and campaign comparison', function () {
    Campaign::factory()->create([
        'status' => CampaignStatus::Sent, 'is_historic' => true, 'finished_at' => now()->subYear(),
        'report_summary' => ['emails_sent' => 200, 'opens' => ['unique_opens' => 50], 'clicks' => ['unique_subscriber_clicks' => 10], 'unsubscribed' => 2],
    ]);

    $this->actingAs(userWithRole(Role::Viewer, $this->admin->organization))->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->has('growth', 12)
            ->where('totals.subscribed', 3)
            ->has('campaigns', 2)
            ->where('campaigns.0.open_rate', 33.3)
            ->where('campaigns.1.open_rate', 25));
});

it('previews historic campaigns exactly as Mailchimp sent them', function () {
    $historic = Campaign::factory()->create(['status' => CampaignStatus::Sent, 'is_historic' => true, 'html' => '<p>Boletín histórico</p>']);

    $this->actingAs($this->admin)->get(route('campaigns.preview', $historic))
        ->assertOk()->assertSee('Boletín histórico', false)->assertDontSee('enlace_baja');
});

it('shows the campaign history with activity on the contact profile', function () {
    $recipient = CampaignRecipient::query()->where('email', 'persona1@example.com')->sole();

    $this->actingAs($this->admin)->get(route('contacts.show', $recipient->contact_id))
        ->assertInertia(fn (Assert $page) => $page
            ->has('campaigns', 1)
            ->where('campaigns.0.campaign_id', $this->campaign->id)
            ->where('campaigns.0.opens', 2)
            ->where('campaigns.0.clicks', 1));

    $bounced = CampaignRecipient::query()->where('email', 'persona2@example.com')->sole();
    $this->actingAs($this->admin)->get(route('contacts.show', $bounced->contact_id))
        ->assertInertia(fn (Assert $page) => $page->where('campaigns.0.issues', ['Rebote duro']));
});
