<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\MailDriver;
use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\MailConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A sending account (Gmail SMTP, Gmail API or ElasticEmail).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property MailDriver $driver
 * @property string $from_email
 * @property string|null $from_name
 * @property string|null $reply_to
 * @property array<string, mixed>|null $credentials
 * @property int|null $daily_limit
 * @property bool $is_default
 * @property ConnectionStatus $status
 * @property Carbon|null $last_verified_at
 * @property string|null $last_error
 * @property array<string, mixed> $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'name', 'driver', 'from_email', 'from_name', 'reply_to', 'credentials', 'daily_limit', 'is_default', 'status', 'last_verified_at', 'last_error', 'meta'])]
#[Hidden(['credentials'])]
class MailConnection extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<MailConnectionFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'unverified',
        'meta' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'driver' => MailDriver::class,
            'status' => ConnectionStatus::class,
            'credentials' => 'encrypted:array',
            'daily_limit' => 'integer',
            'is_default' => 'boolean',
            'last_verified_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return HasMany<MailConnectionDailyUsage, $this>
     */
    public function dailyUsage(): HasMany
    {
        return $this->hasMany(MailConnectionDailyUsage::class);
    }

    public function credential(string $key): mixed
    {
        return $this->credentials[$key] ?? null;
    }

    public function fromAddress(): string
    {
        return $this->from_name ? "{$this->from_name} <{$this->from_email}>" : $this->from_email;
    }
}
