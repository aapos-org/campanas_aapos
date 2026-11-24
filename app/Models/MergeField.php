<?php

namespace App\Models;

use App\Enums\FieldType;
use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\MergeFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Custom contact field (organization-wide). Values: contacts.fields[$key].
 *
 * @property int $id
 * @property int $organization_id
 * @property string $key
 * @property string $label
 * @property FieldType $type
 * @property list<string> $options
 * @property bool $required
 * @property int $position
 * @property string|null $mailchimp_tag
 */
#[Fillable(['organization_id', 'key', 'label', 'type', 'options', 'required', 'position', 'mailchimp_tag'])]
class MergeField extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<MergeFieldFactory> */
    use HasFactory;

    /** Keys reserved for the built-in contact columns. */
    public const array RESERVED_KEYS = ['email', 'first_name', 'last_name', 'phone', 'tags', 'status', 'language'];

    public const int KEY_MAX_LENGTH = 50;

    /** @var array<string, mixed> */
    protected $attributes = [
        'options' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'options' => 'array',
            'required' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * Normalizes a text into a key fragment: ASCII, lowercase, `[a-z0-9_]` only,
     * no repeated or edge underscores, at most 50 characters. May return ''.
     */
    public static function slugKey(string $text): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($text)));

        return trim(substr(trim($slug, '_'), 0, self::KEY_MAX_LENGTH), '_');
    }

    /**
     * Builds a key for a field named $label that is not reserved nor in $taken
     * (adds `_2`, `_3`… when needed). Pure: callers supply the keys in use.
     *
     * @param  iterable<string>  $taken
     */
    public static function keyFor(string $label, iterable $taken = [], ?string $fallback = null): string
    {
        $base = self::slugKey($label);

        if ($base === '' && $fallback !== null) {
            $base = self::slugKey($fallback);
        }

        if ($base === '') {
            $base = 'campo';
        }

        $used = array_flip([...self::RESERVED_KEYS, ...$taken]);
        $key = $base;

        for ($n = 2; isset($used[$key]); $n++) {
            $suffix = '_'.$n;
            $key = rtrim(substr($base, 0, self::KEY_MAX_LENGTH - strlen($suffix)), '_').$suffix;
        }

        return $key;
    }

    /**
     * Key for a new field of the organization, unique among its existing fields.
     */
    public static function generateKey(string $label, int $organizationId, ?string $fallback = null): string
    {
        /** @var list<string> $taken */
        $taken = self::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->pluck('key')->all();

        return self::keyFor($label, $taken, $fallback);
    }
}
