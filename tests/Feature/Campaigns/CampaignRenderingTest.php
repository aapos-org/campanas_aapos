<?php

use App\Enums\Role;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\ContentPreparer;
use Tests\Feature\Campaigns\CampaignTestHelpers;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->org = $this->admin->organization;
    actAsOrganization($this->org);
});

it('personalizes, escapes values, adds preheader, mandatory footer and unsubscribe headers', function () {
    ['campaign' => $campaign, 'audience' => $audience, 'contacts' => [$contact]] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);
    $contact->update(['first_name' => '<b>Ana</b>', 'last_name' => null, 'fields' => ['ciudad' => 'Antigua']]);
    $campaign->update(['html' => app(ContentPreparer::class)->prepare('<html><body><p>Hola {{ nombre }} de {{ ciudad }} {{ apellido | "sin apellido" }}</p><a href="https://example.org/x?a=1&b=2">Leer</a></body></html>')]);
    $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'email' => $contact->email, 'token' => str_repeat('a', 32)]);

    $email = app(CampaignRenderer::class)->render($campaign->fresh(), $this->org, $audience, $recipient, $contact->fresh());

    expect($email->html)
        ->toContain('Hola &lt;b&gt;Ana&lt;/b&gt; de Antigua sin apellido')
        ->toContain('Lo más importante del mes')                    // preheader
        ->toContain('5a Avenida 1-23, Ciudad de Guatemala')         // mandatory footer with address
        ->toContain('/baja/'.$contact->id.'/'.$audience->id.'/')     // unsubscribe link
        ->toContain('/t/o/'.str_repeat('a', 32).'/')                 // open pixel
        ->toContain('/t/c/'.str_repeat('a', 32).'/')                 // tracked click
        ->not->toContain('href="https://example.org/x');

    expect($email->subject)->toBe('Novedades de <b>Ana</b>')
        ->and($email->headers['List-Unsubscribe'])->toStartWith('<http')
        ->and($email->headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($email->text)->toContain('Hola <b>Ana</b>');
});

it('does not add a second footer when the template already has the unsubscribe link', function () {
    ['campaign' => $campaign, 'audience' => $audience, 'contacts' => [$contact]] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);
    $campaign->update(['html' => '<html><body><p>Hola</p><a href="{{ enlace_baja }}">Salir</a></body></html>', 'track_clicks' => false, 'track_opens' => false]);

    $html = app(CampaignRenderer::class)->render($campaign->fresh(), $this->org, $audience, null, $contact)->html;

    expect(substr_count($html, '/baja/'))->toBe(1)->and($html)->not->toContain('Darme de baja');
});

it('accepts fallback text in single quotes, as written by the visual editor', function () {
    ['campaign' => $campaign, 'audience' => $audience] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);
    $campaign->update(['html' => "<html><body><p>Hola {{ nombre | 'amigo' }}</p></body></html>", 'track_opens' => false]);
    $contact = Contact::factory()->create(['first_name' => null]);

    expect(app(CampaignRenderer::class)->render($campaign->fresh(), $this->org, $audience, null, $contact)->html)->toContain('Hola amigo');
});

it('forces a full page load when opening the visual editor from the app', function () {
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);

    $this->actingAs($this->admin)->get(route('campaigns.content', $campaign), ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});
