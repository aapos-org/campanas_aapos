<?php

namespace App\Http\Controllers\Imports;

use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\MergeField;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of an audience (optionally filtered by status and tag). Streamed, so it works
 * for 100k+ contacts without loading them in memory. Every export is audited.
 */
class ExportController extends Controller
{
    public function audience(Request $request, Audience $audience, #[CurrentUser] User $user): StreamedResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'tag' => ['nullable', 'integer'],
        ]);

        $status = ContactStatus::tryFrom((string) ($filters['status'] ?? ''));
        $tagId = isset($filters['tag']) ? (int) $filters['tag'] : null;
        $fields = MergeField::query()->orderBy('position')->get();

        Audit::record('contacts.exported', 'Exportación de contactos', $audience, [
            'status' => $status?->value,
            'tag' => $tagId,
        ], $user);

        $filename = Str::slug($audience->name).'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($audience, $status, $tagId, $fields) {
            $csv = Writer::createFromPath('php://output', 'w');
            $csv->setOutputBOM(Writer::BOM_UTF8);
            // Neutralizes =, +, -, @ at the start of a cell (CSV formula injection in Excel).
            $csv->addFormatter(new EscapeFormula);
            $csv->insertOne(array_merge(
                ['correo', 'nombre', 'apellido', 'telefono', 'estado', 'fecha_suscripcion', 'fecha_baja', 'origen_consentimiento', 'detalle_consentimiento', 'fecha_consentimiento', 'ip_consentimiento', 'etiquetas'],
                $fields->pluck('label')->all(),
            ));

            Subscription::query()
                ->where('audience_id', $audience->id)
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($tagId, fn ($q) => $q->whereExists(fn ($e) => $e->from('contact_tag')
                    ->whereColumn('contact_tag.contact_id', 'audience_contact.contact_id')
                    ->where('contact_tag.tag_id', $tagId)))
                ->whereHas('contact')
                ->with(['contact.tags:id,name'])
                ->lazyById(1000)
                ->each(function (Subscription $s) use ($csv, $fields) {
                    $contact = $s->contactOrFail();
                    $csv->insertOne(array_merge([
                        $contact->email,
                        $contact->first_name,
                        $contact->last_name,
                        $contact->phone,
                        $s->status->label(),
                        $s->subscribed_at?->toIso8601String(),
                        $s->unsubscribed_at?->toIso8601String(),
                        $s->consent_source,
                        $s->consent_detail,
                        $s->consent_at?->toIso8601String(),
                        $s->consent_ip,
                        $contact->tags->pluck('name')->implode(', '),
                    ], $fields->map(fn (MergeField $f) => $contact->fields[$f->key] ?? null)->all()));
                });
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
