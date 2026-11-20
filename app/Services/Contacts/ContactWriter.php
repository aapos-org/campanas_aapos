<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\MergeField;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates/updates contacts from the UI: normalizes email, validates custom fields and syncs tags.
 */
class ContactWriter
{
    public function __construct(private readonly FieldNormalizer $normalizer) {}

    /**
     * @param  array<string, mixed>  $data  email, first_name, last_name, phone, fields
     *
     * @throws ValidationException
     */
    public function save(?Contact $contact, array $data): Contact
    {
        $email = Contact::normalizeEmail((string) $data['email']);

        $duplicateQuery = Contact::query()->withTrashed()->where('email', $email);

        if ($contact !== null) {
            $duplicateQuery->whereKeyNot($contact->id);
        }

        $duplicate = $duplicateQuery->first();

        if ($duplicate !== null && ! $duplicate->trashed()) {
            throw ValidationException::withMessages(['email' => 'Ya existe un contacto con este correo.']);
        }

        $phone = null;
        if (filled($data['phone'] ?? null)) {
            [$phone, $error] = $this->normalizer->phone((string) $data['phone']);

            if ($error !== null) {
                throw ValidationException::withMessages(['phone' => $error]);
            }
        }

        $fields = $this->normalizeFields((array) ($data['fields'] ?? []), $contact->fields ?? []);

        // A soft-deleted contact with the same email is restored instead of duplicated.
        $contact ??= $duplicate?->trashed() ? tap($duplicate)->restore() : new Contact;

        $contact->fill([
            'email' => $email,
            'first_name' => $this->clean($data['first_name'] ?? null),
            'last_name' => $this->clean($data['last_name'] ?? null),
            'phone' => $phone,
            'fields' => $fields,
        ])->save();

        return $contact;
    }

    /**
     * @param  iterable<int, int>  $contactIds
     * @param  list<string>  $names
     */
    public function attachTags(iterable $contactIds, array $names): int
    {
        $tagIds = $this->tagIds($names);
        $rows = [];

        foreach ($contactIds as $contactId) {
            foreach ($tagIds as $tagId) {
                $rows[] = ['contact_id' => $contactId, 'tag_id' => $tagId, 'created_at' => now()];
            }
        }

        $inserted = 0;
        foreach (array_chunk($rows, 1000) as $chunk) {
            $inserted += DB::table('contact_tag')->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * @param  iterable<int, int>  $contactIds
     * @param  array<int, int>  $tagIds
     */
    public function detachTags(iterable $contactIds, array $tagIds): int
    {
        $ids = [];
        foreach ($contactIds as $id) {
            $ids[] = $id;
        }

        return DB::table('contact_tag')
            ->whereIn('contact_id', $ids)
            ->whereIn('tag_id', $tagIds)
            ->delete();
    }

    /**
     * Finds or creates tags by name (case-insensitive, trimmed). Returns their ids.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public function tagIds(array $names): array
    {
        $names = collect($names)
            ->map(fn ($n) => mb_substr(trim((string) $n), 0, 100))
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n))
            ->values();

        if ($names->isEmpty()) {
            return [];
        }

        $existing = Tag::query()
            ->whereIn(DB::raw('lower(name)'), $names->map(fn ($n) => mb_strtolower($n))->all())
            ->get(['id', 'name'])
            ->keyBy(fn (Tag $t) => mb_strtolower($t->name));

        $ids = [];
        foreach ($names as $name) {
            $ids[] = $existing->get(mb_strtolower($name))->id
                ?? Tag::query()->firstOrCreate(['name' => $name])->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function normalizeFields(array $input, array $current): array
    {
        $definitions = MergeField::query()->get()->keyBy('key');
        $errors = [];
        $fields = $current;

        foreach ($definitions as $key => $definition) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            [$value, $error] = $this->normalizer->normalize($definition, $input[$key]);

            if ($error !== null) {
                $errors["fields.{$key}"] = $error;
            } elseif ($value === null && $definition->required) {
                $errors["fields.{$key}"] = "El campo {$definition->label} es obligatorio.";
            } elseif ($value === null) {
                unset($fields[$key]);
            } else {
                $fields[$key] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $fields;
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' || $value === null ? null : mb_substr($value, 0, 255);
    }
}
