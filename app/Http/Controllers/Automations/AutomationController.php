<?php

namespace App\Http\Controllers\Automations;

use App\Enums\AutomationStepType;
use App\Enums\AutomationTrigger;
use App\Enums\CampaignStatus;
use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationRunLog;
use App\Models\AutomationStep;
use App\Models\CampaignRecipient;
use App\Models\MailConnection;
use App\Models\MergeField;
use App\Models\Tag;
use App\Models\User;
use App\Services\Automations\AutomationEditor;
use App\Services\Reports\CampaignReport;
use App\Services\Segments\SegmentRules;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function __construct(private readonly AutomationEditor $editor) {}

    public function index(): Response
    {
        $counts = DB::table('automation_runs')->selectRaw('automation_id, status, count(*) as total')
            ->groupBy('automation_id', 'status')->get()->groupBy('automation_id');

        return Inertia::render('automations/Index', [
            'automations' => Automation::query()->with('audience:id,name')->withCount('steps')->orderBy('name')->get()
                ->map(fn (Automation $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'audience' => $a->audience?->name,
                    'trigger' => $a->trigger_type->label(),
                    'status' => $a->status,
                    'steps' => $a->steps_count,
                    'runs' => collect($counts->get($a->id, []))->mapWithKeys(fn ($r) => [$r->status => (int) $r->total]),
                ]),
            'audiences' => Audience::query()->orderBy('name')->get(['id', 'name']),
            'triggers' => AutomationTrigger::options(),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'audience_id' => ['required', 'integer', Rule::exists('audiences', 'id')->where('organization_id', $user->organization_id)->whereNull('deleted_at')],
            'trigger_type' => ['required', Rule::enum(AutomationTrigger::class)],
        ], [], ['name' => 'nombre', 'audience_id' => 'audiencia', 'trigger_type' => 'disparador']);

        $trigger = AutomationTrigger::from($data['trigger_type']);
        $connection = MailConnection::query()->orderByDesc('is_default')->first();

        $automation = Automation::query()->create($data + [
            'trigger_config' => $trigger === AutomationTrigger::DateField ? ['days_before' => 0, 'send_hour' => 9] : [],
            'allow_reentry' => $trigger === AutomationTrigger::DateField,
            'mail_connection_id' => $connection?->id,
            'from_name' => $connection?->from_name,
            'from_email' => $connection?->from_email,
            'created_by' => $user->id,
        ]);

        Audit::record('automations.created', 'Automatización creada', $automation, [], $user);

        return to_route('automations.edit', $automation);
    }

    public function edit(Automation $automation): Response
    {
        $automation->load(['steps.campaign']);

        return Inertia::render('automations/Edit', [
            'automation' => [
                'id' => $automation->id,
                'name' => $automation->name,
                'audience_id' => $automation->audience_id,
                'trigger_type' => $automation->trigger_type->value,
                'trigger_config' => (object) $automation->trigger_config,
                'status' => $automation->status,
                'allow_reentry' => $automation->allow_reentry,
                'mail_connection_id' => $automation->mail_connection_id,
                'from_name' => $automation->from_name,
                'from_email' => $automation->from_email,
                'reply_to' => $automation->reply_to,
                'steps' => $automation->steps->map(fn (AutomationStep $s) => [
                    'id' => $s->id,
                    'type' => $s->type->value,
                    'config' => (object) $s->config,
                    'campaign_id' => $s->campaign_id,
                    'has_content' => filled(strip_tags((string) $s->campaign?->html)),
                ])->values(),
            ],
            'problems' => $this->editor->activationProblems($automation),
            'audiences' => Audience::query()->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'dateFields' => MergeField::query()->whereIn('type', [FieldType::Date->value, FieldType::Birthday->value])->orderBy('position')->get(['key', 'label']),
            'connections' => MailConnection::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'from_email', 'status']),
            'triggers' => AutomationTrigger::options(),
            'stepTypes' => AutomationStepType::options(),
            'catalog' => app(SegmentRules::class)->catalog(),
            'operators' => SegmentRules::OPERATORS,
            'canSend' => request()->user()?->can('campaigns.send') ?? false,
        ]);
    }

    public function update(Request $request, Automation $automation, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'audience_id' => ['required', 'integer', Rule::exists('audiences', 'id')->where('organization_id', $user->organization_id)->whereNull('deleted_at')],
            'trigger_type' => ['required', Rule::enum(AutomationTrigger::class)],
            'allow_reentry' => ['boolean'],
            'mail_connection_id' => ['nullable', 'integer', Rule::exists('mail_connections', 'id')->where('organization_id', $user->organization_id)->whereNull('deleted_at')],
            'from_name' => ['nullable', 'string', 'max:100'],
            'from_email' => ['nullable', 'email:rfc', 'max:255'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],
        ], [], [
            'name' => 'nombre', 'audience_id' => 'audiencia', 'mail_connection_id' => 'conexión', 'from_name' => 'nombre del remitente',
            'from_email' => 'correo del remitente', 'reply_to' => 'responder a',
        ]);

        if ($automation->isActive() && ((int) $data['audience_id'] !== $automation->audience_id || $data['trigger_type'] !== $automation->trigger_type->value)) {
            throw ValidationException::withMessages(['trigger_type' => 'Pausa la automatización para cambiar la audiencia o el disparador.']);
        }

        $config = $this->editor->triggerConfig($data['trigger_type'], $request->input('trigger_config'));
        $steps = $this->editor->validateSteps($request->input('steps'));

        DB::transaction(function () use ($automation, $data, $config, $steps) {
            $automation->update($data + ['trigger_config' => $config]);
            $this->editor->saveSteps($automation, $steps);

            // Settings (sender, connection, audience) are mirrored into every email.
            $automation->steps()->where('type', AutomationStepType::SendEmail->value)->get()
                ->each(fn (AutomationStep $s) => $this->editor->syncCampaign($automation, $s));
        });

        Audit::record('automations.updated', 'Automatización modificada', $automation, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Automatización guardada.']);

        return back();
    }

    public function activate(Automation $automation, #[CurrentUser] User $user): RedirectResponse
    {
        $problems = $this->editor->activationProblems($automation);

        if ($problems !== []) {
            throw ValidationException::withMessages(['activation' => $problems[0]]);
        }

        // Only events from now on start the flow; runs already in progress continue.
        $automation->update(['status' => 'active', 'activated_at' => now()]);
        Audit::record('automations.activated', 'Automatización activada', $automation, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Automatización activada.']);

        return back();
    }

    public function pause(Automation $automation, #[CurrentUser] User $user): RedirectResponse
    {
        $automation->update(['status' => 'paused']);
        Audit::record('automations.paused', 'Automatización pausada', $automation, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Automatización pausada: los contactos en curso esperan hasta que la reactives.']);

        return back();
    }

    public function show(Request $request, Automation $automation, CampaignReport $report): Response
    {
        $automation->load(['steps.campaign', 'audience:id,name']);
        $status = $request->validate(['status' => ['nullable', Rule::in(array_keys(AutomationRun::STATUS_LABELS))]])['status'] ?? null;

        $runs = AutomationRun::query()->where('automation_id', $automation->id)
            ->with(['contact:id,email,first_name,last_name', 'logs' => fn ($q) => $q->latest('id')->limit(5)])
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(25)->withQueryString();

        $steps = $automation->steps->keyBy('id');

        return Inertia::render('automations/Show', [
            'automation' => [
                'id' => $automation->id,
                'name' => $automation->name,
                'audience' => $automation->audience?->name,
                'trigger' => $automation->trigger_type->label(),
                'status' => $automation->status,
            ],
            'counts' => AutomationRun::query()->where('automation_id', $automation->id)
                ->selectRaw('status, count(*) as total')->groupBy('status')->toBase()->pluck('total', 'status'),
            'steps' => $automation->steps->map(fn (AutomationStep $s) => [
                'id' => $s->id,
                'position' => $s->position,
                'label' => $this->stepLabel($s),
                'campaign_id' => $s->campaign_id,
                'stats' => $s->campaign !== null ? array_intersect_key($report->metrics($s->campaign), array_flip(['sent', 'unique_opens', 'unique_clicks', 'unsubscribes'])) : null,
                'waiting' => AutomationRun::query()->where('automation_id', $automation->id)->where('status', 'active')->where('next_step_id', $s->id)->count(),
            ])->values(),
            'runs' => $runs->through(fn (AutomationRun $r) => [
                'id' => $r->id,
                'contact_id' => $r->contact_id,
                'email' => $r->contact?->email,
                'status' => $r->status,
                'status_label' => AutomationRun::STATUS_LABELS[$r->status] ?? $r->status,
                'next_step' => ($next = $steps->get((int) $r->next_step_id)) !== null ? $this->stepLabel($next) : null,
                'next_run_at' => $r->next_run_at?->toIso8601String(),
                'entered_at' => $r->entered_at->toIso8601String(),
                'exit_reason' => $r->exit_reason,
                'logs' => $r->logs->map(fn (AutomationRunLog $l) => ['message' => $l->message, 'at' => $l->created_at?->toIso8601String()])->values(),
            ]),
            'filters' => ['status' => $status],
            'statusLabels' => AutomationRun::STATUS_LABELS,
        ]);
    }

    public function destroy(Automation $automation, #[CurrentUser] User $user): RedirectResponse
    {
        if ($automation->isActive()) {
            throw ValidationException::withMessages(['automation' => 'Pausa la automatización antes de eliminarla.']);
        }

        DB::transaction(function () use ($automation) {
            foreach ($automation->steps()->with('campaign')->get() as $step) {
                $campaign = $step->campaign;

                if ($campaign === null) {
                    continue;
                }

                // Emails already received keep their report as a sent campaign.
                CampaignRecipient::query()->where('campaign_id', $campaign->id)->exists()
                    ? $campaign->forceFill(['status' => CampaignStatus::Sent, 'finished_at' => now()])->save()
                    : $campaign->forceDelete();
            }

            $automation->delete();
        });

        Audit::record('automations.deleted', 'Automatización eliminada', $automation, [], $user);

        return to_route('automations.index');
    }

    private function stepLabel(AutomationStep $step): string
    {
        $config = $step->config;

        return $step->position.'. '.match ($step->type) {
            AutomationStepType::SendEmail => 'Enviar «'.($config['subject'] ?? '').'»',
            AutomationStepType::Wait => 'Esperar '.AutomationStepType::duration((int) ($config['amount'] ?? 0), (string) ($config['unit'] ?? 'days')),
            AutomationStepType::AddTag => 'Agregar etiqueta «'.(Tag::query()->whereKey((int) ($config['tag_id'] ?? 0))->value('name') ?? '¿?').'»',
            AutomationStepType::RemoveTag => 'Quitar etiqueta «'.(Tag::query()->whereKey((int) ($config['tag_id'] ?? 0))->value('name') ?? '¿?').'»',
            AutomationStepType::Condition => 'Condición: '.app(SegmentRules::class)->describe((string) ($config['match'] ?? 'all'), $step->conditionList()),
        };
    }
}
