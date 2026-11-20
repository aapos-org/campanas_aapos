<?php

use App\Enums\ContactStatus;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\MergeField;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Tag;

beforeEach(function () {
    $this->editor = userWithRole(Role::Editor);
    actAsOrganization($this->editor->organization);
    $this->audience = Audience::factory()->create();
});

it('lets editors create a contact as subscribed only with a consent statement', function () {
    MergeField::factory()->create(['key' => 'ciudad', 'label' => 'Ciudad', 'type' => FieldType::Select, 'options' => ['Guatemala', 'Antigua']]);

    $this->actingAs($this->editor)->post(route('contacts.store', $this->audience), [
        'email' => 'nueva@example.com',
        'status' => 'subscribed',
    ])->assertSessionHasErrors('consent_detail');

    $this->actingAs($this->editor)->post(route('contacts.store', $this->audience), [
        'email' => ' Nueva@Example.com',
        'first_name' => 'Nueva',
        'phone' => '2222-3333',
        'fields' => ['ciudad' => 'antigua'],
        'tags' => ['Socios'],
        'status' => 'subscribed',
        'consent_detail' => 'Ficha de inscripción firmada',
    ])->assertSessionHasNoErrors();

    $contact = Contact::query()->where('email', 'nueva@example.com')->sole();
    expect($contact->phone)->toBe('+50222223333')
        ->and($contact->fields['ciudad'])->toBe('Antigua')
        ->and($contact->tags->pluck('name')->all())->toBe(['Socios'])
        ->and(Subscription::query()->sole()->status)->toBe(ContactStatus::Subscribed);
});

it('rejects invalid custom field values on the contact form', function () {
    MergeField::factory()->create(['key' => 'nacimiento', 'label' => 'Nacimiento', 'type' => FieldType::Date]);

    $this->actingAs($this->editor)->post(route('contacts.store', $this->audience), [
        'email' => 'x@example.com',
        'fields' => ['nacimiento' => '31/02/2000'],
        'status' => 'non_subscribed',
    ])->assertSessionHasErrors('fields.nacimiento');
});

it('rejects duplicate emails in the organization', function () {
    Contact::factory()->create(['email' => 'dup@example.com']);

    $this->actingAs($this->editor)->post(route('contacts.store', $this->audience), [
        'email' => 'DUP@example.com',
        'status' => 'non_subscribed',
    ])->assertSessionHasErrors('email');
});

it('adds and removes tags in bulk only for contacts of that audience', function () {
    $inAudience = Contact::factory()->count(2)->create();
    foreach ($inAudience as $c) {
        Subscription::create(['audience_id' => $this->audience->id, 'contact_id' => $c->id, 'status' => ContactStatus::Subscribed]);
    }
    $outside = Contact::factory()->create();

    $this->actingAs($this->editor)->post(route('audiences.bulk', $this->audience), [
        'action' => 'add_tags',
        'contact_ids' => [...$inAudience->pluck('id')->all(), $outside->id],
        'tags' => ['VIP'],
    ])->assertSessionHasNoErrors();

    $vip = Tag::query()->where('name', 'VIP')->sole();
    expect($vip->contacts()->pluck('contacts.id')->sort()->values()->all())->toBe($inAudience->pluck('id')->sort()->values()->all());

    $this->actingAs($this->editor)->post(route('audiences.bulk', $this->audience), [
        'action' => 'remove_tags',
        'contact_ids' => $inAudience->pluck('id')->all(),
        'tags' => ['VIP'],
    ]);
    expect($vip->contacts()->count())->toBe(0);
});

it('lets read-only users view audiences and contacts but not change them', function () {
    $viewer = userWithRole(Role::Viewer, $this->editor->organization);
    $contact = Contact::factory()->create();

    $this->actingAs($viewer)->get(route('audiences.show', $this->audience))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false));
    $this->actingAs($viewer)->get(route('contacts.show', $contact))->assertOk();
    $this->actingAs($viewer)->post(route('contacts.store', $this->audience), ['email' => 'a@b.com', 'status' => 'non_subscribed'])->assertForbidden();
    $this->actingAs($viewer)->get(route('imports.create', $this->audience))->assertForbidden();
});

it('hides audiences and contacts of other organizations', function () {
    $foreignAudience = Audience::factory()->for(Organization::factory())->create();
    $foreignContact = Contact::factory()->for($foreignAudience->organization)->create();

    $this->actingAs($this->editor)->get(route('audiences.show', $foreignAudience))->assertNotFound();
    $this->actingAs($this->editor)->get(route('contacts.show', $foreignContact))->assertNotFound();
});

it('filters the contact list by status, tag and search', function () {
    $tag = Tag::factory()->create(['name' => 'Junta']);
    $a = Contact::factory()->create(['email' => 'ana@example.com']);
    $b = Contact::factory()->create(['email' => 'beto@example.com']);
    $a->tags()->attach($tag);
    Subscription::create(['audience_id' => $this->audience->id, 'contact_id' => $a->id, 'status' => ContactStatus::Subscribed]);
    Subscription::create(['audience_id' => $this->audience->id, 'contact_id' => $b->id, 'status' => ContactStatus::Unsubscribed]);

    $this->actingAs($this->editor)->get(route('audiences.show', [$this->audience, 'tag' => $tag->id]))
        ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)->where('subscriptions.data.0.email', 'ana@example.com')->where('counts.subscribed', 1));

    $this->actingAs($this->editor)->get(route('audiences.show', [$this->audience, 'status' => 'unsubscribed', 'search' => 'BETO']))
        ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)->where('subscriptions.data.0.email', 'beto@example.com'));
});
