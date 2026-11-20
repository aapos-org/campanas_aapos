<?php

use App\Enums\ContactStatus;
use App\Enums\Role;
use App\Models\Audience;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Subscription;

it('exports an audience filtered by status, with consent data, and audits it', function () {
    $admin = userWithRole(Role::Admin);
    actAsOrganization($admin->organization);
    $audience = Audience::factory()->create(['name' => 'Socios']);

    foreach (['a@example.com' => ContactStatus::Subscribed, 'b@example.com' => ContactStatus::Unsubscribed] as $email => $status) {
        $contact = Contact::factory()->create(['email' => $email, 'first_name' => '+cmd|calc']);
        Subscription::create(['audience_id' => $audience->id, 'contact_id' => $contact->id, 'status' => $status, 'consent_source' => 'form']);
    }

    $content = $this->actingAs($admin)
        ->get(route('audiences.export', ['audience' => $audience, 'status' => 'subscribed']))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('a@example.com')->not->toContain('b@example.com')
        ->toContain('origen_consentimiento')
        ->toContain("'+cmd|calc");

    expect(AuditLog::query()->where('event', 'contacts.exported')->exists())->toBeTrue();
});

it('forbids exports to read-only users', function () {
    $viewer = userWithRole(Role::Viewer);
    actAsOrganization($viewer->organization);

    $this->actingAs($viewer)->get(route('audiences.export', Audience::factory()->create()))->assertForbidden();
});
