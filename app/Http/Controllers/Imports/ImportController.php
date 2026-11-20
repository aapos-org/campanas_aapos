<?php

namespace App\Http\Controllers\Imports;

use App\Enums\ContactStatus;
use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\RunContactImport;
use App\Models\Audience;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Models\MergeField;
use App\Models\Tag;
use App\Models\User;
use App\Services\Imports\SpreadsheetReader;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('imports/Index', [
            'imports' => ImportBatch::query()->with(['audience:id,name', 'user:id,name'])->latest('id')->paginate(25)
                ->through(fn (ImportBatch $b) => $this->present($b)),
        ]);
    }

    public function create(Audience $audience): Response
    {
        return Inertia::render('imports/Upload', [
            'audience' => $audience->only(['id', 'name']),
        ]);
    }

    public function store(Request $request, Audience $audience, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:csv,txt,xlsx'],
        ], [
            'file.mimes' => 'El archivo debe ser CSV o Excel (.xlsx).',
            'file.max' => 'El archivo no puede pesar más de 50 MB.',
        ], ['file' => 'archivo']);

        $file = $request->file('file');
        $extension = mb_strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $path = $file->storeAs('imports', Str::uuid().'.'.$extension, 'local');

        try {
            $reader = SpreadsheetReader::for(Storage::disk('local')->path((string) $path), 'file.'.$extension);
            $preview = $reader->preview(1);
            $total = $reader->count();
        } catch (Throwable $e) {
            Storage::disk('local')->delete((string) $path);

            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo: '.$e->getMessage()]);
        }

        if ($total === 0) {
            Storage::disk('local')->delete((string) $path);

            throw ValidationException::withMessages(['file' => 'El archivo no tiene filas de datos debajo de los encabezados.']);
        }

        $batch = ImportBatch::create([
            'audience_id' => $audience->id,
            'user_id' => $user->id,
            'source' => $extension,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_path' => $path,
            'headers' => $preview['headers'],
            'total_rows' => $total,
        ]);

        return to_route('imports.map', $batch);
    }

    public function map(ImportBatch $import): Response|RedirectResponse
    {
        if ($import->status !== ImportStatus::Uploaded) {
            return to_route('imports.show', $import);
        }

        $reader = SpreadsheetReader::for(Storage::disk('local')->path((string) $import->file_path), (string) $import->original_filename);
        $fields = MergeField::query()->orderBy('position')->get();

        return Inertia::render('imports/Map', [
            'import' => $this->present($import),
            'headers' => $import->headers,
            'preview' => $reader->preview(5)['rows'],
            'targets' => $this->targets($fields),
            'suggested' => $this->suggestMapping($import->headers, $fields),
            'tags' => Tag::query()->orderBy('name')->pluck('name'),
            'statuses' => [
                ['value' => ContactStatus::Subscribed->value, 'label' => 'Suscritos (tengo su consentimiento)'],
                ['value' => ContactStatus::NonSubscribed->value, 'label' => 'No suscritos (solo guardar sus datos)'],
                ['value' => ContactStatus::Unsubscribed->value, 'label' => 'Dados de baja (agregar a supresión)'],
            ],
        ]);
    }

    public function start(Request $request, ImportBatch $import, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($import->status === ImportStatus::Uploaded, 409, 'Esta importación ya se inició.');

        $validTargets = collect($this->targets(MergeField::query()->get()))->pluck('value')->all();

        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['required', 'string', Rule::in($validTargets)],
            'status' => ['required', Rule::in([ContactStatus::Subscribed->value, ContactStatus::NonSubscribed->value, ContactStatus::Unsubscribed->value])],
            'update_existing' => ['boolean'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:100'],
            'consent_confirmed' => ['exclude_unless:status,subscribed', 'accepted'],
            'consent_detail' => ['exclude_unless:status,subscribed', 'required', 'string', 'min:5', 'max:500'],
        ], [
            'consent_confirmed.accepted' => 'Debes confirmar que tienes el consentimiento de estas personas.',
            'consent_detail.required' => 'Indica cómo se obtuvo el consentimiento (p. ej. «Formulario de inscripción del congreso 2026»).',
        ], ['consent_detail' => 'origen del consentimiento']);

        $mapping = collect(array_map(strval(...), (array) $data['mapping']))
            ->filter(fn ($target, $index) => is_numeric($index) && (int) $index < count($import->headers))
            ->map(fn ($t) => (string) $t);

        $counts = $mapping->reject(fn ($t) => $t === 'ignore')->countBy();

        if (($counts['email'] ?? 0) !== 1) {
            throw ValidationException::withMessages(['mapping' => 'Asigna exactamente una columna como «Correo electrónico».']);
        }

        if ($counts->except('tags')->contains(fn ($n) => $n > 1)) {
            throw ValidationException::withMessages(['mapping' => 'Cada campo solo puede asignarse a una columna (salvo «Etiquetas»).']);
        }

        $import->update([
            'mapping' => $mapping->all(),
            'options' => [
                'status' => $data['status'],
                'update_existing' => (bool) ($data['update_existing'] ?? true),
                'tags' => array_values(array_filter(array_map('trim', $data['tags'] ?? []))),
                'consent_detail' => $data['consent_detail'] ?? null,
            ],
        ]);

        Audit::record('imports.started', 'Importación de contactos iniciada', $import, [
            'audience_id' => $import->audience_id,
            'file' => $import->original_filename,
            'rows' => $import->total_rows,
            'status' => $data['status'],
        ], $user);

        RunContactImport::dispatch($import->id);

        return to_route('imports.show', $import);
    }

    public function show(ImportBatch $import): Response
    {
        $import->load(['audience:id,name', 'user:id,name']);

        return Inertia::render('imports/Show', [
            'import' => $this->present($import),
            'issues' => $import->rows()->orderBy('row_number')->limit(100)->get()
                ->map(fn (ImportBatchRow $r) => $r->only(['row_number', 'email', 'outcome', 'reason'])),
            'issuesTotal' => $import->rows()->count(),
        ]);
    }

    public function cancel(ImportBatch $import, #[CurrentUser] User $user): RedirectResponse
    {
        if (! $import->status->isFinished()) {
            $import->update(['status' => ImportStatus::Cancelled, 'finished_at' => now()]);

            if ($import->file_path && $import->processed_rows === 0) {
                Storage::disk('local')->delete($import->file_path);
            }

            Audit::record('imports.cancelled', 'Importación cancelada', $import, [], $user);
        }

        return to_route('imports.show', $import);
    }

    /**
     * Downloadable CSV with every skipped/failed/warning row and its reason.
     */
    public function report(ImportBatch $import): StreamedResponse
    {
        $filename = 'reporte-importacion-'.$import->id.'.csv';

        return response()->streamDownload(function () use ($import) {
            $csv = Writer::createFromPath('php://output', 'w');
            $csv->setOutputBOM(Writer::BOM_UTF8);
            // Neutralizes =, +, -, @ at the start of a cell (CSV formula injection in Excel).
            $csv->addFormatter(new EscapeFormula);
            $csv->insertOne(['fila', 'correo', 'resultado', 'motivo', 'datos originales']);

            $labels = ['failed' => 'error', 'skipped' => 'omitida', 'warning' => 'advertencia'];

            $import->rows()->orderBy('row_number')->lazyById(1000)->each(function (ImportBatchRow $row) use ($csv, $labels) {
                $csv->insertOne([
                    $row->row_number,
                    $row->email,
                    $labels[$row->outcome] ?? $row->outcome,
                    $row->reason,
                    json_encode($row->data, JSON_UNESCAPED_UNICODE),
                ]);
            });
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  iterable<MergeField>  $fields
     * @return list<array{value: string, label: string}>
     */
    private function targets(iterable $fields): array
    {
        $targets = [
            ['value' => 'ignore', 'label' => '— No importar —'],
            ['value' => 'email', 'label' => 'Correo electrónico'],
            ['value' => 'first_name', 'label' => 'Nombre'],
            ['value' => 'last_name', 'label' => 'Apellido'],
            ['value' => 'phone', 'label' => 'Teléfono'],
            ['value' => 'tags', 'label' => 'Etiquetas (separadas por comas)'],
        ];

        foreach ($fields as $field) {
            $targets[] = ['value' => 'field:'.$field->key, 'label' => $field->label.' (campo personalizado)'];
        }

        return $targets;
    }

    /**
     * Guesses the target of each column from its header (Spanish, English and Mailchimp names).
     *
     * @param  list<string>  $headers
     * @param  iterable<MergeField>  $fields
     * @return array<int, string>
     */
    private function suggestMapping(array $headers, iterable $fields): array
    {
        $aliases = [
            'email' => ['email', 'e-mail', 'correo', 'correo electronico', 'email address', 'direccion de correo', 'mail'],
            'first_name' => ['nombre', 'nombres', 'first name', 'firstname', 'fname'],
            'last_name' => ['apellido', 'apellidos', 'last name', 'lastname', 'lname'],
            'phone' => ['telefono', 'tel', 'celular', 'movil', 'phone', 'phone number', 'whatsapp'],
            'tags' => ['etiquetas', 'etiqueta', 'tags'],
        ];

        foreach ($fields as $field) {
            $aliases['field:'.$field->key] = array_filter([$this->slug($field->label), $this->slug($field->key), $field->mailchimp_tag ? $this->slug($field->mailchimp_tag) : null]);
        }

        $suggested = [];
        $used = [];

        foreach ($headers as $index => $header) {
            $slug = $this->slug($header);
            $suggested[$index] = 'ignore';

            foreach ($aliases as $target => $names) {
                if (in_array($slug, $names, true) && ($target === 'tags' || ! isset($used[$target]))) {
                    $suggested[$index] = $target;
                    $used[$target] = true;

                    break;
                }
            }
        }

        return $suggested;
    }

    private function slug(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(Str::ascii($value))));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'audience' => $batch->audience?->only(['id', 'name']),
            'user' => $batch->user?->name,
            'file' => $batch->original_filename,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'finished' => $batch->status->isFinished(),
            'total_rows' => $batch->total_rows,
            'processed_rows' => $batch->processed_rows,
            'progress' => $batch->progress(),
            'created_count' => $batch->created_count,
            'updated_count' => $batch->updated_count,
            'skipped_count' => $batch->skipped_count,
            'failed_count' => $batch->failed_count,
            'options' => $batch->options,
            'error' => $batch->error,
            'created_at' => $batch->created_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
        ];
    }
}
