<?php

use App\Enums\ContactStatus;
use App\Enums\Role;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\Suppression;
use App\Services\Contacts\SubscriptionManager;
use App\Services\Contacts\SuppressionService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    actAsOrganization($this->admin->organization);
    $this->audience = Audience::factory()->create();
    $this->other = Audience::factory()->create();
    $this->contact = Contact::factory()->create(['email' => 'ana@example.com']);
    $this->manager = app(SubscriptionManager::class);
    $this->service = app(SuppressionService::class);
});

it('makes hard bounces global and cleans every subscription', function () {
    $this->manager->setStatus($this->contact, $this->audience, ContactStatus::Subscribed, ['source' => 'manual']);
    $this->manager->setStatus($this->contact, $this->other, ContactStatus::Subscribed, ['source' => 'manual']);

    // Even if an audience is passed, a hard bounce is global.
    $this->service->suppress('ANA@example.com ', SuppressionReason::HardBounce, $this->audience);

    expect(Suppression::query()->sole()->audience_id)->toBeNull()
        ->and(Subscription::query()->pluck('status')->unique()->all())->toBe([ContactStatus::Cleaned]);
});

it('limits an unsubscribe to its audience', function () {
    $this->manager->setStatus($this->contact, $this->audience, ContactStatus::Subscribed, ['source' => 'manual']);
    $this->manager->setStatus($this->contact, $this->other, ContactStatus::Subscribed, ['source' => 'manual']);

    $this->manager->setStatus($this->contact, $this->audience, ContactStatus::Unsubscribed);

    expect($this->service->isSuppressed('ana@example.com', $this->audience->id))->toBeTrue()
        ->and($this->service->isSuppressed('ana@example.com', $this->other->id))->toBeFalse()
        ->and(Subscription::query()->where('audience_id', $this->other->id)->value('status'))->toBe(ContactStatus::Subscribed);
});

it('never re-subscribes a suppressed or unsubscribed contact from staff edits', function () {
    $this->manager->setStatus($this->contact, $this->audience, ContactStatus::Subscribed, ['source' => 'manual']);
    $this->manager->setStatus($this->contact, $this->audience, ContactStatus::Unsubscribed);

    expect(fn () => $this->manager->setStatus($this->contact, $this->audience, ContactStatus::Subscribed, ['source' => 'manual']))
        ->toThrow(ValidationException::class);

    expect(Subscription::query()->where('audience_id', $this->audience->id)->value('status'))->toBe(ContactStatus::Unsubscribed);
});

it('blocks subscribing an address that complained, in any audience', function () {
    $this->service->suppress('ana@example.com', SuppressionReason::Complaint);

    expect(fn () => $this->manager->setStatus($this->contact, $this->other, ContactStatus::Subscribed, ['source' => 'manual']))
        ->toThrow(ValidationException::class, 'supresión global');
});

it('requires a consent source to mark someone as subscribed', function () {
    expect(fn () => $this->manager->setStatus($this->contact, $this->audience, ContactStatus::Subscribed))
        ->toThrow(ValidationException::class, 'consentimiento');

    $subscription = $this->manager->setStatus($this->contact, $this->audience, ContactStatus::Subscribed, ['source' => 'manual', 'detail' => 'Formulario firmado']);

    expect($subscription->consent_at)->not->toBeNull()
        ->and($subscription->consent_source)->toBe('manual')
        ->and($subscription->consent_detail)->toBe('Formulario firmado');
});

it('only lets administrators lift manual suppressions', function () {
    $manual = $this->service->suppress('x@example.com', SuppressionReason::Manual);
    $bounce = $this->service->suppress('y@example.com', SuppressionReason::HardBounce);

    $this->actingAs($this->admin)->delete(route('suppressions.destroy', $bounce))->assertSessionHasErrors('suppression');
    $this->actingAs($this->admin)->delete(route('suppressions.destroy', $manual))->assertSessionHasNoErrors();

    expect(Suppression::query()->pluck('email')->all())->toBe(['y@example.com']);
});

it('adds manual suppressions in bulk from a pasted list', function () {
    $this->actingAs($this->admin)->post(route('suppressions.store'), [
        'emails' => "uno@example.com, DOS@example.com;\ntres@example.com uno@example.com",
    ])->assertSessionHasNoErrors();

    expect(Suppression::query()->orderBy('email')->pluck('email')->all())->toBe(['dos@example.com', 'tres@example.com', 'uno@example.com']);
});
