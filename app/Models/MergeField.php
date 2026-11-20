<?php

namespace App\Models;

use App\Enums\FieldType;
use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\MergeFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
