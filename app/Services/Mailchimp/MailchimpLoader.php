<?php

namespace App\Services\Mailchimp;

use App\Enums\ContactStatus;
use App\Enums\FieldType;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\MergeField;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Contacts\ContactWriter;
use App\Services\Contacts\FieldNormalizer;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Loads a Mailchimp snapshot (encrypted backup) into the application:
 * backup → imports_staging_records → normalization → final tables.
 *
 * Order (section 8.3): audiences → merge fields → contacts and statuses → suppressions → tags.
 * Segments, signup forms and automations are reported for manual reconstruction.
 * Templates, campaigns and reports stay in staging until their tables exist (Phase 4).
 *
 * Golden rules:
 *  - unsubscribed / cleaned / abuse complaints enter as permanent suppressions;
 *  - pending and transactional/archived are never treated as subscribed;
 *  - a local unsubscribe/clean/suppression is never reactivated by a re-run;
 *  - re-running is idempotent (mailchimp ids, normalized emails).
 *
 * Dry run: everything runs inside a transaction that is rolled back, so the report
 * shows exactly what a real run would insert.
 */
class MailchimpLoader
{
    private const int CHUNK = 1000;

    /** Mailchimp merge tags mapped to built-in contact columns. */
    private const array CORE_TAGS = ['FNAME' => 'first_name', 'LNAME' => 'last_name', 'PHONE' => 'phone'];

    /** @var Closure(string): void */
    private Closure $output;

    /** @var array<string, int> list id => audience id */
    private array $audiences = [];

    /** @var array<string, array<string, array{key: string, type: FieldType, options: list<string>}>> list id => tag => field */
    private array $fieldMap = [];

    /** @var array<string, mixed> */
    private array $stats = [];

    public function __construct(
        private readonly MailchimpBackup $backup,
        private readonly Organization $organization,
        private readonly FieldNormalizer $normalizer,
        private readonly ContactWriter $writer,
    ) {
        $this->output = fn (string $line) => null;
    }

    /**
     * @param  Closure(string): void  $output
     */
    public function onProgress(Closure $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * @return array<string, mixed> statistics for the report
     */
    public function run(bool $dryRun): array
    {
        $this->stats = [
            'snapshot' => $this->backup->snapshot,
            'dry_run' => $dryRun,
            'started_at' => now()->toIso8601String(),
            'audiences' => [],
            'fields_created' => 0,
            'fields_reused' => 0,
            'tags' => 0,
            'rejected' => [],
            'cross_audience_suppressed' => 0,
            'pending_staged' => [],
        ];

        DB::beginTransaction();

        try {
            $this->stage();
            $this->loadAudiences();
            $this->loadMergeFields();
            $this->loadMembers();
            $this->applyGlobalSuppressions();
            $this->stats['pending_staged'] = $this->countStaged(['template', 'campaign', 'segment', 'signup_form', 'automation']);
            $this->stats['finished_at'] = now()->toIso8601String();

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $this->stats;
    }

    // ------------------------------------------------------------------ staging

    private function stage(): void
    {
        ($this->output)('Cargando el respaldo en las tablas de staging…');

        $this->stageCollection('lists', 'lists', 'audience', null);

        foreach ($this->backupItems('lists', 'lists') as $list) {
            $id = (string) $list['id'];
            $this->stageCollection("lists/{$id}/merge_fields", 'merge_fields', 'merge_field', $id, fn ($i) => (string) $i['tag']);
            $this->stageCollection("lists/{$id}/segments", 'segments', 'segment', $id);
            $this->stageCollection("lists/{$id}/signup_forms", 'signup_forms', 'signup_form', $id, fn ($i) => (string) ($i['signup_form_id'] ?? md5((string) json_encode($i))));
            $this->stageCollection("lists/{$id}/members", 'members', 'member', $id);
        }

        $this->stageCollection('templates', 'templates', 'template', null);
        $this->stageCollection('campaigns', 'campaigns', 'campaign', null);
        $this->stageCollection('automations', 'automations', 'automation', null);
    }

    /**
     * @param  Closure(array<string, mixed>): string|null  $idOf
     */
    private function stageCollection(string $prefix, string $itemsKey, string $resource, ?string $listId, ?Closure $idOf = null): void
    {
        $idOf ??= fn (array $item) => (string) $item['id'];

        foreach ($this->backup->keys($prefix) as $key) {
            // Only direct pages of this collection (not nested resources).
            if (! preg_match('#^'.preg_quote($prefix, '#').'/(page-\d+|all)$#', $key)) {
                continue;
            }

            $rows = [];
            foreach ((array) ($this->backup->get($key)[$itemsKey] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $rows[] = [
                    'organization_id' => $this->organization->id,
                    'snapshot' => $this->backup->snapshot,
                    'resource' => $resource,
                    'list_id' => $listId,
                    'mailchimp_id' => mb_substr($idOf($item), 0, 128),
                    'payload' => json_encode($item, JSON_UNESCAPED_UNICODE),
                    'status' => 'staged',
                    'reason' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('imports_staging_records')->upsert(
                    $chunk,
                    ['organization_id', 'snapshot', 'resource', 'list_id', 'mailchimp_id'],
                    ['payload', 'status', 'reason', 'updated_at'],
                );
            }
        }
    }

    // ------------------------------------------------------------------ audiences & fields

    private function loadAudiences(): void
    {
        foreach ($this->staged('audience') as $row) {
            $list = $row['payload'];
            $audience = Audience::query()->withTrashed()->firstOrNew(['mailchimp_id' => (string) $list['id']]);

            $audience->fill([
                'organization_id' => $this->organization->id,
                'name' => $audience->exists ? $audience->name : (string) $list['name'],
                'mailchimp_web_id' => isset($list['web_id']) ? (string) $list['web_id'] : null,
                'permission_reminder' => $audience->permission_reminder ?? ($list['permission_reminder'] ?? null),
                'default_from_name' => $audience->default_from_name ?? ($list['campaign_defaults']['from_name'] ?? null),
                'default_from_email' => $audience->default_from_email ?? ($list['campaign_defaults']['from_email'] ?? null),
                'double_opt_in' => (bool) ($list['double_optin'] ?? true),
            ])->save();

            $this->audiences[(string) $list['id']] = $audience->id;
            $this->stats['audiences'][(string) $list['id']] = [
                'name' => $audience->name,
                'audience_id' => $audience->id,
                'mailchimp_stats' => array_intersect_key((array) ($list['stats'] ?? []), array_flip(['member_count', 'unsubscribe_count', 'cleaned_count'])),
                'by_mailchimp_status' => [],
                'by_local_status' => [],
                'contacts_created' => 0,
                'contacts_updated' => 0,
                'reactivation_prevented' => 0,
                'subscribed_without_optin_date' => 0,
                'field_warnings' => 0,
            ];
            $this->markStaged([$row['id']], 'imported');
        }

        ($this->output)(sprintf('Audiencias: %d', count($this->audiences)));
    }

    private function loadMergeFields(): void
    {
        $position = (int) MergeField::query()->max('position');

        foreach ($this->staged('merge_field') as $row) {
            $mf = $row['payload'];
            $listId = (string) $row['list_id'];
            $tag = (string) $mf['tag'];

            if ($tag === 'EMAIL' || isset(self::CORE_TAGS[$tag])) {
                $this->markStaged([$row['id']], 'skipped', 'Campo base del contacto');

                continue;
            }

            [$type, $options] = $this->fieldType($mf);
            $label = mb_substr((string) $mf['name'], 0, 100);
            $key = mb_substr(Str::snake(Str::ascii($label)) ?: Str::lower($tag), 0, 50);

            // Unify fields with the same Mailchimp tag or the same name across audiences.
            $field = MergeField::query()->where('mailchimp_tag', $tag)->where('label', $label)->first()
                ?? MergeField::query()->where('key', $key)->first();

            if ($field === null) {
                $field = MergeField::create([
                    'key' => in_array($key, ['email', 'first_name', 'last_name', 'phone', 'tags', 'status', 'language'], true) ? $key.'_mc' : $key,
                    'label' => $label,
                    'type' => $type,
                    'options' => $options,
                    'required' => false,
                    'position' => ++$position,
                    'mailchimp_tag' => $tag,
                ]);
                $this->stats['fields_created']++;
            } else {
                if ($field->type === FieldType::Select && $options !== []) {
                    $field->update(['options' => array_values(array_unique([...$field->options, ...$options]))]);
                }
                $this->stats['fields_reused']++;
            }

            $this->fieldMap[$listId][$tag] = ['key' => $field->key, 'type' => $field->type, 'options' => $field->fresh()->options ?? []];
            $this->markStaged([$row['id']], 'imported');
        }
    }

    /**
     * @param  array<string, mixed>  $mf
     * @return array{0: FieldType, 1: list<string>}
     */
    private function fieldType(array $mf): array
    {
        $choices = array_values(array_map('strval', (array) ($mf['options']['choices'] ?? [])));

        return match ((string) ($mf['type'] ?? 'text')) {
            'number' => [FieldType::Number, []],
            'date' => [FieldType::Date, []],
            'birthday' => [FieldType::Birthday, []],
            'phone' => [FieldType::Phone, []],
            'dropdown', 'radio' => $choices !== [] ? [FieldType::Select, $choices] : [FieldType::Text, []],
            default => [FieldType::Text, []],
        };
    }

    // ------------------------------------------------------------------ members

    private function loadMembers(): void
    {
        foreach ($this->audiences as $listId => $audienceId) {
            $audience = Audience::query()->findOrFail($audienceId);
            $processed = 0;

            DB::table('imports_staging_records')
                ->where('organization_id', $this->organization->id)
                ->where('snapshot', $this->backup->snapshot)
                ->where('resource', 'member')
                ->where('list_id', $listId)
                ->orderBy('id')
                ->chunkById(self::CHUNK, function (Collection $rows) use ($listId, $audience, &$processed) {
                    $chunk = [];
                    foreach ($rows as $r) {
                        /** @var array<string, mixed> $payload */
                        $payload = (array) json_decode((string) $r->payload, true);
                        $chunk[] = ['id' => (int) $r->id, 'payload' => $payload];
                    }
                    $this->loadMemberChunk($listId, $audience, $chunk);
                    $processed += $rows->count();
                    ($this->output)(sprintf('  «%s»: %d contactos procesados', $audience->name, $processed));
                });
        }
    }

    /**
     * @param  list<array{id: int, payload: array<string, mixed>}>  $rows
     */
    private function loadMemberChunk(string $listId, Audience $audience, array $rows): void
    {
        $stats = &$this->stats['audiences'][$listId];
        $parsed = [];

        foreach ($rows as $row) {
            $member = $row['payload'];
            $email = Contact::normalizeEmail((string) ($member['email_address'] ?? ''));
            $mcStatus = (string) ($member['status'] ?? 'unknown');
            $stats['by_mailchimp_status'][$mcStatus] = ($stats['by_mailchimp_status'][$mcStatus] ?? 0) + 1;

            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
                $this->reject($row['id'], $email, $audience->name, 'Correo inválido');

                continue;
            }

            $parsed[$email] = ['staging_id' => $row['id'], 'member' => $member] + $this->mapMember($listId, $member, $stats);
        }

        if ($parsed === []) {
            return;
        }

        $emails = array_keys($parsed);
        $contacts = Contact::query()->withTrashed()->whereIn('email', $emails)->get()->keyBy('email');
        $subscriptions = Subscription::query()->where('audience_id', $audience->id)
            ->whereIn('contact_id', $contacts->pluck('id'))->get()->keyBy('contact_id');
        $suppressed = DB::table('suppressions')
            ->where('organization_id', $this->organization->id)
            ->whereIn('email', $emails)
            ->where(fn ($q) => $q->whereNull('audience_id')->orWhere('audience_id', $audience->id))
            ->pluck('reason', 'email');

        $newSuppressions = [];
        $tags = [];
        $imported = [];

        foreach ($parsed as $email => $item) {
            /** @var Contact|null $contact */
            $contact = $contacts->get($email);

            if ($contact === null) {
                $contact = Contact::create(['organization_id' => $this->organization->id, 'email' => $email] + $item['contact']);
                $stats['contacts_created']++;
            } else {
                if ($contact->trashed()) {
                    $contact->restore();
                }
                $this->fillMissing($contact, $item['contact']);
                $stats['contacts_updated']++;
            }

            /** @var Subscription|null $subscription */
            $subscription = $subscriptions->get($contact->id);
            [$status, $reasonForKeep] = $this->resolveStatus($item['status'], $subscription, $suppressed[$email] ?? null);

            if ($reasonForKeep !== null) {
                $stats['reactivation_prevented']++;
            }

            $attributes = ['status' => $status, 'mailchimp_id' => (string) ($item['member']['id'] ?? md5($email))] + $item['subscription'];

            if ($subscription === null) {
                Subscription::create($attributes + [
                    'organization_id' => $this->organization->id,
                    'audience_id' => $audience->id,
                    'contact_id' => $contact->id,
                ]);
            } elseif ($reasonForKeep === null) {
                $subscription->fill($attributes)->save();
            }

            $stats['by_local_status'][$status->value] = ($stats['by_local_status'][$status->value] ?? 0) + 1;

            if ($item['suppression'] !== null) {
                $newSuppressions[] = [
                    'organization_id' => $this->organization->id,
                    'email' => $email,
                    'audience_id' => $item['suppression']->isAlwaysGlobal() ? null : $audience->id,
                    'reason' => $item['suppression']->value,
                    'source' => 'mailchimp',
                    'detail' => $item['suppression_detail'],
                    'created_at' => $item['suppressed_at'] ?? now(),
                    'updated_at' => now(),
                ];
            }

            if ($item['tags'] !== []) {
                $tags[$contact->id] = $item['tags'];
            }

            $imported[] = $item['staging_id'];
        }

        foreach (array_chunk($newSuppressions, 500) as $chunk) {
            DB::table('suppressions')->insertOrIgnore($chunk);
        }

        $this->attachTags($tags);
        $this->markStaged($imported, 'imported');
    }

    /**
     * @param  array<string, mixed>  $member
     * @param  array<string, mixed>  $stats
     * @return array{contact: array<string, mixed>, subscription: array<string, mixed>, status: ContactStatus, suppression: SuppressionReason|null, suppression_detail: string|null, suppressed_at: Carbon|null, tags: list<string>}
     */
    private function mapMember(string $listId, array $member, array &$stats): array
    {
        $merge = (array) ($member['merge_fields'] ?? []);
        $contact = ['fields' => []];

        foreach (self::CORE_TAGS as $tag => $column) {
            $value = trim((string) ($merge[$tag] ?? ''));

            if ($value === '') {
                continue;
            }

            if ($column === 'phone') {
                [$phone, $error] = $this->normalizer->phone($value);
                $error === null ? $contact['phone'] = $phone : $stats['field_warnings']++;
            } else {
                $contact[$column] = mb_substr($value, 0, 255);
            }
        }

        foreach ($this->fieldMap[$listId] ?? [] as $tag => $field) {
            $value = $merge[$tag] ?? null;

            if (is_array($value)) { // address
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }

            if ($value === null || $value === '') {
                continue;
            }

            // Mailchimp birthdays are MM/DD.
            if ($field['type'] === FieldType::Birthday && preg_match('#^(\d{1,2})/(\d{1,2})$#', (string) $value, $m)) {
                $value = sprintf('%02d-%02d', (int) $m[1], (int) $m[2]);
            }

            [$normalized, $error] = $this->normalizer->normalizeType($field['type'], $value, $field['options']);
            $error === null ? $contact['fields'][$field['key']] = $normalized : $stats['field_warnings']++;
        }

        if (filled($member['language'] ?? null)) {
            $contact['language'] = mb_substr((string) $member['language'], 0, 10);
        }

        if (filled($member['unique_email_id'] ?? null)) {
            $contact['mailchimp_unique_id'] = (string) $member['unique_email_id'];
        }

        $mcStatus = (string) ($member['status'] ?? '');
        $optinAt = $this->date($member['timestamp_opt'] ?? null);
        $signupAt = $this->date($member['timestamp_signup'] ?? null);
        $changedAt = $this->date($member['last_changed'] ?? null);
        $unsubReason = mb_strtolower((string) ($member['unsubscribe_reason'] ?? ''));
        $isComplaint = str_contains($unsubReason, 'abuse') || str_contains($unsubReason, 'spam');

        [$status, $suppression] = match ($mcStatus) {
            'subscribed' => [ContactStatus::Subscribed, null],
            'unsubscribed' => [ContactStatus::Unsubscribed, $isComplaint ? SuppressionReason::Complaint : SuppressionReason::Unsubscribed],
            'cleaned' => [ContactStatus::Cleaned, SuppressionReason::Cleaned],
            'pending' => [ContactStatus::Pending, null],
            default => [ContactStatus::NonSubscribed, null], // transactional, archived
        };

        if ($status === ContactStatus::Subscribed && $optinAt === null && $signupAt === null) {
            $stats['subscribed_without_optin_date']++;
        }

        $source = trim((string) ($member['source'] ?? ''));
        // Pending / transactional / archived people never confirmed: no consent is recorded.
        $hasConsent = in_array($status, [ContactStatus::Subscribed, ContactStatus::Unsubscribed, ContactStatus::Cleaned], true);

        return [
            'contact' => $contact,
            'status' => $status,
            'subscription' => [
                'subscribed_at' => $status === ContactStatus::Subscribed ? ($optinAt ?? $signupAt ?? $changedAt) : null,
                'unsubscribed_at' => $status->isTerminal() ? $changedAt : null,
                'unsubscribe_reason' => $status->isTerminal() ? ($member['unsubscribe_reason'] ?? $mcStatus) : null,
                'consent_at' => $hasConsent ? ($optinAt ?? $signupAt) : null,
                'consent_ip' => $hasConsent ? ($this->ip($member['ip_opt'] ?? null) ?? $this->ip($member['ip_signup'] ?? null)) : null,
                'consent_source' => $hasConsent ? 'mailchimp' : null,
                'consent_detail' => mb_substr(trim(implode(' · ', array_filter([
                    'Migrado de Mailchimp',
                    $source !== '' ? "origen: {$source}" : null,
                    $signupAt ? 'alta: '.$signupAt->toDateString() : null,
                    $optinAt ? 'confirmación: '.$optinAt->toDateString() : null,
                ]))), 0, 500),
            ],
            'suppression' => $suppression,
            'suppression_detail' => $suppression !== null ? mb_substr('Mailchimp: '.$mcStatus.($unsubReason !== '' ? " ({$unsubReason})" : ''), 0, 500) : null,
            'suppressed_at' => $suppression !== null ? $changedAt : null,
            'tags' => array_values(array_filter(array_map(
                fn ($t) => is_array($t) ? trim((string) ($t['name'] ?? '')) : '',
                (array) ($member['tags'] ?? []),
            ))),
        ];
    }

    /**
     * Never reactivates: a local terminal status or an existing suppression wins over Mailchimp.
     *
     * @return array{0: ContactStatus, 1: string|null} [status, reason the local status was kept]
     */
    private function resolveStatus(ContactStatus $fromMailchimp, ?Subscription $local, ?string $suppressedReason): array
    {
        if ($fromMailchimp->isTerminal()) {
            return [$fromMailchimp, null];
        }

        if ($local !== null && $local->status->isTerminal()) {
            return [$local->status, 'local_terminal'];
        }

        if ($suppressedReason !== null && in_array($fromMailchimp, [ContactStatus::Subscribed, ContactStatus::Pending], true)) {
            $reason = SuppressionReason::tryFrom($suppressedReason);

            return [
                in_array($reason, [SuppressionReason::HardBounce, SuppressionReason::Cleaned], true) ? ContactStatus::Cleaned : ContactStatus::Unsubscribed,
                'suppressed',
            ];
        }

        return [$fromMailchimp, null];
    }

    /**
     * Global suppressions (bounces, complaints) also affect the person's other audiences.
     */
    private function applyGlobalSuppressions(): void
    {
        $affected = DB::update(<<<'SQL'
            UPDATE audience_contact ac
            SET status = CASE WHEN s.reason IN ('hard_bounce', 'cleaned') THEN 'cleaned' ELSE 'unsubscribed' END,
                unsubscribed_at = COALESCE(ac.unsubscribed_at, now()),
                unsubscribe_reason = s.reason,
                updated_at = now()
            FROM contacts c, suppressions s
            WHERE ac.contact_id = c.id
              AND s.organization_id = c.organization_id
              AND s.email = c.email
              AND s.audience_id IS NULL
              AND c.organization_id = ?
              AND ac.status IN ('subscribed', 'pending', 'non_subscribed')
            SQL, [$this->organization->id]);

        $this->stats['cross_audience_suppressed'] = $affected;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Only fills values that are empty locally (a re-run never overwrites local edits).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fillMissing(Contact $contact, array $attributes): void
    {
        foreach (['first_name', 'last_name', 'phone', 'language', 'mailchimp_unique_id'] as $column) {
            if (blank($contact->getAttribute($column)) && filled($attributes[$column] ?? null)) {
                $contact->setAttribute($column, $attributes[$column]);
            }
        }

        $contact->fields = array_merge((array) ($attributes['fields'] ?? []), $contact->fields);

        if ($contact->isDirty()) {
            $contact->save();
        }
    }

    /**
     * @param  array<int, list<string>>  $tagsByContact
     */
    private function attachTags(array $tagsByContact): void
    {
        $names = array_values(array_unique(array_merge(...array_values($tagsByContact ?: [[]]))));

        if ($names === []) {
            return;
        }

        $ids = array_combine(array_map('mb_strtolower', $names), $this->writer->tagIds($names));
        $rows = [];

        foreach ($tagsByContact as $contactId => $tags) {
            foreach ($tags as $tag) {
                $rows[] = ['contact_id' => $contactId, 'tag_id' => $ids[mb_strtolower($tag)], 'created_at' => now()];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('contact_tag')->insertOrIgnore($chunk);
        }

        $this->stats['tags'] = DB::table('tags')->where('organization_id', $this->organization->id)->count();
    }

    private function reject(int $stagingId, string $email, string $audience, string $reason): void
    {
        $this->markStaged([$stagingId], 'rejected', $reason);
        $this->stats['rejected'][] = ['email' => $email, 'audience' => $audience, 'reason' => $reason];
    }

    /**
     * @param  list<int>  $ids
     */
    private function markStaged(array $ids, string $status, ?string $reason = null): void
    {
        foreach (array_chunk($ids, 1000) as $chunk) {
            DB::table('imports_staging_records')->whereIn('id', $chunk)->update(['status' => $status, 'reason' => $reason, 'updated_at' => now()]);
        }
    }

    /**
     * @return \Generator<int, array{id: int, list_id: string|null, payload: array<string, mixed>}>
     */
    private function staged(string $resource): \Generator
    {
        $rows = DB::table('imports_staging_records')
            ->where('organization_id', $this->organization->id)
            ->where('snapshot', $this->backup->snapshot)
            ->where('resource', $resource)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            yield (int) $row->id => [
                'id' => (int) $row->id,
                'list_id' => $row->list_id !== null ? (string) $row->list_id : null,
                'payload' => (array) json_decode((string) $row->payload, true),
            ];
        }
    }

    /**
     * @param  list<string>  $resources
     * @return array<string, int>
     */
    private function countStaged(array $resources): array
    {
        return DB::table('imports_staging_records')
            ->where('organization_id', $this->organization->id)
            ->where('snapshot', $this->backup->snapshot)
            ->whereIn('resource', $resources)
            ->selectRaw('resource, count(*) as total')
            ->groupBy('resource')
            ->pluck('total', 'resource')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function backupItems(string $prefix, string $itemsKey): array
    {
        $items = [];
        foreach ($this->backup->keys($prefix) as $key) {
            if (preg_match('#^'.preg_quote($prefix, '#').'/page-\d+$#', $key)) {
                array_push($items, ...array_values((array) ($this->backup->get($key)[$itemsKey] ?? [])));
            }
        }

        return $items;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function ip(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }
}
