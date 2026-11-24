<?php

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\Role;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use App\Services\Contacts\SuppressionService;
use App\Support\Tenancy\CurrentOrganization;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->org = $this->admin->organization;
    actAsOrganization($this->org);
    $this->audience = Audience::factory()->create(['name' => 'Boletín']);
});

function apiToken(User $user, array $abilities): string
{
    return $user->createToken('Pruebas', $abilities)->plainTextToken;
}

function api(string $token): object
{
    // A fresh request context per call: the API sets the tenant from the token owner.
    app(CurrentOrganization::class)->set(null);

    return test()->withToken($token)->withHeader('Accept', 'application/json');
}

it('rejects missing tokens and tokens without the ability, in Spanish', function () {
    $this->getJson('/api/v1/contacts')->assertUnauthorized()->assertJson(['message' => 'No autenticado: falta el token o no es válido.']);

    api(apiToken($this->admin, ['audiences:read']))->getJson('/api/v1/contacts')
        ->assertForbidden()->assertJson(['message' => 'El token no tiene el permiso «contacts:read».']);
});

it('lists audiences with counts and the available custom fields', function () {
    $contact = Contact::factory()->create();
    Subscription::create(['audience_id' => $this->audience->id, 'contact_id' => $contact->id, 'status' => ContactStatus::Subscribed]);

    api(apiToken($this->admin, ['audiences:read']))->getJson('/api/v1/audiences')
        ->assertOk()->assertJsonPath('data.0.name', 'Boletín')->assertJsonPath('data.0.counts.subscribed', 1)->assertJsonStructure(['fields']);
});

it('upserts contacts by email, validates in Spanish and filters the list', function () {
    $token = apiToken($this->admin, ['contacts:read', 'contacts:write']);

    api($token)->postJson('/api/v1/contacts', ['email' => ' Ana@Example.com', 'first_name' => 'Ana', 'tags' => ['Web']])
        ->assertCreated()->assertJsonPath('data.email', 'ana@example.com')->assertJsonPath('data.tags', ['Web']);
    api($token)->postJson('/api/v1/contacts', ['email' => 'ana@example.com', 'last_name' => 'López'])
        ->assertOk()->assertJsonPath('data.last_name', 'López');
    api($token)->postJson('/api/v1/contacts', ['email' => 'no-es-correo'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', fn ($m) => str_contains($m, 'correo'));

    expect(Contact::query()->count())->toBe(1);

    api($token)->getJson('/api/v1/contacts?tag=Web')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    api($token)->getJson('/api/v1/contacts?tag=Otra')->assertOk()->assertJsonCount(0, 'data');
});

it('subscribes with consent evidence and never reactivates suppressed addresses', function () {
    $token = apiToken($this->admin, ['subscriptions:write']);

    api($token)->postJson("/api/v1/audiences/{$this->audience->id}/subscribe", ['email' => 'beto@example.com', 'consent_detail' => 'Formulario del sitio web'])
        ->assertOk()->assertJsonPath('data.subscriptions.0.status', 'subscribed');

    $subscription = Subscription::query()->sole();
    expect($subscription->consent_source)->toBe('api')->and($subscription->consent_detail)->toBe('Formulario del sitio web');

    api($token)->postJson("/api/v1/audiences/{$this->audience->id}/unsubscribe", ['email' => 'beto@example.com'])
        ->assertOk()->assertJsonPath('data.subscriptions.0.status', 'unsubscribed');

    api($token)->postJson("/api/v1/audiences/{$this->audience->id}/subscribe", ['email' => 'beto@example.com', 'consent_detail' => 'Otra vez'])
        ->assertUnprocessable()->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'se dio de baja'));

    app(SuppressionService::class)->suppress('rebota@example.com', SuppressionReason::HardBounce);
    api($token)->postJson("/api/v1/audiences/{$this->audience->id}/subscribe", ['email' => 'rebota@example.com', 'consent_detail' => 'Sitio'])
        ->assertUnprocessable();
});

it('adds and removes tags and exposes campaign statistics', function () {
    $contact = Contact::factory()->create();
    $token = apiToken($this->admin, ['contacts:write', 'tags:read', 'campaigns:read']);

    api($token)->postJson("/api/v1/contacts/{$contact->id}/tags", ['tags' => ['Donante', 'VIP']])->assertOk()->assertJsonCount(2, 'data.tags');
    $tag = Tag::query()->where('name', 'VIP')->sole();
    api($token)->deleteJson("/api/v1/contacts/{$contact->id}/tags/{$tag->id}")->assertOk()->assertJsonPath('data.tags', ['Donante']);
    api($token)->getJson('/api/v1/tags')->assertOk()->assertJsonPath('data.0.name', 'Donante');

    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sent, 'sent_count' => 10]);
    api($token)->getJson("/api/v1/campaigns/{$campaign->id}")->assertOk()->assertJsonPath('data.stats.sent', 10)->assertJsonPath('data.status', 'sent');
});

it('keeps every organization isolated and hides internal details on 404', function () {
    $other = Organization::factory()->create();
    $foreign = app(CurrentOrganization::class)->runAs($other, fn () => Contact::factory()->create());

    api(apiToken($this->admin, ['contacts:read']))->getJson("/api/v1/contacts/{$foreign->id}")
        ->assertNotFound()->assertExactJson(['message' => 'No encontrado.']);
});

it('stops tokens of deactivated users and of roles that lost the permission', function () {
    $editor = userWithRole(Role::Viewer, $this->org);
    $token = apiToken($editor, ['contacts:write']);

    api($token)->postJson('/api/v1/contacts', ['email' => 'x@example.com'])
        ->assertForbidden()->assertJson(['message' => 'El rol del dueño del token ya no permite esta operación.']);

    $editor->update(['is_active' => false]);
    api($token)->getJson('/api/v1/contacts')->assertForbidden();
});

it('limits requests per token', function () {
    config(['services.api.rate_per_minute' => 2]);
    $token = apiToken($this->admin, ['tags:read']);

    api($token)->getJson('/api/v1/tags')->assertOk();
    api($token)->getJson('/api/v1/tags')->assertOk();
    api($token)->getJson('/api/v1/tags')->assertTooManyRequests()->assertJson(['message' => 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.']);
});

it('creates tokens from the panel, shows them once, limits abilities to the role and revokes them', function () {
    $this->actingAs($this->admin)->post(route('admin.api.tokens.store'), ['name' => 'Sitio web', 'abilities' => ['contacts:read', 'subscriptions:write'], 'expires_in_days' => 30])
        ->assertRedirect(route('admin.api.index'))->assertSessionHas('api_token');

    $token = PersonalAccessToken::query()->sole();
    expect($token->abilities)->toBe(['contacts:read', 'subscriptions:write'])
        ->and($token->expires_at->isFuture())->toBeTrue();

    $this->actingAs($this->admin)->delete(route('admin.api.tokens.destroy', $token))->assertRedirect();
    expect(PersonalAccessToken::query()->count())->toBe(0);

    $this->actingAs(userWithRole(Role::Editor, $this->org))->get(route('admin.api.index'))->assertForbidden();
});
