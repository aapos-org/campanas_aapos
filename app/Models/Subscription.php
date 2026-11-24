<?php

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\WebhookEvent;
use App\Services\Webhooks\OutgoingWebhooks;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A contact's membership in an audience (table audience_contact),
 * with status and proof of consent.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $audience_id
 * @property int $contact_id
 * @property ContactStatus $status
 * @property Carbon|null $subscribed_at
 * @property Carbon|null $unsubscribed_at
 * @property string|null $unsubscribe_reason
 * @property Carbon|null $consent_at
 * @property string|null $consent_ip
 * @property string|null $consent_source
 * @property string|null $consent_detail
 * @property string|null $mailchimp_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'audience_id', 'contact_id', 'status', 'subscribed_at', 'unsubscribed_at', 'unsubscribe_reason', 'consent_at', 'consent_ip', 'consent_source', 'consent_detail', 'mailchimp_id'])]
class Subscription extends Pivot
{
    use BelongsToOrganization;

    protected $table = 'audience_contact';

    public $incrementing = true;

    protected static function booted(): void
    {
        // Outgoing webhooks: contact.subscribed / contact.unsubscribed.
        static::saved(function (self $subscription) {
            if (! $subscription->wasRecentlyCreated && ! $subscription->wasChanged('status')) {
                return;
            }

            $event = match ($subscription->status) {
                ContactStatus::Subscribed => WebhookEvent::ContactSubscribed,
                ContactStatus::Unsubscribed => WebhookEvent::ContactUnsubscribed,
                default => null,
            };

            if ($event === null) {
                return;
            }

            $contact = DB::table('contacts')->where('id', $subscription->contact_id)->first(['id', 'email', 'first_name', 'last_name']);
            $audience = DB::table('audiences')->where('id', $subscription->audience_id)->first(['id', 'name']);

            app(OutgoingWebhooks::class)->dispatch((int) $subscription->organization_id, $event, [
                'contact' => $contact !== null ? ['id' => (int) $contact->id, 'email' => (string) $contact->email, 'first_name' => $contact->first_name, 'last_name' => $contact->last_name] : null,
                'audience' => $audience !== null ? ['id' => (int) $audience->id, 'name' => (string) $audience->name] : null,
                'status' => $subscription->status->value,
                'source' => $subscription->consent_source,
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Audience, $this>
     */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The (loaded) contact; callers filter out soft-deleted contacts with whereHas('contact').
     */
    public function contactOrFail(): Contact
    {
        return $this->contact ?? throw new LogicException("Subscription {$this->id} has no contact.");
    }
}
