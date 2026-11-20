<?php

use App\Enums\ContactStatus;
use App\Enums\FieldType;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\ImportBatch;
use App\Models\MergeField;
use App\Models\Subscription;
use App\Services\Contacts\SuppressionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = userWithRole(Role::Admin);
    actAsOrganization($this->admin->organization);
    $this->audience = Audience::factory()->create();
    MergeField::factory()->create(['key' => 'birthday', 'label' => 'Cumpleaños', 'type' => FieldType::Birthday]);
});

function uploadCsv(object $test, string $csv, string $name = 'contactos.csv'): ImportBatch
{
    $test->actingAs($test->admin)
        ->post(route('imports.store', $test->audience), ['file' => UploadedFile::fake()->createWithContent($name, $csv)])
        ->assertSessionHasNoErrors();

    return ImportBatch::query()->latest('id')->firstOrFail();
}

function startImport(object $test, ImportBatch $batch, array $options = []): ImportBatch
{
    $test->actingAs($test->admin)->post(route('imports.start', $batch), $options + [
        'mapping' => ['0' => 'email', '1' => 'first_name', '2' => 'phone', '3' => 'field:birthday', '4' => 'tags'],
        'status' => 'subscribed',
        'update_existing' => true,
        'tags' => ['Importados'],
        'consent_confirmed' => true,
        'consent_detail' => 'Inscritos al congreso 2026',
    ])->assertSessionHasNoErrors()->assertRedirect(route('imports.show', $batch));

    return $batch->fresh();
}

it('imports, normalizes, deduplicates and reports every problem row', function () {
    $csv = "Correo;Nombre;Teléfono;Cumpleaños;Etiquetas\n"
        ." Ana@Example.COM ;Ana;5555 1234;31/03;Socios, VIP\n"
        ."ana@example.com;Ana repetida;;;\n"
        ."no-es-correo;Luis;;;\n"
        .";Sin correo;;;\n"
        ."beto@example.com;Beto;123;31/02;\n";

    $batch = startImport($this, uploadCsv($this, $csv));

    expect($batch->status)->toBe(ImportStatus::Completed)
        ->and($batch->total_rows)->toBe(5)
        ->and($batch->processed_rows)->toBe(5)
        ->and($batch->created_count)->toBe(2)
        ->and($batch->skipped_count)->toBe(1)
        ->and($batch->failed_count)->toBe(2);

    $ana = Contact::query()->where('email', 'ana@example.com')->sole();
    expect($ana->phone)->toBe('+50255551234')
        ->and($ana->fields['birthday'])->toBe('03-31')
        ->and($ana->tags->pluck('name')->sort()->values()->all())->toBe(['Importados', 'Socios', 'VIP']);

    $subscription = Subscription::query()->where('contact_id', $ana->id)->sole();
    expect($subscription->status)->toBe(ContactStatus::Subscribed)
        ->and($subscription->consent_source)->toBe('import')
        ->and($subscription->consent_detail)->toContain('Inscritos al congreso 2026')->toContain('contactos.csv');

    // Beto is imported, but his invalid phone and impossible birthday are dropped with warnings.
    $beto = Contact::query()->where('email', 'beto@example.com')->sole();
    expect($beto->phone)->toBeNull()->and($beto->fields)->not->toHaveKey('birthday');

    expect($batch->rows()->where('outcome', 'warning')->count())->toBe(2)
        ->and($batch->rows()->where('outcome', 'failed')->pluck('reason')->all())->toContain('Falta el correo electrónico.');

    // The uploaded file (personal data) is deleted after processing.
    Storage::disk('local')->assertMissing($batch->file_path);
});

it('never subscribes suppressed addresses nor reactivates unsubscribed ones', function () {
    app(SuppressionService::class)->suppress('rebote@example.com', SuppressionReason::HardBounce);

    $baja = Contact::factory()->create(['email' => 'baja@example.com']);
    Subscription::create(['audience_id' => $this->audience->id, 'contact_id' => $baja->id, 'status' => ContactStatus::Unsubscribed]);

    $csv = "email,nombre,tel,cumple,tags\nrebote@example.com,R,,,\nbaja@example.com,B,,,\nnueva@example.com,N,,,\n";
    $batch = startImport($this, uploadCsv($this, $csv));

    expect(Subscription::query()->with('contact')->get()->mapWithKeys(fn ($s) => [$s->contact->email => $s->status->value])->all())
        ->toBe([
            'baja@example.com' => 'unsubscribed',
            'rebote@example.com' => 'cleaned',
            'nueva@example.com' => 'subscribed',
        ]);

    expect($batch->skipped_count)->toBe(2)->and($batch->created_count)->toBe(1);
});

it('requires an explicit consent statement to import subscribed contacts', function () {
    $batch = uploadCsv($this, "email\nx@example.com\n");

    $this->actingAs($this->admin)->post(route('imports.start', $batch), [
        'mapping' => ['0' => 'email'],
        'status' => 'subscribed',
    ])->assertSessionHasErrors(['consent_confirmed', 'consent_detail']);

    expect($batch->fresh()->status)->toBe(ImportStatus::Uploaded);
});

it('requires exactly one email column', function () {
    $batch = uploadCsv($this, "a,b\nx@example.com,y@example.com\n");

    $this->actingAs($this->admin)->post(route('imports.start', $batch), [
        'mapping' => ['0' => 'email', '1' => 'email'],
        'status' => 'non_subscribed',
    ])->assertSessionHasErrors('mapping');
});

it('updates existing contacts only with non-empty values and keeps their consent', function () {
    $existing = Contact::factory()->create(['email' => 'ana@example.com', 'first_name' => 'Ana', 'last_name' => 'López']);
    Subscription::create(['audience_id' => $this->audience->id, 'contact_id' => $existing->id, 'status' => ContactStatus::Subscribed, 'consent_source' => 'form', 'consent_at' => now()->subYear()]);

    $batch = startImport($this, uploadCsv($this, "email,nombre,tel,cumple,tags\nana@example.com,,,15/08,\n"));

    $existing->refresh();
    expect($existing->first_name)->toBe('Ana')
        ->and($existing->last_name)->toBe('López')
        ->and($existing->fields['birthday'])->toBe('08-15')
        ->and(Subscription::query()->sole()->consent_source)->toBe('form')
        ->and($batch->updated_count)->toBe(1);
});

it('imports a list of unsubscribes into the suppression list', function () {
    $batch = uploadCsv($this, "email\nfuera@example.com\n");

    $this->actingAs($this->admin)->post(route('imports.start', $batch), [
        'mapping' => ['0' => 'email'],
        'status' => 'unsubscribed',
    ])->assertSessionHasNoErrors();

    expect(app(SuppressionService::class)->isSuppressed('fuera@example.com', $this->audience->id))->toBeTrue();
});

it('reads Latin-1 CSV files exported by Excel', function () {
    $csv = mb_convert_encoding("correo;nombre\nmaria@example.com;María Peñate\n", 'Windows-1252', 'UTF-8');
    $batch = uploadCsv($this, $csv);

    $this->actingAs($this->admin)->post(route('imports.start', $batch), [
        'mapping' => ['0' => 'email', '1' => 'first_name'],
        'status' => 'non_subscribed',
    ]);

    expect(Contact::query()->where('email', 'maria@example.com')->value('first_name'))->toBe('María Peñate');
});

it('suggests a column mapping from Spanish and Mailchimp headers', function () {
    $batch = uploadCsv($this, "Email Address,First Name,Apellidos,Teléfono,Cumpleaños,Otra\nx@example.com,X,Y,,,\n");

    $this->actingAs($this->admin)->get(route('imports.map', $batch))
        ->assertInertia(fn ($page) => $page->where('suggested', ['email', 'first_name', 'last_name', 'phone', 'field:birthday', 'ignore']));
});

it('produces a downloadable error report escaping spreadsheet formulas', function () {
    $batch = startImport($this, uploadCsv($this, "email,nombre,tel,cumple,tags\n=HYPERLINK(\"http://x\"),Mal,,,\n"));

    $content = $this->actingAs($this->admin)->get(route('imports.report', $batch))->assertOk()->streamedContent();

    expect($content)->toContain('Correo inválido')->toContain("'=hyperlink");
});

it('imports Excel files keeping columns aligned when a middle cell is empty', function () {
    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Correo', 'Nombre', 'Apellido']));
    $writer->addRow(Row::fromValues(['sin-nombre@example.com', null, 'Pérez']));
    $writer->addRow(Row::fromValues(['con-fecha@example.com', 'Luz', 'Ramos']));
    $writer->close();

    $this->actingAs($this->admin)
        ->post(route('imports.store', $this->audience), ['file' => new UploadedFile($path, 'contactos.xlsx', null, null, true)])
        ->assertSessionHasNoErrors();
    $batch = ImportBatch::query()->latest('id')->firstOrFail();

    $this->actingAs($this->admin)->post(route('imports.start', $batch), [
        'mapping' => ['0' => 'email', '1' => 'first_name', '2' => 'last_name'],
        'status' => 'non_subscribed',
    ])->assertSessionHasNoErrors();

    $contact = Contact::query()->where('email', 'sin-nombre@example.com')->sole();
    expect($contact->first_name)->toBeNull()
        ->and($contact->last_name)->toBe('Pérez')
        ->and($batch->fresh()->created_count)->toBe(2);
});
