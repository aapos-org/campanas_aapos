<?php

use App\Enums\ContactStatus;
use App\Enums\Role;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\Subscription;

it('feeds the bento dashboard with a 30-day series, comparable KPIs and recent activity', function () {
    $admin = userWithRole(Role::Admin);
    actAsOrganization($admin->organization);
    $audience = Audience::factory()->create(['name' => 'Boletín']);
    $contact = Contact::factory()->create(['email' => 'ana@example.com']);
    Subscription::create(['audience_id' => $audience->id, 'contact_id' => $contact->id, 'status' => ContactStatus::Subscribed, 'subscribed_at' => now()->subDays(2)]);

    $this->actingAs($admin)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->has('series', 30)
        ->where('kpis.subscribed.value', 1)
        ->where('kpis.subscribed.previous', 0)
        ->where('activity.0.title', 'ana@example.com')
        ->where('activity.0.detail', 'Se suscribió a Boletín')
        ->has('greeting'));
});
