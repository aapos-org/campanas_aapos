<?php

use App\Enums\AutomationTrigger;
use App\Enums\ContactStatus;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\Audience;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\MailConnection;
use App\Models\MergeField;
use App\Models\Subscription;
use App\Models\Tag;
use App\Services\Automations\AutomationEditor;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->org = $this->admin->organization;
    $this->org->update(['physical_address' => '5a Avenida, Guatemala', 'timezone' => 'America/Guatemala']);
    actAsOrganization($this->org);
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm', 'TransactionID' => 't'])]);
    $this->audience = Audience::factory()->create(['permission_reminder' => 'Te suscribiste en la web.']);
    $this->connection = MailConnection::factory()->create();
    $this->tag = Tag::factory()->create(['name' => 'Bienvenida completa']);
});

function emailsSent(): array
{
    return Http::recorded()
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'emails/transactional'))
        ->map(fn ($pair) => [$pair[0]['Recipients']['To'][0], $pair[0]['Content']['Subject']])
        ->values()->all();
}

function subscribe(string $email, array $fields = []): Contact
{
    $contact = Contact::factory()->create(['email' => $email, 'fields' => $fields]);
    Subscription::create(['audience_id' => test()->audience->id, 'contact_id' => $contact->id, 'status' => ContactStatus::Subscribed, 'subscribed_at' => now()]);

    return $contact;
}

/**
 * Creates and activates an automation through the HTTP endpoints, giving every email content.
 */
function automation(object $test, string $trigger, array $steps, array $config = [], bool $reentry = false): Automation
{
    $test->actingAs($test->admin)->post(route('automations.store'), ['name' => 'Bienvenida', 'audience_id' => $test->audience->id, 'trigger_type' => $trigger]);
    $automation = Automation::query()->latest('id')->firstOrFail();

    $test->actingAs($test->admin)->put(route('automations.update', $automation), [
        'name' => 'Bienvenida', 'audience_id' => $test->audience->id, 'trigger_type' => $trigger, 'trigger_config' => $config,
        'allow_reentry' => $reentry, 'mail_connection_id' => $test->connection->id, 'from_name' => 'AAPOS', 'from_email' => 'info@example.org',
        'steps' => $steps,
    ])->assertSessionHasNoErrors();

    foreach ($automation->steps()->with('campaign')->get() as $step) {
        if ($step->campaign !== null) {
            $test->actingAs($test->admin)->put(route('campaigns.content.update', $step->campaign), ['html' => '<p>Hola {{ nombre }}, <a href="https://example.org">visita</a></p>'])
                ->assertSessionHasNoErrors();
        }
    }

    $test->actingAs($test->admin)->post(route('automations.activate', $automation))->assertSessionHasNoErrors();

    return $automation->refresh();
}

it('runs a welcome series: email, wait, tag, email — once per contact', function () {
    subscribe('antes@example.com'); // subscribed before activation: not enrolled
    $this->travel(1)->minutes();

    $automation = automation($this, 'subscribed', [
        ['type' => 'send_email', 'config' => ['subject' => 'Bienvenido']],
        ['type' => 'wait', 'config' => ['amount' => 2, 'unit' => 'days']],
        ['type' => 'add_tag', 'config' => ['tag_id' => $this->tag->id]],
        ['type' => 'send_email', 'config' => ['subject' => 'Conoce nuestros proyectos']],
    ]);
    $this->travel(1)->minutes();
    $ana = subscribe('ana@example.com');

    $this->artisan('automations:run')->assertSuccessful();

    $run = AutomationRun::query()->sole();
    expect(emailsSent())->toBe([['ana@example.com', 'Bienvenido']])
        ->and($run->status)->toBe('active')
        ->and($run->next_run_at->isFuture())->toBeTrue();

    $this->artisan('automations:run')->assertSuccessful();
    expect(emailsSent())->toHaveCount(1); // waiting: nothing new

    $this->travel(2)->days();
    $this->artisan('automations:run')->assertSuccessful();

    expect(emailsSent())->toBe([['ana@example.com', 'Bienvenido'], ['ana@example.com', 'Conoce nuestros proyectos']])
        ->and($run->refresh()->status)->toBe('completed')
        ->and($ana->tags()->pluck('name')->all())->toBe(['Bienvenida completa'])
        ->and($run->logs()->pluck('message')->all())->toContain('Flujo completado.');

    $this->artisan('automations:run')->assertSuccessful();
    expect(AutomationRun::query()->count())->toBe(1)->and(emailsSent())->toHaveCount(2);

    // The automation email collects its own report.
    $campaign = $automation->steps()->first()->campaign;
    expect($campaign->recipients()->where('status', 'sent')->count())->toBe(1);
});

it('takes contacts out of the flow when they unsubscribe', function () {
    automation($this, 'subscribed', [
        ['type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'hours']],
        ['type' => 'send_email', 'config' => ['subject' => 'Segundo correo']],
    ]);
    $this->travel(1)->minutes();
    $contact = subscribe('beto@example.com');
    $this->artisan('automations:run');

    Subscription::query()->where('contact_id', $contact->id)->update(['status' => ContactStatus::Unsubscribed]);
    $this->travel(2)->hours();
    $this->artisan('automations:run');

    expect(emailsSent())->toBe([])
        ->and(AutomationRun::query()->sole())->status->toBe('exited')->exit_reason->toBe('ya no está suscrito');
});

it('starts on a tag and branches with simple conditions', function () {
    $trigger = Tag::factory()->create(['name' => 'Donante']);
    $vip = Tag::factory()->create(['name' => 'VIP']);
    automation($this, 'tag_added', [
        ['type' => 'condition', 'config' => ['match' => 'all', 'conditions' => [['field' => 'tag', 'op' => 'has', 'value' => $vip->id]], 'on_false' => 'skip']],
        ['type' => 'send_email', 'config' => ['subject' => 'Gracias VIP']],
        ['type' => 'send_email', 'config' => ['subject' => 'Gracias por donar']],
    ], ['tag_id' => $trigger->id]);
    $this->travel(1)->minutes();

    $normal = subscribe('normal@example.com');
    $special = subscribe('vip@example.com');
    $special->tags()->attach($vip->id, ['created_at' => now()]);
    $this->artisan('automations:run');
    expect(emailsSent())->toBe([]); // nobody has the trigger tag yet

    $normal->tags()->attach($trigger->id, ['created_at' => now()]);
    $special->tags()->attach($trigger->id, ['created_at' => now()]);
    $this->artisan('automations:run');

    expect(emailsSent())->toEqualCanonicalizing([
        ['normal@example.com', 'Gracias por donar'],
        ['vip@example.com', 'Gracias VIP'],
        ['vip@example.com', 'Gracias por donar'],
    ]);
});

it('sends birthday emails once a year at the configured hour', function () {
    MergeField::factory()->create(['key' => 'cumple', 'label' => 'Cumpleaños', 'type' => FieldType::Birthday]);
    $this->travelTo(now('America/Guatemala')->setDate(2026, 3, 14)->setTime(7, 0));
    subscribe('cumple@example.com', ['cumple' => '03-14']);
    subscribe('otro@example.com', ['cumple' => '08-01']);

    automation($this, AutomationTrigger::DateField->value, [
        ['type' => 'send_email', 'config' => ['subject' => '¡Feliz cumpleaños!']],
    ], ['field_key' => 'cumple', 'days_before' => 0, 'send_hour' => 9], reentry: true);

    $this->artisan('automations:run');
    expect(emailsSent())->toBe([]); // before 9:00 in Guatemala

    $this->travelTo(now('America/Guatemala')->setTime(9, 5));
    $this->artisan('automations:run');
    $this->artisan('automations:run');
    expect(emailsSent())->toBe([['cumple@example.com', '¡Feliz cumpleaños!']]);

    $this->travelTo(now('America/Guatemala')->addYear()->setTime(10, 0));
    $this->artisan('automations:run');
    expect(emailsSent())->toHaveCount(2)
        ->and(AutomationRun::query()->pluck('trigger_key')->sort()->values()->all())->toBe(['date:2026', 'date:2027']);
});

it('explains in Spanish what is missing before activation', function () {
    $this->actingAs($this->admin)->post(route('automations.store'), ['name' => 'X', 'audience_id' => $this->audience->id, 'trigger_type' => 'subscribed']);
    $automation = Automation::query()->sole();

    $this->actingAs($this->admin)->post(route('automations.activate', $automation))->assertSessionHasErrors(['activation' => 'Agrega al menos un paso.']);

    $this->actingAs($this->admin)->put(route('automations.update', $automation), [
        'name' => 'X', 'audience_id' => $this->audience->id, 'trigger_type' => 'subscribed',
        'steps' => [['type' => 'wait', 'config' => ['amount' => 0, 'unit' => 'days']]],
    ])->assertSessionHasErrors(['steps' => 'Paso 1 (Esperar): indica una cantidad entre 1 y 365.']);

    $this->actingAs($this->admin)->put(route('automations.update', $automation), [
        'name' => 'X', 'audience_id' => $this->audience->id, 'trigger_type' => 'subscribed', 'mail_connection_id' => $this->connection->id,
        'steps' => [['type' => 'send_email', 'config' => ['subject' => 'Hola']]],
    ])->assertSessionHasNoErrors();

    expect(app(AutomationEditor::class)->activationProblems($automation->refresh()))->toBe(['El correo del paso 1 no tiene contenido.']);
});

it('keeps automation emails out of the campaign list and shows the flow report', function () {
    $automation = automation($this, 'subscribed', [['type' => 'send_email', 'config' => ['subject' => 'Bienvenido']]]);

    $this->actingAs($this->admin)->get(route('campaigns.index'))->assertInertia(fn ($page) => $page->has('campaigns.data', 0));
    $this->actingAs($this->admin)->get(route('automations.show', $automation))
        ->assertOk()->assertInertia(fn ($page) => $page->component('automations/Show')->has('steps', 1)->where('steps.0.label', '1. Enviar «Bienvenido»'));
});
