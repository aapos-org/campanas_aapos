<?php

namespace App\Services\Segments;

use App\Models\Segment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns validated segment conditions into query constraints. Works on a query with the
 * aliases `ac` (audience_contact) and `c` (contacts). Column names come from fixed
 * whitelists; every user value (including custom field keys) is a bound parameter.
 */
class SegmentQuery
{
    /** Contact columns that conditions may use. */
    private const array CONTACT_COLUMNS = [
        'email' => 'c.email',
        'first_name' => 'c.first_name',
        'last_name' => 'c.last_name',
        'phone' => 'c.phone',
    ];

    /**
     * Subscriptions of an audience joined with their (non-deleted) contacts.
     */
    public function base(int $audienceId): Builder
    {
        return DB::table('audience_contact as ac')
            ->join('contacts as c', fn ($j) => $j->on('c.id', '=', 'ac.contact_id')->whereNull('c.deleted_at'))
            ->where('ac.audience_id', $audienceId);
    }

    public function forSegment(Segment $segment): Builder
    {
        $query = $this->base($segment->audience_id);
        $this->apply($query, $segment->match, $segment->conditions);

        return $query;
    }

    /**
     * @param  list<array{field: string, op: string, value: mixed}>  $conditions
     */
    public function apply(Builder $query, string $match, array $conditions): void
    {
        if ($conditions === []) {
            return;
        }

        $query->where(function (Builder $group) use ($match, $conditions) {
            foreach ($conditions as $condition) {
                $method = $match === 'any' ? 'orWhere' : 'where';
                $group->{$method}(fn (Builder $q) => $this->condition($q, $condition['field'], $condition['op'], $condition['value']));
            }
        });
    }

    /**
     * Whether one subscription matches (used by automation conditions).
     *
     * @param  list<array{field: string, op: string, value: mixed}>  $conditions
     */
    public function matches(int $subscriptionId, int $audienceId, string $match, array $conditions): bool
    {
        $query = $this->base($audienceId)->where('ac.id', $subscriptionId);
        $this->apply($query, $match, $conditions);

        return $query->exists();
    }

    private function condition(Builder $q, string $field, string $op, mixed $value): void
    {
        match (true) {
            isset(self::CONTACT_COLUMNS[$field]) => $this->text($q, self::CONTACT_COLUMNS[$field], $op, $value),
            $field === 'status' => $q->where('ac.status', $op === 'is' ? '=' : '<>', (string) $value),
            $field === 'subscribed_at' => $this->date($q, 'ac.subscribed_at', $op, $value),
            $field === 'tag' => $this->exists($q, $op === 'has', fn (Builder $e) => $e->from('contact_tag as ct')
                ->whereColumn('ct.contact_id', 'c.id')->where('ct.tag_id', (int) $value)),
            $field === 'campaign' => $this->campaign($q, $op, (int) $value),
            $field === 'activity' => $this->activity($q, $op, (int) $value),
            $field === 'link' => $q->whereExists(fn (Builder $e) => $e->from('email_events as e')
                ->join('campaign_links as l', 'l.id', '=', 'e.campaign_link_id')
                ->whereColumn('e.contact_id', 'c.id')->where('e.type', 'click')
                ->where('l.url', 'ilike', $this->like((string) $value))),
            str_starts_with($field, 'field:') => $this->customField($q, substr($field, 6), $op, $value),
            default => throw new InvalidArgumentException("Campo de segmento desconocido: {$field}"),
        };
    }

    private function text(Builder $q, string $column, string $op, mixed $value): void
    {
        match ($op) {
            'is' => $q->whereRaw("lower({$column}) = lower(?)", [(string) $value]),
            'is_not' => $q->where(fn ($w) => $w->whereNull($column)->orWhereRaw("lower({$column}) <> lower(?)", [(string) $value])),
            'contains' => $q->where($column, 'ilike', $this->like((string) $value)),
            'not_contains' => $q->where(fn ($w) => $w->whereNull($column)->orWhere($column, 'not ilike', $this->like((string) $value))),
            'ends_with' => $q->where($column, 'ilike', '%'.$this->escape((string) $value)),
            'blank' => $q->where(fn ($w) => $w->whereNull($column)->orWhere($column, '')),
            'not_blank' => $q->whereNotNull($column)->where($column, '<>', ''),
            default => throw new InvalidArgumentException("Operador no válido: {$op}"),
        };
    }

    private function date(Builder $q, string $column, string $op, mixed $value): void
    {
        match ($op) {
            'before' => $q->where($column, '<', CarbonImmutable::parse((string) $value)->startOfDay()),
            'after' => $q->where($column, '>=', CarbonImmutable::parse((string) $value)->addDay()->startOfDay()),
            'within_days' => $q->where($column, '>=', now()->subDays((int) $value)),
            'not_within_days' => $q->where(fn ($w) => $w->whereNull($column)->orWhere($column, '<', now()->subDays((int) $value))),
            default => throw new InvalidArgumentException("Operador no válido: {$op}"),
        };
    }

    private function customField(Builder $q, string $key, string $op, mixed $value): void
    {
        // The key is bound as a parameter: c.fields ->> ?
        $expr = 'c.fields ->> ?';

        match ($op) {
            'is' => $q->whereRaw("lower({$expr}) = lower(?)", [$key, (string) $value]),
            'is_not' => $q->whereRaw("({$expr} IS NULL OR lower({$expr}) <> lower(?))", [$key, $key, (string) $value]),
            'contains' => $q->whereRaw("{$expr} ILIKE ?", [$key, $this->like((string) $value)]),
            'not_contains' => $q->whereRaw("({$expr} IS NULL OR {$expr} NOT ILIKE ?)", [$key, $key, $this->like((string) $value)]),
            'blank' => $q->whereRaw("coalesce({$expr}, '') = ''", [$key]),
            'not_blank' => $q->whereRaw("coalesce({$expr}, '') <> ''", [$key]),
            'gt', 'lt' => $q->whereRaw(
                "CASE WHEN {$expr} ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN ({$expr})::numeric END ".($op === 'gt' ? '>' : '<').' CAST(? AS numeric)',
                [$key, $key, (string) $value],
            ),
            // Dates are stored as Y-m-d: string comparison is chronological.
            'before' => $q->whereRaw("{$expr} < ?", [$key, (string) $value]),
            'after' => $q->whereRaw("{$expr} > ?", [$key, (string) $value]),
            'within_days' => $q->whereRaw("{$expr} >= ?", [$key, now()->subDays((int) $value)->toDateString()]),
            // Birthdays are stored as MM-DD.
            'month_is' => $q->whereRaw("left({$expr}, 2) = ?", [$key, sprintf('%02d', (int) $value)]),
            default => throw new InvalidArgumentException("Operador no válido: {$op}"),
        };
    }

    private function campaign(Builder $q, string $op, int $campaignId): void
    {
        $positive = in_array($op, ['received', 'opened', 'clicked'], true);

        if (in_array($op, ['received', 'not_received'], true)) {
            $this->exists($q, $positive, fn (Builder $e) => $e->from('campaign_recipients as cr')
                ->whereColumn('cr.contact_id', 'c.id')->where('cr.campaign_id', $campaignId)->where('cr.status', 'sent'));

            return;
        }

        $type = in_array($op, ['opened', 'not_opened'], true) ? 'open' : 'click';
        $this->exists($q, $positive, fn (Builder $e) => $e->from('email_events as e')
            ->whereColumn('e.contact_id', 'c.id')->where('e.campaign_id', $campaignId)->where('e.type', $type));
    }

    private function activity(Builder $q, string $op, int $days): void
    {
        $type = str_contains($op, 'opened') ? 'open' : 'click';

        $this->exists($q, ! str_starts_with($op, 'not_'), fn (Builder $e) => $e->from('email_events as e')
            ->whereColumn('e.contact_id', 'c.id')->where('e.type', $type)
            ->where('e.occurred_at', '>=', now()->subDays($days)));
    }

    private function exists(Builder $q, bool $positive, \Closure $sub): void
    {
        $positive ? $q->whereExists($sub) : $q->whereNotExists($sub);
    }

    private function like(string $value): string
    {
        return '%'.$this->escape($value).'%';
    }

    private function escape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
