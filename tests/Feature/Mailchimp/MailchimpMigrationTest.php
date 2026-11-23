<?php

use App\Enums\ContactStatus;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\MergeField;
use App\Models\Subscription;
use App\Models\Suppression;
use App\Services\Mailchimp\MailchimpBackup;
use App\Services\Mailchimp\MailchimpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Mailchimp\FakeMailchimp;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = userWithRole(Role::Admin);
    $this->org = $this->admin->organization;
    $this->reportsDir = sys_get_temp_dir().'/mc-reports-'.uniqid();
});

afterEach(fn () => File::deleteDirectory($this->reportsDir));

function extractAndLoad(object $test, bool $dryRun = false): void
{
    $test->artisan('mailchimp:extract')->assertSuccessful();

    if (! $dryRun) {
        $test->artisan('mailchimp:load', ['--dry-run' => true, '--organization' => $test->org->slug])->assertSuccessful();
    }

    $test->artisan('mailchimp:load', [
        '--dry-run' => $dryRun,
        '--force' => true,
        '--organization' => $test->org->slug,
        '--reports-dir' => $test->reportsDir,
    ])->assertSuccessful();

    actAsOrganization($test->org);
}

function subscriptionStatus(string $email, string $listId): ?string
{
    $contact = Contact::query()->where('email', $email)->first();
    $audience = Audience::query()->where('mailchimp_id', $listId)->first();

    return Subscription::query()->where('contact_id', $contact?->id)->where('audience_id', $audience?->id)->first()?->status->value;
}

it('only ever sends GET requests to Mailchimp', function () {
    FakeMailchimp::install();

    $this->artisan('mailchimp:extract')->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    expect(get_class_methods(MailchimpClient::class))
        ->not->toContain('post')->not->toContain('put')->not->toContain('patch')->not->toContain('delete');
});

it('stores the raw extraction encrypted and resumes without calling the API again', function () {
    FakeMailchimp::install();
    $this->artisan('mailchimp:extract')->assertSuccessful();
    $calls = count(Http::recorded());

    $backup = new MailchimpBackup;
    $raw = Storage::disk('local')->get($backup->root().'/lists/list1/members/page-0000000.json.enc');
    expect($raw)->not->toContain('ana@example.com')
        ->and($backup->get('lists/list1/members/page-0000000')['members'][0]['email_address'])->toBe('ana@example.com')
        ->and(Storage::disk('local')->get($backup->root().'/manifest.json'))->not->toContain('@');

    $this->artisan('mailchimp:extract')->assertSuccessful();
    expect(count(Http::recorded()))->toBe($calls);
});

it('refuses a real load without a previous dry run', function () {
    FakeMailchimp::install();
    $this->artisan('mailchimp:extract')->assertSuccessful();

    $this->artisan('mailchimp:load', ['--organization' => $this->org->slug])->assertFailed();
});

it('changes nothing in a dry run but reports what would happen', function () {
    FakeMailchimp::install();
    extractAndLoad($this, dryRun: true);

    expect(Contact::query()->count())->toBe(0)
        ->and(Audience::query()->count())->toBe(0)
        ->and(Suppression::query()->count())->toBe(0);

    $reports = Storage::disk('local')->files((new MailchimpBackup)->root().'/reports');
    expect($reports)->toHaveCount(1);
    expect(Storage::disk('local')->get($reports[0]))
        ->toContain('Ensayo en seco')
        ->toContain('Boletín')
        ->toContain('Correo inválido')
        ->toContain('| 0 | 0 | 0 |')
        ->not->toContain('no-es-correo');
});

it('applies the golden rules of the migration', function () {
    FakeMailchimp::install();
    extractAndLoad($this);

    // Subscribed with consent evidence
    $ana = Contact::query()->where('email', 'ana@example.com')->sole();
    $anaSub = Subscription::query()->where('contact_id', $ana->id)->sole();
    expect($anaSub->status)->toBe(ContactStatus::Subscribed)
        ->and($anaSub->consent_source)->toBe('mailchimp')
        ->and($anaSub->consent_ip)->toBe('190.56.1.1')
        ->and($anaSub->consent_at?->toDateString())->toBe('2024-05-01')
        ->and($ana->phone)->toBe('+50255551234')
        ->and($ana->tags->pluck('name')->all())->toBe(['VIP']);

    // Merge fields: birthday MM/DD and dropdown → select
    $birthday = MergeField::query()->where('mailchimp_tag', 'MMERGE5')->sole();
    expect($birthday->type)->toBe(FieldType::Birthday)
        ->and($ana->fields[$birthday->key])->toBe('03-31')
        ->and(MergeField::query()->where('mailchimp_tag', 'MMERGE6')->sole()->type)->toBe(FieldType::Select);

    // Unsubscribed → per-audience suppression; abuse → global complaint; cleaned → global
    expect(subscriptionStatus('beto@example.com', 'list1'))->toBe('unsubscribed')
        ->and(Suppression::query()->where('email', 'beto@example.com')->sole()->audience_id)->not->toBeNull()
        ->and(Suppression::query()->where('email', 'eva@example.com')->sole()->reason)->toBe(SuppressionReason::Complaint)
        ->and(Suppression::query()->where('email', 'carla@example.com')->sole()->audience_id)->toBeNull();

    // Pending and archived are never subscribed, and have no consent
    expect(subscriptionStatus('dario@example.com', 'list1'))->toBe('pending')
        ->and(Subscription::query()->whereHas('contact', fn ($q) => $q->where('email', 'dario@example.com'))->sole()->consent_at)->toBeNull()
        ->and(subscriptionStatus('fede@example.com', 'list1'))->toBe('non_subscribed');

    // Global suppression wins over "subscribed" in another audience
    expect(subscriptionStatus('carla@example.com', 'list2'))->toBe('cleaned')
        ->and(subscriptionStatus('gabi@example.com', 'list2'))->toBe('subscribed');

    // Invalid email rejected, audience settings migrated
    expect(Contact::query()->where('email', 'no-es-correo')->exists())->toBeFalse()
        ->and(Audience::query()->where('mailchimp_id', 'list2')->sole()->double_opt_in)->toBeFalse()
        ->and(Audience::query()->where('mailchimp_id', 'list1')->sole()->default_from_email)->toBe('info@example.org');

    // Campaigns/templates stay in staging for Phase 4; manual reconstruction guides are written
    expect(DB::table('imports_staging_records')->where('resource', 'campaign')->count())->toBe(1)
        ->and(File::get($this->reportsDir.'/automatizaciones_pendientes.md'))->toContain('Bienvenida')
        ->and(File::get($this->reportsDir.'/formularios_pendientes.md'))->toContain('Suscríbete')
        ->and(File::get($this->reportsDir.'/segmentos_pendientes.md'))->toContain('Activos');
});

it('is idempotent and never reactivates a local unsubscribe on re-run', function () {
    $api = FakeMailchimp::install();
    extractAndLoad($this);

    $counts = [Contact::query()->count(), Subscription::query()->count(), Suppression::query()->count(), Audience::query()->count()];

    // Locally, Gabi unsubscribes. Mailchimp still says subscribed.
    $gabi = Contact::query()->where('email', 'gabi@example.com')->sole();
    Subscription::query()->where('contact_id', $gabi->id)->update(['status' => 'unsubscribed']);

    $this->artisan('mailchimp:load', ['--force' => true, '--organization' => $this->org->slug, '--reports-dir' => $this->reportsDir])->assertSuccessful();
    actAsOrganization($this->org);

    expect([Contact::query()->count(), Subscription::query()->count(), Suppression::query()->count(), Audience::query()->count()])->toBe($counts)
        ->and(subscriptionStatus('gabi@example.com', 'list2'))->toBe('unsubscribed');
});

it('verifies the migration against the backup with masked emails', function () {
    FakeMailchimp::install();
    extractAndLoad($this);
    $output = $this->reportsDir.'/informe.md';

    $this->artisan('mailchimp:verify', ['--organization' => $this->org->slug, '--output' => $output])->assertSuccessful();

    expect(File::get($output))->toContain('sin diferencias')->not->toContain('ana@example.com');
});

it('detects differences when the local data does not match', function () {
    FakeMailchimp::install();
    extractAndLoad($this);
    actAsOrganization($this->org);
    Subscription::query()->whereHas('contact', fn ($q) => $q->where('email', 'dario@example.com'))->update(['status' => 'subscribed']);

    $this->artisan('mailchimp:verify', ['--organization' => $this->org->slug, '--output' => $this->reportsDir.'/informe.md'])->assertFailed();
});

it('downloads campaigns in parallel and the result is identical to the sequential one', function () {
    FakeMailchimp::install();
    $this->artisan('mailchimp:extract', ['--concurrency' => 8])->assertSuccessful();

    $backup = new MailchimpBackup;
    expect($backup->get('campaigns/c1/content')['html'])->toBe('<p>Contenido</p>')
        ->and($backup->get('reports/c1/summary')['emails_sent'])->toBe(2)
        ->and($backup->get('reports/c1/click_details/page-0000000')['urls_clicked'][0]['total_clicks'])->toBe(4)
        ->and($backup->isComplete('reports/c1/unsubscribed'))->toBeTrue();

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
});

it('downloads again a backup file that was left corrupted by an interruption', function () {
    FakeMailchimp::install();
    $this->artisan('mailchimp:extract')->assertSuccessful();

    $backup = new MailchimpBackup;
    Storage::disk('local')->put($backup->root().'/campaigns/c1/content.json.enc', 'cortado-a-la-mit');
    $before = count(Http::recorded());

    $this->artisan('mailchimp:extract')->assertSuccessful();

    expect($backup->get('campaigns/c1/content')['html'])->toBe('<p>Contenido</p>')
        ->and(count(Http::recorded()) - $before)->toBe(1)
        ->and(Storage::disk('local')->allFiles($backup->root()))->each->not->toEndWith('.tmp');
});

it('retries through the sequential path when a parallel request is rate-limited', function () {
    $api = new FakeMailchimp;
    $calls = 0;
    config(['services.mailchimp.api_key' => FakeMailchimp::key()]);
    Http::fake(function (Request $request) use ($api, &$calls) {
        if (str_contains($request->url(), 'reports/c1/click-details') && $calls++ === 0) {
            return Http::response(['detail' => 'Too many requests'], 429);
        }

        return (new ReflectionMethod($api, 'respond'))->invoke($api, $request);
    });

    $this->artisan('mailchimp:extract')->assertSuccessful();

    expect((new MailchimpBackup)->get('reports/c1/click_details/page-0000000')['urls_clicked'])->toHaveCount(1);
});
