<?php

namespace App\Models;

use App\Services\Limits\ContactLimit;
use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A person, unique per organization by normalized email.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phone
 * @property string|null $language
 * @property array<string, mixed> $fields
 * @property string|null $mailchimp_unique_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $full_name
 */
#[Fillable(['organization_id', 'email', 'first_name', 'last_name', 'phone', 'language', 'fields', 'mailchimp_unique_id'])]
class Contact extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ContactFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'fields' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Organization limit (runs after BelongsToOrganization has set organization_id).
        static::creating(function (self $contact) {
            app(ContactLimit::class)->ensureCanCreate((int) $contact->organization_id);
        });
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => self::normalizeEmail($value));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim(($this->first_name ?? '').' '.($this->last_name ?? '')));
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('created_at');
    }
}
