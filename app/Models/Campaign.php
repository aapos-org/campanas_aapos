<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $subject
 * @property string|null $preheader
 * @property string|null $from_name
 * @property string|null $from_email
 * @property string|null $reply_to
 * @property int|null $audience_id
 * @property list<int> $tag_ids
 * @property int|null $segment_id
 * @property string $kind
 * @property string|null $ab_type
 * @property int|null $ab_sample_percent
 * @property string|null $ab_metric
 * @property int|null $ab_wait_hours
 * @property int|null $ab_winner_variant_id
 * @property Carbon|null $ab_sample_finished_at
 * @property Carbon|null $ab_decided_at
 * @property int|null $mail_connection_id
 * @property int|null $template_id
 * @property string $editor
 * @property string|null $mjml
 * @property array<string, mixed>|null $design
 * @property string|null $html
 * @property CampaignStatus $status
 * @property Carbon|null $scheduled_at
 * @property string $timezone
 * @property int|null $rate_per_minute
 * @property bool $track_opens
 * @property bool $track_clicks
 * @property int $recipients_count
 * @property int $sent_count
 * @property int $failed_count
 * @property int $skipped_count
 * @property string|null $status_reason
 * @property string|null $job_batch_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $last_test_sent_at
 * @property bool $is_historic
 * @property string|null $mailchimp_id
 * @property string|null $mailchimp_web_id
 * @property array<string, mixed>|null $report_summary
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Audience|null $audience
 * @property-read MailConnection|null $mailConnection
 * @property-read Segment|null $segment
 * @property-read Collection<int, CampaignVariant> $variants
 */
#[Fillable(['organization_id', 'name', 'subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'audience_id', 'tag_ids', 'segment_id', 'kind', 'ab_type', 'ab_sample_percent', 'ab_metric', 'ab_wait_hours', 'ab_winner_variant_id', 'ab_sample_finished_at', 'ab_decided_at', 'mail_connection_id', 'template_id', 'editor', 'mjml', 'design', 'html', 'status', 'scheduled_at', 'timezone', 'rate_per_minute', 'track_opens', 'track_clicks', 'recipients_count', 'sent_count', 'failed_count', 'skipped_count', 'status_reason', 'job_batch_id', 'started_at', 'finished_at', 'last_test_sent_at', 'is_historic', 'mailchimp_id', 'mailchimp_web_id', 'report_summary', 'created_by'])]
class Campaign extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'tag_ids' => '[]',
        'status' => 'draft',
        'kind' => 'regular',
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'tag_ids' => 'array',
            'design' => 'array',
            'report_summary' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_test_sent_at' => 'datetime',
            'rate_per_minute' => 'integer',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'is_historic' => 'boolean',
            'ab_sample_percent' => 'integer',
            'ab_wait_hours' => 'integer',
            'ab_sample_finished_at' => 'datetime',
            'ab_decided_at' => 'datetime',
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
     * @return BelongsTo<MailConnection, $this>
     */
    public function mailConnection(): BelongsTo
    {
        return $this->belongsTo(MailConnection::class);
    }

    /**
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * @return HasMany<CampaignVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(CampaignVariant::class)->orderBy('label');
    }

    /**
     * In-memory copy with a variant's overrides applied (for rendering). Never save it.
     */
    public function withVariant(?CampaignVariant $variant): self
    {
        if ($variant === null) {
            return $this;
        }

        $copy = clone $this;

        foreach (['subject', 'preheader', 'from_name', 'html'] as $attribute) {
            if (filled($variant->{$attribute})) {
                $copy->setAttribute($attribute, $variant->{$attribute});
            }
        }

        return $copy;
    }

    /** A/B test whose winner has not been chosen yet. */
    public function abPending(): bool
    {
        return $this->ab_type !== null && $this->ab_decided_at === null;
    }

    public function isAbTest(): bool
    {
        return $this->ab_type !== null;
    }

    public function isAutomation(): bool
    {
        return $this->kind === 'automation';
    }

    /**
     * @return HasMany<CampaignLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(CampaignLink::class);
    }
}
