<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\AudienceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 * @property string|null $default_from_name
 * @property string|null $default_from_email
 * @property string|null $permission_reminder
 * @property bool $double_opt_in
 * @property string|null $mailchimp_id
 * @property string|null $mailchimp_web_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'name', 'description', 'default_from_name', 'default_from_email', 'permission_reminder', 'double_opt_in', 'mailchimp_id', 'mailchimp_web_id'])]
class Audience extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AudienceFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'double_opt_in' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return BelongsToMany<Contact, $this, Subscription, 'subscription'>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'audience_contact')
            ->using(Subscription::class)
            ->as('subscription')
            ->withPivot(['id', 'status', 'subscribed_at', 'unsubscribed_at', 'consent_at', 'consent_source'])
            ->withTimestamps();
    }
}
