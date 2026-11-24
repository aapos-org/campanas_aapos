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

        return Inertia::render('Dashboard', [
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
