<?php

namespace App\Services\Imports;

use App\Enums\ContactStatus;
use App\Enums\ImportStatus;
use App\Enums\SuppressionReason;
use App\Models\Contact;
use App\Models\ImportBatch;
use App\Models\MergeField;
use App\Models\Subscription;
use App\Services\Contacts\ContactWriter;
use App\Services\Contacts\FieldNormalizer;
use App\Services\Contacts\SuppressionService;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Imports contacts from a mapped CSV/XLSX into an audience.
 *
 * Rules (tested in tests/Feature/Imports):
 *  - Emails are normalized; invalid ones fail with a reason.
 *  - Duplicates inside the file: the first occurrence wins, the rest are skipped.
 *  - Suppressed addresses (global or audience) are NEVER subscribed: the row is reported.
 *  - Unsubscribed/cleaned memberships are never reactivated.
 *  - "subscribed" requires a consent statement given when starting the import.
 *  - Invalid optional values (phone, date...) are dropped and reported as warnings.
 */
class ContactImporter
{
    public const int CHUNK_SIZE = 500;

    /** @var array<string, true> emails already seen in this file */
    private array $seen = [];

    /** @var Collection<string, MergeField> */
    private Collection $fields;

    public function __construct(
        private readonly FieldNormalizer $normalizer,
        private readonly SuppressionService $suppressions,
        private readonly ContactWriter $writer,
    ) {}

    public function run(ImportBatch $batch): void
    {
        $this->seen = [];
        $this->fields = MergeField::query()->get()->keyBy('key');

        $batch->update(['status' => ImportStatus::Processing, 'started_at' => now(), 'error' => null]);

        try {
            $reader = SpreadsheetReader::for(Storage::disk('local')->path((string) $batch->file_path), (string) $batch->original_filename);
            $buffer = [];

            foreach ($reader->rows() as $number => $cells) {
                if ($number === 1) {
                    continue; // headers
                }

                $buffer[$number] = $cells;

                if (count($buffer) >= self::CHUNK_SIZE) {
                    if (! $this->processChunk($batch, $buffer)) {
                        return; // cancelled
                    }
                    $buffer = [];
                }
            }

            if ($buffer !== [] && ! $this->processChunk($batch, $buffer)) {
                return;
            }

            $batch->update(['status' => ImportStatus::Completed, 'finished_at' => now()]);

            Audit::record('imports.completed', 'Importación de contactos completada', $batch, [
                'audience_id' => $batch->audience_id,
                'file' => $batch->original_filename,
                'created' => $batch->created_count,
                'updated' => $batch->updated_count,
                'skipped' => $batch->skipped_count,
                'failed' => $batch->failed_count,
            ], $batch->user, $batch->organization_id);
        } catch (Throwable $e) {
            $batch->update(['status' => ImportStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);

            throw $e;
        } finally {
            // The uploaded file holds personal data: remove it once processed.
            if ($batch->fresh()?->status->isFinished() && $batch->file_path) {
                Storage::disk('local')->delete($batch->file_path);
            }
        }
    }

    /**
     * @param  array<int, list<string>>  $rows  row number => cells
     * @return bool false if the import was cancelled
     */
    private function processChunk(ImportBatch $batch, array $rows): bool
    {
        if ($batch->fresh()?->status === ImportStatus::Cancelled) {
            return false;
        }

        $counters = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $report = [];
        $parsed = [];

        // 1. Parse and validate rows (no queries).
        foreach ($rows as $number => $cells) {
            $row = $this->mapRow($batch->mapping, $cells);
            $email = Contact::normalizeEmail($row['email'] ?? '');

            if ($email === '' || mb_strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $counters['failed']++;
                $report[] = $this->reportRow($batch, $number, $email ?: null, 'failed', $email === '' ? 'Falta el correo electrónico.' : "Correo inválido: «{$email}».", $cells);

                continue;
            }

            if (isset($this->seen[$email])) {
                $counters['skipped']++;
                $report[] = $this->reportRow($batch, $number, $email, 'skipped', 'Correo duplicado en el archivo (se usó la primera aparición).', $cells);

                continue;
            }

            $this->seen[$email] = true;
            [$attributes, $warnings] = $this->normalizeAttributes($row);

            foreach ($warnings as $warning) {
                $report[] = $this->reportRow($batch, $number, $email, 'warning', $warning, $cells);
            }

            $parsed[$email] = ['number' => $number, 'attributes' => $attributes, 'tags' => $row['tags'], 'cells' => $cells];
        }

        // 2. Apply valid rows with bulk lookups, then store the report and counters, atomically.
        DB::transaction(function () use ($batch, $rows, $parsed, &$counters, &$report) {
            if ($parsed !== []) {
                $this->applyRows($batch, $parsed, $counters, $report);
            }

            foreach (array_chunk($report, 500) as $chunk) {
                DB::table('import_batch_rows')->insert($chunk);
            }

            DB::table('import_batches')->where('id', $batch->id)->incrementEach([
                'processed_rows' => count($rows),
                'created_count' => $counters['created'],
                'updated_count' => $counters['updated'],
                'skipped_count' => $counters['skipped'],
                'failed_count' => $counters['failed'],
            ], ['updated_at' => now()]);
        });

        return true;
    }

    /**
     * @param  array<string, array{number: int, attributes: array<string, mixed>, tags: list<string>, cells: list<string>}>  $parsed
     * @param  array{created: int, updated: int, skipped: int, failed: int}  $counters
     * @param  list<array<string, mixed>>  $report
     */
    private function applyRows(ImportBatch $batch, array $parsed, array &$counters, array &$report): void
    {
        $emails = array_keys($parsed);
        $options = $batch->options;
        $targetStatus = ContactStatus::from((string) ($options['status'] ?? ContactStatus::NonSubscribed->value));
        $updateExisting = (bool) ($options['update_existing'] ?? true);

        $contacts = Contact::query()->withTrashed()->whereIn('email', $emails)->get()->keyBy('email');
        $subscriptions = Subscription::query()
            ->where('audience_id', $batch->audience_id)
            ->whereIn('contact_id', $contacts->pluck('id'))
            ->get()
            ->keyBy('contact_id');
        $suppressed = $this->suppressions->suppressedAmong($emails, $batch->audience_id);
        $tagsByContact = [];

        foreach ($parsed as $email => $item) {
            /** @var Contact|null $contact */
            $contact = $contacts->get($email);
            $isNewContact = $contact === null;

            if ($contact === null) {
                $contact = Contact::create(['email' => $email, 'organization_id' => $batch->organization_id] + $item['attributes']);
            } else {
                if ($contact->trashed()) {
                    $contact->restore();
                }

                if ($updateExisting) {
                    $this->updateContact($contact, $item['attributes']);
                }
            }

            $tagsByContact[$contact->id] = array_values(array_map(strval(...), array_merge((array) ($options['tags'] ?? []), $item['tags'])));

            /** @var Subscription|null $subscription */
            $subscription = $subscriptions->get($contact->id);
            $outcome = $this->applyStatus($batch, $contact, $subscription, $targetStatus, $suppressed[$email] ?? null);

            if ($outcome['skipped'] !== null) {
                $counters['skipped']++;
                $report[] = $this->reportRow($batch, $item['number'], $email, 'skipped', $outcome['skipped'], $item['cells']);
            } elseif ($subscription === null || $isNewContact) {
                $counters['created']++;
            } else {
                $counters['updated']++;
            }
        }

        $this->attachTags($tagsByContact);
    }

    /**
     * @return array{skipped: string|null}
     */
    private function applyStatus(ImportBatch $batch, Contact $contact, ?Subscription $subscription, ContactStatus $target, ?SuppressionReason $suppressedBy): array
    {
        $options = $batch->options;

        // Importing a list of unsubscribes feeds the suppression list.
        if ($target === ContactStatus::Unsubscribed) {
            if ($subscription === null) {
                Subscription::create([
                    'organization_id' => $batch->organization_id,
                    'audience_id' => $batch->audience_id,
                    'contact_id' => $contact->id,
                    'status' => ContactStatus::Unsubscribed,
                    'unsubscribed_at' => now(),
                    'unsubscribe_reason' => 'import',
                ]);
            }
            $this->suppressions->suppress($contact->email, SuppressionReason::Unsubscribed, $batch->audience, 'import', $batch->original_filename);

            return ['skipped' => null];
        }

        if ($suppressedBy !== null) {
            if ($subscription === null) {
                Subscription::create([
                    'organization_id' => $batch->organization_id,
                    'audience_id' => $batch->audience_id,
                    'contact_id' => $contact->id,
                    'status' => in_array($suppressedBy, [SuppressionReason::HardBounce, SuppressionReason::Cleaned], true) ? ContactStatus::Cleaned : ContactStatus::Unsubscribed,
                    'unsubscribed_at' => now(),
                    'unsubscribe_reason' => $suppressedBy->value,
                ]);
            }

            return ['skipped' => "Está en la lista de supresión ({$suppressedBy->label()}): no se suscribió."];
        }

        if ($subscription !== null && $subscription->status->isTerminal()) {
            return ['skipped' => "Estaba «{$subscription->status->label()}» en la audiencia: no se reactivó."];
        }

        if ($subscription !== null && $subscription->status === ContactStatus::Subscribed) {
            return ['skipped' => null]; // already subscribed: keep original consent
        }

        $attributes = ['status' => $target];

        if ($target === ContactStatus::Subscribed) {
            $attributes += [
                'subscribed_at' => now(),
                'consent_at' => now(),
                'consent_source' => 'import',
                'consent_detail' => trim(($options['consent_detail'] ?? '').' · Archivo: '.$batch->original_filename, ' ·'),
            ];
        }

        if ($subscription === null) {
            Subscription::create($attributes + [
                'organization_id' => $batch->organization_id,
                'audience_id' => $batch->audience_id,
                'contact_id' => $contact->id,
            ]);
        } elseif ($subscription->status !== $target && $target !== ContactStatus::NonSubscribed) {
            $subscription->update($attributes);
        }

        return ['skipped' => null];
    }

    /**
     * @param  array<string, string>  $mapping  column index => target
     * @param  list<string>  $cells
     * @return array{email?: string, first_name?: string, last_name?: string, phone?: string, fields: array<string, string>, tags: list<string>}
     */
    private function mapRow(array $mapping, array $cells): array
    {
        $fields = [];
        $tags = [];
        $row = [];

        foreach ($mapping as $index => $target) {
            $value = trim($cells[(int) $index] ?? '');

            if ($target === 'ignore' || $value === '') {
                continue;
            }

            if ($target === 'tags') {
                foreach (preg_split('/[,;|]/', $value) ?: [] as $tag) {
                    if (trim($tag) !== '') {
                        $tags[] = trim($tag);
                    }
                }
            } elseif (str_starts_with($target, 'field:')) {
                $fields[substr($target, 6)] = $value;
            } elseif (in_array($target, ['email', 'first_name', 'last_name', 'phone'], true)) {
                $row[$target] = $value;
            }
        }

        return $row + ['fields' => $fields, 'tags' => $tags];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function normalizeAttributes(array $row): array
    {
        $attributes = [];
        $warnings = [];

        foreach (['first_name', 'last_name'] as $key) {
            if (isset($row[$key])) {
                $attributes[$key] = mb_substr((string) $row[$key], 0, 255);
            }
        }

        if (isset($row['phone'])) {
            [$phone, $error] = $this->normalizer->phone((string) $row['phone']);
            $error ? $warnings[] = $error.' Se ignoró ese dato.' : $attributes['phone'] = $phone;
        }

        foreach ($row['fields'] as $key => $value) {
            $definition = $this->fields->get($key);

            if ($definition === null) {
                continue;
            }

            [$normalized, $error] = $this->normalizer->normalize($definition, $value);
            $error ? $warnings[] = $error.' Se ignoró ese dato.' : $attributes['fields'][$key] = $normalized;
        }

        return [$attributes, $warnings];
    }

    /**
     * Only non-empty incoming values overwrite existing data.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateContact(Contact $contact, array $attributes): void
    {
        $fields = $attributes['fields'] ?? [];
        unset($attributes['fields']);

        $contact->fill($attributes);

        if ($fields !== []) {
            $contact->fields = array_merge($contact->fields, $fields);
        }

        if ($contact->isDirty()) {
            $contact->save();
        }
    }

    /**
     * @param  array<int, list<string>>  $tagsByContact
     */
    private function attachTags(array $tagsByContact): void
    {
        $byTagSet = [];
        foreach ($tagsByContact as $contactId => $tags) {
            if ($tags !== []) {
                $byTagSet[json_encode(array_values(array_unique($tags)))][] = $contactId;
            }
        }

        foreach ($byTagSet as $tagsJson => $contactIds) {
            $this->writer->attachTags($contactIds, array_values(array_map(strval(...), (array) json_decode((string) $tagsJson, true))));
        }
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, mixed>
     */
    private function reportRow(ImportBatch $batch, int $number, ?string $email, string $outcome, string $reason, array $cells): array
    {
        return [
            'import_batch_id' => $batch->id,
            'row_number' => $number,
            'email' => $email !== null ? mb_substr($email, 0, 255) : null,
            'outcome' => $outcome,
            'reason' => mb_substr($reason, 0, 255),
            'data' => json_encode(array_combine(
                array_map(fn ($i) => $batch->headers[$i] ?? "col{$i}", array_keys($cells)),
                $cells,
            ), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ];
    }
}
