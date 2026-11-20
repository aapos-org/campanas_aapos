<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant root. The app serves a single organization today, but every main table
 * carries `organization_id` so it can become multi-tenant later.
 *
 * `limits` keys (all optional, null = unlimited):
 *  - contacts: int            max stored contacts
 *  - monthly_sends: int       max emails sent per calendar month
 *  - daily_sends_per_connection: int  default daily cap per sending connection
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $locale
 * @property string|null $physical_address
 * @property string|null $default_from_name
 * @property string|null $default_from_email
 * @property array<string, mixed> $limits
 * @property array<string, mixed> $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'timezone', 'locale', 'physical_address', 'default_from_name', 'default_from_email', 'limits', 'settings'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'limits' => '{}',
        'settings' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
