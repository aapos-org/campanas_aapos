<?php

namespace App\Services\Mailchimp;

use App\Enums\CampaignStatus;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\Organization;
use App\Services\Campaigns\EmailHtmlSanitizer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Loads the sent-campaign history from the Mailchimp backup as read-only "historic" campaigns:
 * sanitized HTML, report summary and per-link click totals. Idempotent by mailchimp_id
 * (re-running updates the same rows and never touches campaigns created in the platform).
 *
 * Templates are only reported: the Mailchimp API exposes their editable sections, not
 * the full HTML, so they cannot be rebuilt faithfully. Per-person activity is not loaded
 * (it is in the encrypted backup for audits).
 */
class MailchimpHistoryLoader
{
    /** Report fields kept from Mailchimp (the rest — industry stats, ecommerce… — is noise). */
    private const array SUMMARY_FIELDS = ['emails_sent', 'abuse_reports', 'unsubscribed', 'bounces', 'forwards', 'opens', 'clicks', 'list_stats', 'preview_text'];

    private ?Closure $output = null;

    /** @var array<string, int> */
    private array $stats = [
        'campaigns' => 0,
        'created' => 0,
        'updated' => 0,
        'without_content' => 0,
        'without_audience' => 0,
        'links' => 0,
        'templates_total' => 0,
    ];

    public function __construct(
        private readonly MailchimpBackup $backup,
        private readonly Organization $organization,
        private readonly EmailHtmlSanitizer $sanitizer,
    ) {}

    public function onProgress(Closure $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function run(bool $dryRun): array
    {
        DB::beginTransaction();

        try {
            $this->loadCampaigns();
            $this->stats['templates_total'] = count($this->items('templates', 'templates'));
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $this->stats;
    }

    private function loadCampaigns(): void
    {
        $audiences = Audience::query()->whereNotNull('mailchimp_id')->pluck('id', 'mailchimp_id');
        $campaigns = $this->items('campaigns', 'campaigns');
        $total = count($campaigns);

        foreach ($campaigns as $i => $mc) {
            $cid = (string) $mc['id'];
            $settings = (array) ($mc['settings'] ?? []);
            $summary = $this->backup->tryGet("reports/{$cid}/summary") ?? (array) ($mc['report_summary'] ?? []);
            $content = $this->backup->tryGet("campaigns/{$cid}/content") ?? [];
            $html = (string) ($content['html'] ?? $content['archive_html'] ?? '');
            $listId = (string) data_get($mc, 'recipients.list_id', '');
            $sentAt = $this->date($mc['send_time'] ?? null);
            $emailsSent = (int) ($summary['emails_sent'] ?? $mc['emails_sent'] ?? 0);

            $campaign = Campaign::query()->withTrashed()->firstOrNew(['mailchimp_id' => $cid]);

            if ($campaign->exists && ! $campaign->is_historic) {
                continue; // never overwrite a campaign created in the platform
            }

            $this->stats[$campaign->exists ? 'updated' : 'created']++;
            $this->stats['campaigns']++;

            if ($html === '') {
                $this->stats['without_content']++;
            }

            $audienceId = $audiences[$listId] ?? null;

            if ($audienceId === null) {
                $this->stats['without_audience']++;
            }

            $campaign->forceFill([
                'organization_id' => $this->organization->id,
                'name' => mb_substr((string) ($settings['title'] ?? '') ?: (string) ($settings['subject_line'] ?? $cid), 0, 255),
                'subject' => mb_substr((string) ($settings['subject_line'] ?? ''), 0, 255) ?: null,
                'preheader' => mb_substr((string) ($summary['preview_text'] ?? ''), 0, 255) ?: null,
                'from_name' => mb_substr((string) ($settings['from_name'] ?? ''), 0, 255) ?: null,
                'reply_to' => mb_substr((string) ($settings['reply_to'] ?? ''), 0, 255) ?: null,
                'audience_id' => $audienceId,
                'editor' => 'html',
                'html' => $html !== '' ? $this->sanitizer->sanitize($html) : null,
                'status' => CampaignStatus::Sent,
                'is_historic' => true,
                'mailchimp_web_id' => isset($mc['web_id']) ? (string) $mc['web_id'] : null,
                'recipients_count' => $emailsSent,
                'sent_count' => $emailsSent,
                'started_at' => $sentAt,
                'finished_at' => $sentAt,
                'report_summary' => array_intersect_key($summary, array_flip(self::SUMMARY_FIELDS)),
                'deleted_at' => null,
            ]);
            $campaign->created_at ??= $sentAt !== null ? Carbon::instance($sentAt) : null;
            $campaign->save();

            $this->loadLinks($campaign, $cid);

            if ($this->output !== null && (($i + 1) % 100 === 0 || $i + 1 === $total)) {
                ($this->output)(sprintf('  Campañas %d/%d', $i + 1, $total));
            }
        }
    }

    private function loadLinks(Campaign $campaign, string $cid): void
    {
        // The same URL can appear several times in Mailchimp's report: totals are summed.
        $links = [];
        foreach ($this->items("reports/{$cid}/click_details", 'urls_clicked') as $link) {
            $url = trim((string) ($link['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $hash = CampaignLink::hashFor($url);
            $links[$hash] ??= ['url' => $url, 'position' => count($links), 'historic_clicks' => 0, 'historic_unique_clicks' => 0];
            $links[$hash]['historic_clicks'] += (int) ($link['total_clicks'] ?? 0);
            $links[$hash]['historic_unique_clicks'] += (int) ($link['unique_clicks'] ?? 0);
        }

        foreach ($links as $hash => $attributes) {
            CampaignLink::query()->updateOrCreate(['campaign_id' => $campaign->id, 'hash' => $hash], $attributes);
            $this->stats['links']++;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $prefix, string $itemsKey): array
    {
        $items = [];
        foreach ($this->backup->keys($prefix) as $key) {
            if (preg_match('#^'.preg_quote($prefix, '#').'/page-\d+$#', $key)) {
                array_push($items, ...array_values((array) ($this->backup->get($key)[$itemsKey] ?? [])));
            }
        }

        return $items;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
