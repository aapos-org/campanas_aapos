<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $campaign_id
 * @property int|null $contact_id
 * @property int|null $subscription_id
 * @property string $email
 * @property RecipientStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $message_id
 * @property string|null $provider_id
 * @property string $token
 * @property Carbon|null $sent_at
 * @property int|null $variant_id
 * @property int|null $automation_run_id
 * @property-read CampaignVariant|null $variant
 * @property-read Campaign $campaign
 * @property-read Contact|null $contact
 */
#[Fillable(['organization_id', 'campaign_id', 'contact_id', 'subscription_id', 'email', 'status', 'attempts', 'last_error', 'message_id', 'provider_id', 'token', 'sent_at', 'variant_id', 'automation_run_id'])]
class CampaignRecipient extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<CampaignVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(CampaignVariant::class);
    }
}
