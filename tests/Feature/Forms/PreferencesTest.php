<?php

use App\Enums\ContactStatus;
use App\Enums\Role;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\Suppression;
use App\Services\Forms\PublicLinks;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    actAsOrganization($this->admin->organization);
    $this->a = Audience::factory()->create(['name' => 'Boletín']);
    $this->b = Audience::factory()->create(['name' => 'Eventos']);
    $this->contact = Contact::factory()->create(['email' => 'ana@example.com']);
    foreach ([$this->a, $this->b] as $audience) {
        Subscription::create(['audience_id' => $audience->id, 'contact_id' => $this->contact->id, 'status' => ContactStatus::Subscribed]);
    }
    $this->links = app(PublicLinks::class);
    $this->unsubscribeUrl = $this->links->unsubscribe($this->contact, $this->a);
    $this->preferencesUrl = $this->links->preferences($this->contact);
    app(CurrentOrganization::class)->set(null);
});

function statusIn(Audience $audience, Contact $contact): ContactStatus
{
    return Subscription::query()->withoutGlobalScopes()->where('audience_id', $audience->id)->where('contact_id', $contact->id)->sole()->status;
}

it('does not unsubscribe on GET (mail scanners follow links)', function () {
    $this->get($this->unsubscribeUrl)->assertOk()->assertSee('¿Darte de baja?');

    expect(statusIn($this->a, $this->contact))->toBe(ContactStatus::Subscribed);
});

it('supports RFC 8058 one-click unsubscribe by POST without CSRF token', function () {
    $this->post($this->unsubscribeUrl, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(statusIn($this->a, $this->contact))->toBe(ContactStatus::Unsubscribed)
        ->and(statusIn($this->b, $this->contact))->toBe(ContactStatus::Subscribed)
        ->and(Suppression::query()->withoutGlobalScopes()->sole()->audience_id)->toBe($this->a->id);
});

it('lets the person unsubscribe from one audience or from everything', function () {
    $this->get($this->preferencesUrl)->assertOk()->assertSee('Boletín')->assertSee('Eventos');

    $action = str_replace('/preferencias/', '/preferencias/', $this->preferencesUrl);
    $this->post($action, ['audience' => $this->b->id])->assertRedirect();
    expect(statusIn($this->b, $this->contact))->toBe(ContactStatus::Unsubscribed)
        ->and(statusIn($this->a, $this->contact))->toBe(ContactStatus::Subscribed);

    $this->post($action, ['all' => 1])->assertRedirect();
    expect(statusIn($this->a, $this->contact))->toBe(ContactStatus::Unsubscribed)
        ->and(Suppression::query()->withoutGlobalScopes()->whereNull('audience_id')->where('email', 'ana@example.com')->exists())->toBeTrue();
});

it('rejects tampered or foreign preference links', function () {
    // Another contact id with the same signature, and a wrong email hash.
    $this->get(preg_replace('#/preferencias/\d+/#', '/preferencias/999/', $this->preferencesUrl))->assertForbidden();
    $otherHash = URL::signedRoute('preferences.show', ['contact' => $this->contact->id, 'hash' => str_repeat('0', 32)]);
    $this->get($otherHash)->assertForbidden();
    $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=00', $this->preferencesUrl))->assertForbidden();
});
