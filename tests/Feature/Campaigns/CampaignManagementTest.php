<?php

use App\Enums\CampaignStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Template;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Campaigns\CampaignTestHelpers;

beforeEach(function () {
    $this->editor = userWithRole(Role::Editor);
    $this->org = $this->editor->organization;
    actAsOrganization($this->org);
});

it('sanitizes HTML saved by users', function () {
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);

    $this->actingAs($this->editor)->put(route('campaigns.content.update', $campaign), [
        'html' => '<html><body><p>Hola</p><script>alert(1)</script><a href="javascript:x()" onclick="y()">x</a></body></html>',
    ])->assertSessionHasNoErrors();

    expect($campaign->fresh()->html)->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript:');
});

it('requires typing ENVIAR and a passing checklist before sending', function () {
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm'])]);
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 2);

    $this->actingAs($this->editor)->post(route('campaigns.send', $campaign), ['confirmation' => 'si'])->assertSessionHasErrors('confirmation');

    $campaign->update(['subject' => '']);
    $this->actingAs($this->editor)->post(route('campaigns.send', $campaign), ['confirmation' => 'ENVIAR'])->assertSessionHasErrors('confirmation');
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);

    $campaign->update(['subject' => 'Hola']);
    $this->actingAs($this->editor)->post(route('campaigns.send', $campaign), ['confirmation' => 'enviar'])->assertRedirect(route('campaigns.show', $campaign));
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent);
});

it('schedules in the campaign timezone and the scheduler sends it when due', function () {
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm'])]);
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);
    $this->travelTo(now('America/Guatemala')->setTime(9, 0));

    $this->actingAs($this->editor)->post(route('campaigns.send', $campaign), [
        'confirmation' => 'ENVIAR',
        'scheduled_at' => now('America/Guatemala')->setTime(10, 30)->format('Y-m-d\TH:i'),
    ])->assertRedirect();

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled)
        ->and($campaign->fresh()->scheduled_at->setTimezone('America/Guatemala')->format('H:i'))->toBe('10:30');

    $this->artisan('campaigns:dispatch-scheduled');
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled);

    $this->travel(2)->hours();
    $this->artisan('campaigns:dispatch-scheduled');
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent);
});

it('sends a test email with real data of the contact, marked as test and without tracking', function () {
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm'])]);
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);

    $this->actingAs($this->editor)->post(route('campaigns.test', $campaign), ['emails' => 'persona1@example.com'])->assertSessionHasNoErrors();

    Http::assertSent(fn ($r) => str_starts_with($r['Content']['Subject'], '[Prueba] Novedades de Persona1')
        && ! str_contains($r['Content']['Body'][0]['Content'], '/t/o/'));
    expect($campaign->fresh()->last_test_sent_at)->not->toBeNull();
});

it('lets read-only users view campaigns but not send or edit them', function () {
    $viewer = userWithRole(Role::Viewer, $this->org);
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);

    $this->actingAs($viewer)->get(route('campaigns.index'))->assertOk();
    $this->actingAs($viewer)->get(route('campaigns.show', $campaign))->assertOk();
    $this->actingAs($viewer)->get(route('campaigns.edit', $campaign))->assertForbidden();
    $this->actingAs($viewer)->post(route('campaigns.send', $campaign), ['confirmation' => 'ENVIAR'])->assertForbidden();
});

it('serves previews with a script-free CSP, frameable only by the panel', function () {
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 1);

    $response = $this->actingAs($this->editor)->get(route('campaigns.preview', $campaign))->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->not->toContain('script-src')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});

it('allows inline styles only on the visual editor pages', function () {
    $template = Template::factory()->create(['editor' => 'visual', 'mjml' => '<mjml></mjml>']);

    $editorCsp = $this->actingAs($this->editor)->get(route('templates.edit', $template))->headers->get('Content-Security-Policy');
    $otherCsp = $this->actingAs($this->editor)->get(route('templates.index'))->headers->get('Content-Security-Policy');

    expect($editorCsp)->toContain("style-src 'self' 'unsafe-inline'")->not->toMatch('/script-src[^;]*unsafe/')
        ->and($otherCsp)->toMatch("/style-src 'self' 'nonce-[^']+';/");
});

it('creates templates from the library and duplicates historic campaigns as HTML drafts', function () {
    $this->actingAs($this->editor)->post(route('templates.store'), ['name' => 'Mi boletín', 'base' => 'boletin'])->assertRedirect();
    expect(Template::query()->sole()->mjml)->toContain('<mjml>')->toContain('{{ enlace_baja }}');

    $historic = Campaign::factory()->create(['is_historic' => true, 'status' => CampaignStatus::Sent, 'editor' => 'visual']);
    $this->actingAs($this->editor)->post(route('campaigns.duplicate', $historic))->assertRedirect();

    $copy = Campaign::query()->latest('id')->first();
    expect($copy->status)->toBe(CampaignStatus::Draft)->and($copy->is_historic)->toBeFalse()->and($copy->editor)->toBe('html');
});
