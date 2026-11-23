<?php

use App\Enums\ContactStatus;
use App\Enums\Role;
use App\Enums\SuppressionReason;
use App\Jobs\SendOptInConfirmation;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\Form;
use App\Models\MailConnection;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Suppression;
use App\Services\Contacts\SuppressionService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->org = $this->admin->organization;
    actAsOrganization($this->org);
    $this->audience = Audience::factory()->create(['double_opt_in' => true]);
    $this->form = Form::create([
        'audience_id' => $this->audience->id,
        'name' => 'Boletín web',
        'fields' => [
            ['key' => 'email', 'label' => 'Correo', 'required' => true],
            ['key' => 'first_name', 'label' => 'Nombre', 'required' => true],
        ],
        'texts' => [],
        'style' => [],
        'tags' => ['Web'],
    ]);
    // Public requests have no tenant set.
    app(CurrentOrganization::class)->set(null);
});

function submitForm(object $test, array $data, bool $wait = true)
{
    return $test->post(route('forms.public.submit', ['slug' => $test->form->slug]), $data + [
        '_ts' => Crypt::encryptString((string) ($wait ? time() - 10 : time())),
    ]);
}

function statusOf(string $email, Audience $audience): ?ContactStatus
{
    return Subscription::query()->withoutGlobalScopes()
        ->where('audience_id', $audience->id)
        ->whereHas('contact', fn ($q) => $q->withoutGlobalScopes()->where('email', $email))
        ->first()?->status;
}

it('renders an embeddable hosted form with honeypot and nonce', function () {
    $response = $this->get(route('forms.public.show', ['slug' => $this->form->slug, 'embed' => 1]))->assertOk();

    expect($response->headers->has('X-Frame-Options'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *')
        ->and($response->getContent())->toContain('name="website"')->toContain('Suscríbete a nuestro boletín');

    // The admin panel is still not frameable.
    expect($this->get(route('login'))->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('keeps contacts pending and sends a confirmation email for double opt-in', function () {
    Queue::fake();

    submitForm($this, ['email' => 'Ana@Example.com', 'first_name' => 'Ana'])
        ->assertRedirect(route('forms.public.result', ['slug' => $this->form->slug, 'estado' => 'pending']));

    expect(statusOf('ana@example.com', $this->audience))->toBe(ContactStatus::Pending);
    Queue::assertPushed(SendOptInConfirmation::class);
});

it('sends the confirmation with a signed link that subscribes with consent evidence', function () {
    actAsOrganization($this->org);
    MailConnection::factory()->create(['is_default' => true]);
    app(CurrentOrganization::class)->set(null);
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm1'])]);

    submitForm($this, ['email' => 'ana@example.com', 'first_name' => 'Ana']);

    $sent = Http::recorded()->first()[0];
    expect($sent['Recipients']['To'])->toBe(['ana@example.com'])
        ->and($sent['Content']['Subject'])->toBe('Confirma tu suscripción');
    preg_match('#href="([^"]+/confirmar/[^"]+)"#', $sent['Content']['Body'][0]['Content'], $m);
    $link = html_entity_decode($m[1]);

    $this->withServerVariables(['REMOTE_ADDR' => '190.1.2.3'])->get($link)->assertOk()->assertSee('Tu suscripción está confirmada');

    $subscription = Subscription::query()->withoutGlobalScopes()->where('audience_id', $this->audience->id)->sole();
    expect($subscription->status)->toBe(ContactStatus::Subscribed)
        ->and($subscription->consent_source)->toBe('form')
        ->and($subscription->consent_ip)->toBe('190.1.2.3')
        ->and(Contact::query()->withoutGlobalScopes()->sole()->tags()->pluck('name')->all())->toBe(['Web']);

    // Tampered or expired links are rejected.
    $this->get(str_replace('/confirmar/', '/confirmar/9', $link))->assertForbidden();
    $this->travel(8)->days();
    $this->get($link)->assertForbidden();
});

it('subscribes immediately with single opt-in, recording IP and form', function () {
    $this->form->update(['double_opt_in' => false]);

    $this->withServerVariables(['REMOTE_ADDR' => '200.1.1.1'])
        ->post(route('forms.public.submit', ['slug' => $this->form->slug]), ['email' => 'beto@example.com', 'first_name' => 'Beto', '_ts' => Crypt::encryptString((string) (time() - 5))])
        ->assertRedirect();

    $subscription = Subscription::query()->withoutGlobalScopes()->sole();
    expect($subscription->status)->toBe(ContactStatus::Subscribed)
        ->and($subscription->consent_ip)->toBe('200.1.1.1')
        ->and($subscription->consent_detail)->toContain('Boletín web');
});

it('does not let a third party change someone who unsubscribed; only the person can, by confirming', function () {
    Queue::fake();
    $this->form->update(['double_opt_in' => false]);
    actAsOrganization($this->org);
    $contact = Contact::factory()->create(['email' => 'carla@example.com']);
    Subscription::create(['audience_id' => $this->audience->id, 'contact_id' => $contact->id, 'status' => ContactStatus::Unsubscribed]);
    app(SuppressionService::class)->suppress('carla@example.com', SuppressionReason::Unsubscribed, $this->audience);
    app(CurrentOrganization::class)->set(null);

    submitForm($this, ['email' => 'carla@example.com', 'first_name' => 'Carla']);

    // Even with single opt-in: still unsubscribed, and a confirmation is required.
    expect(statusOf('carla@example.com', $this->audience))->toBe(ContactStatus::Unsubscribed);
    Queue::assertPushed(SendOptInConfirmation::class);
});

it('never contacts manually suppressed addresses', function () {
    Queue::fake();
    actAsOrganization($this->org);
    app(SuppressionService::class)->suppress('legal@example.com', SuppressionReason::Manual);
    app(CurrentOrganization::class)->set(null);

    submitForm($this, ['email' => 'legal@example.com', 'first_name' => 'X'])->assertRedirect();

    Queue::assertNothingPushed();
    expect(Suppression::query()->withoutGlobalScopes()->where('email', 'legal@example.com')->exists())->toBeTrue();
});

it('silently drops honeypot submissions and rejects instant ones', function () {
    Queue::fake();

    submitForm($this, ['email' => 'bot@example.com', 'first_name' => 'Bot', 'website' => 'http://spam'])->assertRedirect();
    submitForm($this, ['email' => 'bot2@example.com', 'first_name' => 'Bot'], wait: false)->assertStatus(422)->assertSee('demasiado rápido');

    expect(Contact::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('re-renders the form with Spanish errors for invalid input', function () {
    submitForm($this, ['email' => 'no-es-correo', 'first_name' => ''])
        ->assertStatus(422)
        ->assertSee('Escribe un correo electrónico válido')
        ->assertSee('Completa el campo «Nombre»');
});

it('requires Turnstile when the form has captcha enabled', function () {
    config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
    $this->form->update(['captcha' => true]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    submitForm($this, ['email' => 'x@example.com', 'first_name' => 'X'])->assertStatus(422)->assertSee('No pudimos verificar que no eres un robot');
    submitForm($this, ['email' => 'x@example.com', 'first_name' => 'X', 'cf-turnstile-response' => 'token'])->assertStatus(422);
    Http::assertSent(fn (Request $r) => $r['secret'] === 'secret' && $r['response'] === 'token');

    expect(Contact::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('only touches contacts of the form organization', function () {
    Queue::fake();
    $other = Organization::factory()->create();
    $foreign = Contact::factory()->for($other)->create(['email' => 'same@example.com', 'first_name' => 'Otra org']);

    submitForm($this, ['email' => 'same@example.com', 'first_name' => 'Nueva']);

    expect(Contact::query()->withoutGlobalScopes()->where('email', 'same@example.com')->count())->toBe(2)
        ->and($foreign->fresh()->first_name)->toBe('Otra org');
});

it('rate-limits submissions per email', function () {
    Queue::fake();

    foreach (range(1, 5) as $i) {
        submitForm($this, ['email' => 'flood@example.com', 'first_name' => 'F']);
    }

    submitForm($this, ['email' => 'flood@example.com', 'first_name' => 'F'])->assertTooManyRequests();
});
