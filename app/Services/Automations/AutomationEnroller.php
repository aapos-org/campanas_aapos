<?php

namespace App\Services\Automations;

use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Puts contacts into active automations when their trigger happens. Polled every minute:
 * it only looks at events after the automation was activated, and the unique index
 * (automation_id, contact_id, trigger_key) guarantees nobody enters twice for the same
 * event — or ever, when re-entry is disabled (trigger_key = 'once').
 */
class AutomationEnroller
{
    public function enrollAll(): int
    {
        $total = 0;

        Automation::query()->withoutGlobalScopes()->where('status', 'active')->orderBy('id')
            ->each(function (Automation $automation) use (&$total) {
                $total += $this->enroll($automation);
            });

        return $total;
    }

    public function enroll(Automation $automation): int
    {
        if (! $automation->isActive() || $automation->activated_at === null) {
            return 0;
        }

        $firstStep = $automation->steps()->value('id');

        if ($firstStep === null) {
            return 0;
        }

        $query = match ($automation->trigger_type) {
            AutomationTrigger::Subscribed => $this->subscribed($automation),
            AutomationTrigger::TagAdded => $this->tagAdded($automation),
            AutomationTrigger::DateField => $this->dateField($automation),
        };

        if ($query === null) {
            return 0;
        }

        $now = now();
        $query->selectRaw('?, ?, ?, ?, ?, ?, ?, ?', ['active', $automation->organization_id, $automation->id, $firstStep, $now, $now, $now, $now]);

        return DB::table('automation_runs')->insertOrIgnoreUsing(
            ['contact_id', 'subscription_id', 'trigger_key', 'status', 'organization_id', 'automation_id', 'next_step_id', 'next_run_at', 'entered_at', 'created_at', 'updated_at'],
            $query,
        );
    }

    /**
     * Subscribed contacts of the audience who are not suppressed.
     */
    private function eligible(Automation $automation): Builder
    {
        return DB::table('audience_contact as ac')
            ->join('contacts as c', fn ($j) => $j->on('c.id', '=', 'ac.contact_id')->whereNull('c.deleted_at'))
            ->where('ac.audience_id', $automation->audience_id)
            ->where('ac.status', 'subscribed')
            ->whereNotExists(fn ($q) => $q->from('suppressions as s')
                ->whereColumn('s.organization_id', 'c.organization_id')
                ->whereColumn('s.email', 'c.email')
                ->where(fn ($w) => $w->whereNull('s.audience_id')->orWhereColumn('s.audience_id', 'ac.audience_id')));
    }

    private function subscribed(Automation $automation): Builder
    {
        return $this->eligible($automation)
            ->where('ac.subscribed_at', '>=', $automation->activated_at)
            ->select('c.id', 'ac.id')
            ->selectRaw($automation->allow_reentry ? "'sub:' || to_char(ac.subscribed_at, 'YYYYMMDDHH24MISS')" : "'once'");
    }

    private function tagAdded(Automation $automation): ?Builder
    {
        $tagId = (int) ($automation->trigger_config['tag_id'] ?? 0);

        if ($tagId === 0) {
            return null;
        }

        return $this->eligible($automation)
            ->join('contact_tag as ct', fn ($j) => $j->on('ct.contact_id', '=', 'c.id')->where('ct.tag_id', $tagId))
            ->where('ct.created_at', '>=', $automation->activated_at)
            ->select('c.id', 'ac.id')
            ->selectRaw($automation->allow_reentry ? "'tag:' || to_char(ct.created_at, 'YYYYMMDDHH24MISS')" : "'once'");
    }

    /**
     * Contacts whose date (or birthday) falls `days_before` days from today, once the
     * configured hour has passed in the organization's timezone.
     */
    private function dateField(Automation $automation): ?Builder
    {
        $key = (string) ($automation->trigger_config['field_key'] ?? '');
        $timezone = Organization::query()->whereKey($automation->organization_id)->value('timezone') ?: 'America/Guatemala';
        $now = CarbonImmutable::now((string) $timezone);

        if ($key === '' || $now->hour < (int) ($automation->trigger_config['send_hour'] ?? 9)) {
            return null;
        }

        $target = $now->addDays((int) ($automation->trigger_config['days_before'] ?? 0));
        $monthDay = $target->format('m-d');

        // Dates are stored as Y-m-d and birthdays as MM-DD: compare the last five characters.
        return $this->eligible($automation)
            ->whereRaw('right(c.fields ->> ?, 5) = ?', [$key, $monthDay])
            ->select('c.id', 'ac.id')
            ->selectRaw($automation->allow_reentry ? '?' : "'once'", $automation->allow_reentry ? ['date:'.$target->format('Y')] : []);
    }
}
