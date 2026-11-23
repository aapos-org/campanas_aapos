<?php

namespace App\Services\Webhooks;

use App\Enums\EventType;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One ElasticEmail notification, normalized.
 *
 * The v4 OpenAPI documents how webhooks are configured (GET or POST JSON, optional
 * HMAC signing, toggles Sent/Opened/Clicked/Unsubscribed/AbuseReport/Error) but not the
 * exact payload, so field names are read tolerantly (case-insensitive, known aliases).
 * Verified against real notifications once the endpoint is public (docs/WEBHOOKS.md).
 */
final readonly class ElasticEmailEvent
{
    /** Bounce categories treated as permanent (address does not exist). */
    public const array HARD_BOUNCE_CATEGORIES = ['nomailbox'];

    public function __construct(
        public ?EventType $type,
        public string $rawStatus,
        public ?string $category,
        public ?string $to,
        public ?string $messageId,
        public ?string $transactionId,
        public ?string $postback,
        public ?Carbon $occurredAt,
        public ?string $url,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $d = array_change_key_case($data, CASE_LOWER);
        $get = function (string ...$keys) use ($d): ?string {
            foreach ($keys as $key) {
                if (isset($d[$key]) && is_scalar($d[$key]) && trim((string) $d[$key]) !== '') {
                    return trim((string) $d[$key]);
                }
            }

            return null;
        };

        $status = (string) $get('status', 'eventtype', 'event', 'type');
        $category = $get('category', 'messagecategory', 'errorcategory', 'bouncecategory');

        try {
            $date = ($raw = $get('date', 'eventdate', 'timestamp')) !== null ? Carbon::parse($raw)->utc() : null;
        } catch (Throwable) {
            $date = null;
        }

        return new self(
            type: self::mapType($status, $category),
            rawStatus: $status,
            category: $category,
            to: ($to = $get('to', 'email', 'recipient')) !== null ? mb_strtolower($to) : null,
            messageId: $get('messageid', 'msgid', 'message_id'),
            transactionId: $get('transaction', 'transactionid', 'transaction_id'),
            postback: $get('postback'),
            occurredAt: $date,
            url: $get('target', 'url', 'link'),
        );
    }

    /**
     * Opens and clicks are measured by the app itself (D10): provider opens/clicks are ignored
     * to avoid double counting. FailedAttempt is a temporary failure ElasticEmail retries.
     */
    private static function mapType(string $status, ?string $category): ?EventType
    {
        return match (mb_strtolower($status)) {
            'sent', 'delivered', 'delivery' => EventType::Delivered,
            'bounce', 'bounced', 'error', 'failed' => in_array(mb_strtolower((string) $category), self::HARD_BOUNCE_CATEGORIES, true)
                ? EventType::HardBounce
                : EventType::SoftBounce,
            'abusereport', 'complaint', 'abuse', 'spam' => EventType::Complaint,
            'unsubscribed', 'unsubscribe', 'transactionalunsubscribe' => EventType::Unsubscribe,
            default => null, // opened, clicked, submission, failedattempt, suppress…
        };
    }

    public function dedupeKey(): string
    {
        return 'ee:'.sha1(implode('|', [
            mb_strtolower($this->rawStatus), $this->messageId, $this->transactionId, $this->to,
            $this->occurredAt?->toIso8601String(), $this->category,
        ]));
    }

    /**
     * Our own reference sent as Content.Postback ("cr-{recipientId}"), if echoed back.
     */
    public function recipientIdFromPostback(): ?int
    {
        return $this->postback !== null && preg_match('/^cr-(\d+)$/', $this->postback, $m) ? (int) $m[1] : null;
    }
}
