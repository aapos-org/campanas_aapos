<?php

use App\Enums\CampaignStatus;
use App\Enums\Role;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\ImportBatch;
use App\Models\MailConnection;
use App\Services\Campaigns\CampaignSender;
use App\Services\Limits\MonthlySendQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Campaigns\CampaignTestHelpers;

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->org = $this->admin->organization;
    actAsOrganization($this->org);
});

it('lets an administrator edit the organization profile and limits, and audits it', function () {
    $this->actingAs($this->admin)->put(route('admin.organization.update'), [
        'name' => 'AAPOS', 'physical_address' => '5a Avenida 1-23, Antigua Guatemala', 'timezone' => 'America/Guatemala',
        'default_from_email' => 'info@example.org', 'limits' => ['contacts' => '1000', 'monthly_sends' => '', 'daily_sends_per_connection' => 500],
    ])->assertSessionHasNoErrors();

    expect($this->org->refresh()->physical_address)->toBe('5a Avenida 1-23, Antigua Guatemala')
        ->and($this->org->limits)->toBe(['contacts' => 1000, 'monthly_sends' => null, 'daily_sends_per_connection' => 500])
        ->and(DB::table('audit_logs')->where('event', 'organization.updated')->count())->toBe(1);

    $this->actingAs(userWithRole(Role::Editor, $this->org))->get(route('admin.organization.edit'))->assertForbidden();
});

it('stops creating contacts at the limit, from the panel and from imports', function () {
    Storage::fake('local');
    $this->org->update(['limits' => ['contacts' => 3]]);
    $audience = Audience::factory()->create();
    Contact::factory()->count(2)->create();

    $this->actingAs($this->admin)->post(route('contacts.store', $audience), ['email' => 'tercero@example.com', 'status' => 'subscribed', 'consent_detail' => 'Ficha firmada'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('contacts.store', $audience), ['email' => 'cuarto@example.com', 'status' => 'subscribed', 'consent_detail' => 'Ficha firmada'])
        ->assertSessionHasErrors(['email' => 'Se alcanzó el límite de 3 contactos de la organización. Un administrador puede ampliarlo en Administración → Organización.']);

    $this->actingAs($this->admin)->post(route('imports.store', $audience), ['file' => UploadedFile::fake()->createWithContent('c.csv', "correo\nquinto@example.com\n")]);
    $batch = ImportBatch::query()->latest('id')->firstOrFail();
    $this->actingAs($this->admin)->post(route('imports.start', $batch), [
        'mapping' => ['0' => 'email'], 'status' => 'subscribed', 'update_existing' => true, 'consent_confirmed' => true, 'consent_detail' => 'Lista del congreso',
    ]);

    // The import stops with the limit message instead of creating contacts beyond it.
    expect(Contact::query()->count())->toBe(3)
        ->and($batch->fresh()->status->value)->toBe('failed')
        ->and($batch->fresh()->error)->toContain('límite de 3 contactos');
});

it('pauses a campaign when the monthly send limit is reached', function () {
    Http::fake(['api.elasticemail.com/*' => Http::response(['MessageID' => 'm', 'TransactionID' => 't'])]);
    $this->org->update(['limits' => ['monthly_sends' => 2]]);
    ['campaign' => $campaign] = CampaignTestHelpers::campaignWithSubscribers($this->org, 4);

    app(CampaignSender::class)->start($campaign);

    $campaign->refresh();
    expect($campaign->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->status_reason)->toContain('límite mensual de 2 envíos')
        ->and($campaign->sent_count)->toBe(2)
        ->and(app(MonthlySendQuota::class)->used($this->org->refresh()))->toBe(2);
});

it('caps the daily limit of sending connections to the organization ceiling', function () {
    $this->org->update(['limits' => ['daily_sends_per_connection' => 300]]);
    $connection = MailConnection::factory()->create(['daily_limit' => 100]);

    $this->actingAs($this->admin)->put(route('connections.update', $connection), [
        'name' => $connection->name, 'from_email' => $connection->from_email, 'from_name' => 'AAPOS', 'daily_limit' => 5000,
    ]);

    expect($connection->refresh()->daily_limit)->toBe(300);
});

it('shows usage and warns on the dashboard near the limits', function () {
    $this->org->update(['limits' => ['contacts' => 5, 'monthly_sends' => 10]]);
    Contact::factory()->count(4)->create();
    app(MonthlySendQuota::class)->reserve($this->org, 10);

    $this->actingAs($this->admin)->get(route('usage'))
        ->assertInertia(fn ($page) => $page->component('admin/organization/Usage')
            ->where('contacts', ['used' => 4, 'limit' => 5])->where('monthly.used', 10)->has('history', 6));

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('alerts', fn ($alerts) => collect($alerts)->pluck('title')->intersect([
            'Cerca del límite de contactos', 'Se alcanzó el límite de envíos de este mes',
        ])->count() === 2));
});
