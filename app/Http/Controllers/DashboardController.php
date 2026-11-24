<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\ConnectionStatus;
use App\Enums\ContactStatus;
use App\Enums\Permission;
use App\Models\Audience;
use App\Models\Automation;
use App\Models\Campaign;
use App\Models\MailConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Limits\ContactLimit;
use App\Services\Limits\MonthlySendQuota;
use App\Services\Reports\CampaignReport;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home: audiences, last campaign, upcoming sends, automations and alerts
 * (missing address, usage limits, failing connections, high bounces/complaints).
 */
class DashboardController extends Controller
{
    /** Alert thresholds for the last campaign (industry rules of thumb). */
    private const float HARD_BOUNCE_ALERT = 0.05;

    private const float COMPLAINT_ALERT = 0.001;

    public function __invoke(Request $request, CurrentOrganization $current, ContactLimit $contacts, MonthlySendQuota $monthly, CampaignReport $report): Response
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $current->getOrFail();

        $subscribed = DB::table('audience_contact')->where('organization_id', $organization->id)
            ->where('status', ContactStatus::Subscribed->value)
            ->selectRaw('audience_id, count(*) as total')->groupBy('audience_id')->pluck('total', 'audience_id');

        $last = Campaign::query()->where('kind', 'regular')->where('is_historic', false)
            ->where('status', CampaignStatus::Sent)->latest('finished_at')->first();
        $lastStats = $last !== null ? $report->metrics($last) : null;

        $usage = [
            'contacts' => ['used' => $contacts->count($organization->id), 'limit' => $organization->limit('contacts')],
            'monthly' => ['used' => $monthly->used($organization), 'limit' => $organization->limit('monthly_sends')],
        ];

        $timezone = $organization->timezone ?: (string) config('app.display_timezone');
        $now = CarbonImmutable::now($timezone);

        return Inertia::render('Dashboard', [
            'greeting' => match (true) {
                $now->hour < 12 => 'Buenos días',
                $now->hour < 19 => 'Buenas tardes',
                default => 'Buenas noches',
            },
            'today' => $now->toDateString(),
            'series' => $this->series($organization->id, $timezone),
            'kpis' => $this->kpis($organization->id),
            'activity' => fn () => $user->can(Permission::ContactsView->value) ? $this->activity() : [],
            'alerts' => $this->alerts($user, $organization, $usage, $last, $lastStats),
            'audiences' => Audience::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Audience $a) => ['id' => $a->id, 'name' => $a->name, 'subscribed' => (int) ($subscribed[$a->id] ?? 0)]),
            'lastCampaign' => $last !== null && $lastStats !== null ? [
                'id' => $last->id,
                'name' => $last->name,
                'finished_at' => $last->finished_at?->toIso8601String(),
                'sent' => $lastStats['sent'],
                'open_rate' => $lastStats['sent'] > 0 ? round($lastStats['unique_opens'] * 100 / $lastStats['sent'], 1) : null,
                'click_rate' => $lastStats['sent'] > 0 ? round($lastStats['unique_clicks'] * 100 / $lastStats['sent'], 1) : null,
            ] : null,
            'upcoming' => Campaign::query()->where('kind', 'regular')
                ->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Sending->value, CampaignStatus::Testing->value, CampaignStatus::Paused->value])
                ->orderByRaw('scheduled_at NULLS FIRST')->limit(5)->get()
                ->map(fn (Campaign $c) => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status->value, 'status_label' => $c->status->label(), 'scheduled_at' => $c->scheduled_at?->toIso8601String()]),
            'automations' => Automation::query()->where('status', 'active')->count(),
            'usage' => $usage,
            'stats' => [
                'users' => $user->can(Permission::UsersManage->value) ? User::query()->where('is_active', true)->count() : null,
            ],
        ]);
    }

    /**
     * Sends, opens and clicks per day over the last 30 days (organization timezone).
     *
     * @return list<array{date: string, sent: int, opens: int, clicks: int}>
     */
    private function series(int $organizationId, string $timezone): array
    {
        $start = CarbonImmutable::now($timezone)->startOfDay()->subDays(29);

        $rows = DB::table('email_events')
            ->where('organization_id', $organizationId)
            ->whereIn('type', ['sent', 'open', 'click'])
            ->where('occurred_at', '>=', $start->utc())
            ->selectRaw("to_char((occurred_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') as day", [$timezone])
            ->selectRaw("count(*) FILTER (WHERE type = 'sent') as sent")
            ->selectRaw("count(*) FILTER (WHERE type = 'open') as opens")
            ->selectRaw("count(*) FILTER (WHERE type = 'click') as clicks")
            ->groupBy('day')->get()->keyBy('day');

        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $start->addDays($i)->toDateString();
            $row = $rows[$day] ?? null;
            $series[] = ['date' => $day, 'sent' => (int) ($row->sent ?? 0), 'opens' => (int) ($row->opens ?? 0), 'clicks' => (int) ($row->clicks ?? 0)];
        }

        return $series;
    }

    /**
     * Last 30 days against the 30 before: new subscribers, unsubscribes, open and click rates.
     *
     * @return array<string, array{value: float|int|null, previous: float|int|null}>
     */
    private function kpis(int $organizationId): array
    {
        $now = now();
        $current = $this->periodValues($organizationId, $now->subDays(30), $now);
        $previous = $this->periodValues($organizationId, $now->subDays(60), $now->subDays(30));

        $kpis = [];
        foreach ($current as $key => $value) {
            $kpis[$key] = ['value' => $value, 'previous' => $previous[$key]];
        }

        return $kpis;
    }

    /**
     * @return array{subscribed: int, unsubscribed: int, open_rate: float|null, click_rate: float|null}
     */
    private function periodValues(int $organizationId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $subscriptions = DB::table('audience_contact')->where('organization_id', $organizationId)
            ->selectRaw('count(*) FILTER (WHERE subscribed_at >= ? AND subscribed_at < ?) as subscribed', [$from, $to])
            ->selectRaw('count(*) FILTER (WHERE unsubscribed_at >= ? AND unsubscribed_at < ?) as unsubscribed', [$from, $to])
            ->first();
        $events = DB::table('email_events')->where('organization_id', $organizationId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("count(*) FILTER (WHERE type = 'sent') as sent")
            ->selectRaw("count(DISTINCT campaign_recipient_id) FILTER (WHERE type = 'open') as opened")
            ->selectRaw("count(DISTINCT campaign_recipient_id) FILTER (WHERE type = 'click') as clicked")
            ->first();
        $sent = (int) ($events->sent ?? 0);

        return [
            'subscribed' => (int) ($subscriptions->subscribed ?? 0),
            'unsubscribed' => (int) ($subscriptions->unsubscribed ?? 0),
            'open_rate' => $sent > 0 ? round((int) ($events->opened ?? 0) * 100 / $sent, 1) : null,
            'click_rate' => $sent > 0 ? round((int) ($events->clicked ?? 0) * 100 / $sent, 1) : null,
        ];
    }

    /**
     * Latest subscriptions, unsubscribes and finished campaigns, newest first.
     *
     * @return list<array{type: string, title: string, detail: string, at: string|null, link: string|null}>
     */
    private function activity(): array
    {
        $items = collect();

        $subscriptions = DB::table('audience_contact as ac')
            ->join('contacts as c', 'c.id', '=', 'ac.contact_id')
            ->join('audiences as a', 'a.id', '=', 'ac.audience_id')
            ->where('ac.organization_id', app(CurrentOrganization::class)->id())
            ->whereNull('c.deleted_at');

        foreach ((clone $subscriptions)->whereNotNull('ac.subscribed_at')->where('ac.status', 'subscribed')->latest('ac.subscribed_at')->limit(6)
            ->get(['c.id', 'c.email', 'a.name as audience', 'ac.subscribed_at as at']) as $row) {
            $items->push(['type' => 'subscribed', 'title' => (string) $row->email, 'detail' => 'Se suscribió a '.$row->audience, 'at' => $row->at, 'link' => route('contacts.show', $row->id)]);
        }

        foreach ((clone $subscriptions)->whereNotNull('ac.unsubscribed_at')->where('ac.status', 'unsubscribed')->latest('ac.unsubscribed_at')->limit(4)
            ->get(['c.id', 'c.email', 'a.name as audience', 'ac.unsubscribed_at as at']) as $row) {
            $items->push(['type' => 'unsubscribed', 'title' => (string) $row->email, 'detail' => 'Se dio de baja de '.$row->audience, 'at' => $row->at, 'link' => route('contacts.show', $row->id)]);
        }

        foreach (Campaign::query()->where('kind', 'regular')->where('is_historic', false)->where('status', CampaignStatus::Sent)
            ->latest('finished_at')->limit(3)->get() as $campaign) {
            $items->push(['type' => 'campaign', 'title' => $campaign->name, 'detail' => number_format($campaign->sent_count).' enviados', 'at' => $campaign->finished_at, 'link' => route('campaigns.show', $campaign)]);
        }

        $sorted = $items->sortByDesc(fn (array $i) => (string) $i['at'])->take(8)->all();
        $result = [];

        foreach ($sorted as $item) {
            $result[] = [
                'type' => (string) $item['type'],
                'title' => (string) $item['title'],
                'detail' => (string) $item['detail'],
                'at' => $item['at'] !== null ? CarbonImmutable::parse((string) $item['at'], 'UTC')->toIso8601String() : null,
                'link' => $item['link'] !== null ? (string) $item['link'] : null,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, array{used: int, limit: int|null}>  $usage
     * @param  array<string, int>|null  $lastStats
     * @return list<array{level: string, title: string, message: string}>
     */
    private function alerts(User $user, Organization $organization, array $usage, ?Campaign $last, ?array $lastStats): array
    {
        $alerts = [];

        if (blank($organization->physical_address)) {
            $alerts[] = ['level' => 'warning', 'title' => 'Falta la dirección física',
                'message' => 'Es obligatoria en el pie de todos los correos. Configúrala en Administración → Organización.'];
        }

        foreach (['contacts' => 'contactos', 'monthly' => 'envíos de este mes'] as $key => $label) {
            ['used' => $used, 'limit' => $limit] = $usage[$key];

            if ($limit !== null && $used >= $limit) {
                $alerts[] = ['level' => 'warning', 'title' => "Se alcanzó el límite de {$label}",
                    'message' => sprintf('%s de %s. %s', number_format($used), number_format($limit), $key === 'monthly' ? 'Las campañas se pausarán hasta el próximo mes.' : 'No se pueden agregar contactos nuevos.')];
            } elseif ($limit !== null && $used >= $limit * 0.8) {
                $alerts[] = ['level' => 'info', 'title' => "Cerca del límite de {$label}",
                    'message' => sprintf('%s de %s (%d %%).', number_format($used), number_format($limit), (int) floor($used * 100 / $limit))];
            }
        }

        foreach (MailConnection::query()->where('status', ConnectionStatus::Error->value)->get() as $connection) {
            $alerts[] = ['level' => 'warning', 'title' => "La conexión «{$connection->name}» tiene errores",
                'message' => 'Revísala en Administración → Conexiones de envío antes de enviar.'];
        }

        if ($last !== null && $lastStats !== null && $lastStats['sent'] >= 50) {
            if ($lastStats['hard_bounces'] / $lastStats['sent'] > self::HARD_BOUNCE_ALERT) {
                $alerts[] = ['level' => 'warning', 'title' => 'Muchos rebotes en la última campaña',
                    'message' => sprintf('«%s»: %s %% de rebotes duros. Revisa la calidad de la lista antes del próximo envío.', $last->name, round($lastStats['hard_bounces'] * 100 / $lastStats['sent'], 1))];
            }

            if ($lastStats['complaints'] / $lastStats['sent'] > self::COMPLAINT_ALERT) {
                $alerts[] = ['level' => 'warning', 'title' => 'Quejas de spam en la última campaña',
                    'message' => sprintf('«%s»: %d quejas. Más de 0.1 %% afecta la entrega de los próximos correos.', $last->name, $lastStats['complaints'])];
            }
        }

        if (! $user->hasConfirmedTwoFactor()) {
            $alerts[] = ['level' => 'info', 'title' => 'Protege tu cuenta',
                'message' => 'Activa la autenticación en dos pasos desde Configuración → Seguridad.'];
        }

        return $alerts;
    }
}
