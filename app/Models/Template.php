<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 * @property string $editor
 * @property string|null $mjml
 * @property array<string, mixed>|null $design
 * @property string|null $html
 * @property string $source
 * @property string|null $mailchimp_id
 * @property int|null $created_by
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'name', 'description', 'editor', 'mjml', 'design', 'html', 'source', 'mailchimp_id', 'created_by'])]
class Template extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<TemplateFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['design' => 'array'];
    }
}
