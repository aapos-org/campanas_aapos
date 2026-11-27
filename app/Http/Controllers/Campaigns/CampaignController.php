<?php

namespace App\Http\Controllers\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Mail\Transport\MailTransportFactory;
use App\Mail\Transport\OutgoingEmail;
use App\Mail\Transport\TransportException;
use App\Models\AiAssistantSetting;
use App\Models\Audience;
use App\Models\AutomationStep;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVariant;
use App\Models\Contact;
use App\Models\MailConnection;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Template;
use App\Models\User;
use App\Services\Ai\ContentLint;
use App\Services\Campaigns\AbTester;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\ContentPreparer;
use App\Services\Campaigns\LinkRegistry;
use App\Services\Campaigns\PreSendChecklist;
use App\Services\Reports\CampaignReport;
use App\Services\Sending\DailySendQuota;
use App\Support\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly ContentPreparer $preparer,
        private readonly LinkRegistry $links,
    ) {}

    public function index(Request $request): Response
    {
        $historic = $request->boolean('historico');

        $campaigns = Campaign::query()
            ->with('audience:id,name')
            ->where('is_historic', $historic)
            ->where('kind', 'regular')
            ->orderByRaw("CASE WHEN status IN ('sending','paused','scheduled','testing') THEN 0 ELSE 1 END")
            ->orderByDesc(DB::raw('COALESCE(finished_at, scheduled_at, updated_at)'))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Campaign $c) => $this->summary($c));

        return Inertia::render('campaigns/Index', [
            'campaigns' => $campaigns,
            'historic' => $historic,
            'historicCount' => Campaign::query()->where('is_historic', true)->count(),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']], [], ['name' => 'nombre']);
        $default = MailConnection::query()->orderByDesc('is_default')->orderBy('id')->first();
        $audience = Audience::query()->orderBy('name')->first();

        $campaign = Campaign::create([
            'name' => $data['name'],
            'audience_id' => $audience?->id,
            'mail_connection_id' => $default?->id,
            'from_name' => $audience->default_from_name ?? $default?->from_name,
            'from_email' => $audience->default_from_email ?? $default?->from_email,
            'reply_to' => $default?->reply_to,
            'timezone' => (string) config('app.display_timezone'),
            'created_by' => $user->id,
        ]);

        Audit::record('campaigns.created', 'Campaña creada', $campaign, [], $user);

        return to_route('campaigns.edit', $campaign);
    }

    public function edit(Campaign $campaign): Response|RedirectResponse
    {
        if ($campaign->isAutomation()) {
            return $this->toAutomation($campaign);
        }

        if (! $campaign->status->isEditable() || $campaign->is_historic) {
            return to_route('campaigns.show', $campaign);
        }

        return Inertia::render('campaigns/Edit', [
            'campaign' => $this->detail($campaign),
            'audiences' => Audience::query()->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'segments' => Segment::query()->orderBy('name')->get(['id', 'name', 'audience_id']),
            'connections' => MailConnection::query()->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (MailConnection $c) => ['id' => $c->id, 'name' => $c->name, 'driver_label' => $c->driver->label(), 'from_email' => $c->from_email, 'status' => $c->status->value, 'daily_limit' => $c->daily_limit]),
        ]);
    }

    public function update(Request $request, Campaign $campaign, #[CurrentUser] User $user): RedirectResponse
    {
        $this->ensureEditable($campaign);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject' => ['nullable', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'from_email' => ['nullable', 'email:rfc', 'max:255'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'audience_id' => ['nullable', 'integer', Rule::exists('audiences', 'id')->where('organization_id', $user->organization_id)->whereNull('deleted_at')],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('organization_id', $user->organization_id)],
            'segment_id' => ['nullable', 'integer', Rule::exists('segments', 'id')->where('organization_id', $user->organization_id)->where('audience_id', (int) $request->input('audience_id'))],
            'ab_type' => ['nullable', Rule::in(['subject', 'from_name', 'content'])],
            'ab_sample_percent' => ['required_with:ab_type', 'nullable', 'integer', 'min:10', 'max:50'],
            'ab_metric' => ['required_with:ab_type', 'nullable', Rule::in(['opens', 'clicks'])],
            'ab_wait_hours' => ['required_with:ab_type', 'nullable', 'integer', 'min:1', 'max:72'],
            'variant_b_subject' => [Rule::requiredIf($request->input('ab_type') === 'subject'), 'nullable', 'string', 'max:200'],
            'variant_b_from_name' => [Rule::requiredIf($request->input('ab_type') === 'from_name'), 'nullable', 'string', 'max:100'],
            'mail_connection_id' => ['nullable', 'integer', Rule::exists('mail_connections', 'id')->where('organization_id', $user->organization_id)->whereNull('deleted_at')],
            'rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'track_opens' => ['boolean'],
            'track_clicks' => ['boolean'],
        ], [], [
            'name' => 'nombre', 'subject' => 'asunto', 'preheader' => 'texto de vista previa', 'from_name' => 'nombre del remitente',
            'from_email' => 'correo del remitente', 'reply_to' => 'responder a', 'audience_id' => 'audiencia', 'mail_connection_id' => 'conexión',
            'segment_id' => 'segmento', 'ab_sample_percent' => 'porcentaje de la muestra', 'ab_metric' => 'criterio ganador',
            'ab_wait_hours' => 'horas de espera', 'variant_b_subject' => 'asunto de la versión B', 'variant_b_from_name' => 'remitente de la versión B',
        ]);

        $ab = $data['ab_type'] ?? null;
        $campaign->update([
            'tag_ids' => array_values(array_map('intval', $data['tag_ids'] ?? [])),
            'ab_sample_percent' => $ab ? $data['ab_sample_percent'] : null,
            'ab_metric' => $ab ? $data['ab_metric'] : null,
            'ab_wait_hours' => $ab ? $data['ab_wait_hours'] : null,
        ] + Arr::except($data, ['variant_b_subject', 'variant_b_from_name']));

        if ($ab === null) {
            $campaign->variants()->delete();
        } else {
            $campaign->variants()->firstOrCreate(['label' => 'A']);
            $campaign->variants()->updateOrCreate(['label' => 'B'], [
                'subject' => $ab === 'subject' ? $data['variant_b_subject'] : null,
                'from_name' => $ab === 'from_name' ? $data['variant_b_from_name'] : null,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Campaña guardada.']);

        return back();
    }

    public function content(Request $request, Campaign $campaign): Response|RedirectResponse|SymfonyResponse
    {
        // The editor needs its own CSP (inline styles), which only applies on a full page load.
        $editable = $this->contentEditable($campaign);

        if ($request->header('X-Inertia') && $editable) {
            return Inertia::location($request->fullUrl());
        }

        if (! $editable) {
            return to_route('campaigns.show', $campaign);
        }

        $variant = $this->contentVariant($request, $campaign);

        return Inertia::render('campaigns/Content', [
            'campaign' => $campaign->only(['id', 'name', 'editor', 'subject', 'kind']) + [
                'mjml' => $variant ? $variant->mjml : $campaign->mjml,
                'design' => $variant ? $variant->design : $campaign->design,
                'html' => $variant ? $variant->html : $campaign->html,
            ],
            'variant' => $variant?->label,
            'assistant' => ContentReviewController::publicConfig(AiAssistantSetting::current(), $request->user()),
            'mergeTagKeys' => ContentLint::knownTagKeys(),
            'automationId' => $campaign->isAutomation() ? AutomationStep::query()->where('campaign_id', $campaign->id)->value('automation_id') : null,
            'abContent' => $campaign->ab_type === 'content',
            'mergeTags' => MergeTags::all(),
            'templates' => Template::query()->orderBy('name')->get(['id', 'name', 'editor']),
            'library' => collect(TemplateController::LIBRARY)->map(fn ($t, $key) => $t + ['key' => $key])->values(),
        ]);
    }

    public function updateContent(Request $request, Campaign $campaign): RedirectResponse|JsonResponse
    {
        $this->ensureEditable($campaign);

        $data = $request->validate([
            'mjml' => ['nullable', 'string', 'max:2000000'],
            'design' => ['nullable', 'array'],
            'html' => ['nullable', 'string', 'max:2000000'],
        ]);

        $target = $this->contentVariant($request, $campaign) ?? $campaign;
        $target->update([
            'mjml' => $campaign->editor === 'visual' ? ($data['mjml'] ?? $target->mjml) : null,
            'design' => $campaign->editor === 'visual' ? ($data['design'] ?? $target->design) : null,
            'html' => $this->preparer->prepare((string) ($data['html'] ?? '')),
        ]);

        if ($campaign->isAutomation()) {
            // Automation emails are never "started": their links are registered on save.
            $this->links->register($campaign->refresh());
        }

        // The editor saves with a JSON request: a redirect back to the editor would force a
        // full page reload (its own CSP) and lose what the page wanted to do next.
        if ($request->wantsJson()) {
            return response()->json(['message' => 'Contenido guardado.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contenido guardado.']);

        return back();
    }

    /**
     * Starts the content from a saved template, a library design or plain HTML.
     */
    public function applyTemplate(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->ensureEditable($campaign);

        $data = $request->validate([
            'template_id' => ['nullable', 'integer'],
            'base' => ['nullable', Rule::in([...array_keys(TemplateController::LIBRARY), 'html'])],
        ]);

        if (! empty($data['template_id'])) {
            $template = Template::query()->findOrFail((int) $data['template_id']);
            $campaign->update(['template_id' => $template->id, 'editor' => $template->editor, 'mjml' => $template->mjml, 'design' => $template->design, 'html' => $template->html]);
        } else {
            $base = $data['base'] ?? 'blanco';
            $campaign->update([
                'template_id' => null,
                'editor' => $base === 'html' ? 'html' : 'visual',
                'mjml' => $base === 'html' ? null : TemplateController::baseMjml($base),
                'design' => null,
                'html' => $base === 'html' ? "<html>\n<body>\n  <p>Hola {{ nombre | \"amigo\" }}</p>\n</body>\n</html>" : null,
            ]);
        }

        return to_route('campaigns.content', $campaign);
    }

    public function review(Campaign $campaign, PreSendChecklist $checklist): Response|RedirectResponse
    {
        if ($campaign->isAutomation()) {
            return $this->toAutomation($campaign);
        }

        if (! $campaign->status->isEditable() || $campaign->is_historic) {
            return to_route('campaigns.show', $campaign);
        }

        $campaign->load(['audience', 'mailConnection']);
        $checks = $checklist->run($campaign, $this->organization(), checkLinks: request()->boolean('enlaces'));

        return Inertia::render('campaigns/Review', [
            'campaign' => $this->detail($campaign),
            'checks' => $checks,
            'blocked' => PreSendChecklist::blocks($checks),
            'linksChecked' => request()->boolean('enlaces'),
            'canSend' => request()->user()?->can(Permission::CampaignsSend->value) ?? false,
            'timezone' => $campaign->timezone,
        ]);
    }

    public function sendTest(Request $request, Campaign $campaign, CampaignRenderer $renderer, MailTransportFactory $transports, DailySendQuota $quota, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['emails' => ['required', 'string', 'max:500']], [], ['emails' => 'destinatarios']);
        $emails = collect(preg_split('/[\s,;]+/', mb_strtolower($data['emails'])) ?: [])->filter()->unique()->values();

        if ($emails->isEmpty() || $emails->count() > 5 || $emails->contains(fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))) {
            throw ValidationException::withMessages(['emails' => 'Escribe entre 1 y 5 correos válidos.']);
        }

        $campaign->load(['audience', 'mailConnection']);
        $connection = $campaign->mailConnection ?? throw ValidationException::withMessages(['emails' => 'Elige primero una conexión de envío.']);
        $organization = $this->organization();

        foreach ($emails as $to) {
            // Real data when the address is a contact; sample data otherwise. No tracking in tests.
            $contact = Contact::query()->where('email', $to)->first();
            $email = $renderer->render($campaign, $organization, $campaign->audience, null, $contact);

            if (! $quota->reserve($connection)) {
                throw ValidationException::withMessages(['emails' => 'Se alcanzó el límite diario de la conexión.']);
            }

            try {
                $transports->for($connection)->send(new OutgoingEmail(
                    fromEmail: $campaign->from_email ?: $connection->from_email,
                    fromName: $campaign->from_name ?: $connection->from_name,
                    to: $to,
                    toName: null,
                    subject: '[Prueba] '.$email->subject,
                    html: $email->html,
                    text: $email->text,
                    replyTo: $campaign->reply_to ?: $connection->reply_to,
                    headers: ['X-Campanas-Test' => '1'],
                ));
            } catch (TransportException $e) {
                $quota->release($connection);

                throw ValidationException::withMessages(['emails' => $e->getMessage()]);
            }
        }

        $campaign->forceFill(['last_test_sent_at' => now()])->save();
        Audit::record('campaigns.test_sent', 'Prueba de campaña enviada', $campaign, ['to' => $emails->all()], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Prueba enviada a '.$emails->implode(', ').'.']);

        return back();
    }

    public function send(Request $request, Campaign $campaign, CampaignSender $sender, PreSendChecklist $checklist, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ], [], ['confirmation' => 'confirmación']);

        if (mb_strtoupper(trim($data['confirmation'])) !== 'ENVIAR') {
            throw ValidationException::withMessages(['confirmation' => 'Escribe ENVIAR para confirmar.']);
        }

        $campaign->load(['audience', 'mailConnection']);

        if (PreSendChecklist::blocks($checklist->run($campaign, $this->organization()))) {
            throw ValidationException::withMessages(['confirmation' => 'Corrige los puntos marcados en rojo antes de enviar.']);
        }

        if (! empty($data['scheduled_at'])) {
            $when = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['scheduled_at'], $campaign->timezone);

            if ($when === null || $when->isPast()) {
                throw ValidationException::withMessages(['scheduled_at' => 'Elige una fecha y hora futura.']);
            }

            $this->ensureEditable($campaign);
            $campaign->update(['status' => CampaignStatus::Scheduled, 'scheduled_at' => $when->utc()]);
            Audit::record('campaigns.scheduled', 'Campaña programada', $campaign, ['at' => $when->toIso8601String()], $user);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Campaña programada para el '.$when->format('d/m/Y H:i').'.']);

            return to_route('campaigns.show', $campaign);
        }

        try {
            $sender->start($campaign, $user);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['confirmation' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Envío iniciado.']);

        return to_route('campaigns.show', $campaign);
    }

    public function unschedule(Campaign $campaign, #[CurrentUser] User $user): RedirectResponse
    {
        Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Scheduled->value)
            ->update(['status' => CampaignStatus::Draft->value, 'scheduled_at' => null]);
        Audit::record('campaigns.unscheduled', 'Programación cancelada', $campaign, [], $user);

        return to_route('campaigns.review', $campaign);
    }

    public function pause(Campaign $campaign, CampaignSender $sender, #[CurrentUser] User $user): RedirectResponse
    {
        $sender->pause($campaign, 'Pausada por '.$user->name, $user);

        return back();
    }

    public function resume(Campaign $campaign, CampaignSender $sender, #[CurrentUser] User $user): RedirectResponse
    {
        $sender->resume($campaign, $user);

        return back();
    }

    public function cancel(Campaign $campaign, CampaignSender $sender, #[CurrentUser] User $user): RedirectResponse
    {
        $sender->cancel($campaign, $user);

        return back();
    }

    public function decideAb(Campaign $campaign, AbTester $tester, #[CurrentUser] User $user): RedirectResponse
    {
        $winner = $tester->decide($campaign, $user);

        Inertia::flash('toast', $winner !== null
            ? ['type' => 'success', 'message' => "Ganó la versión {$winner->label}: se está enviando al resto."]
            : ['type' => 'error', 'message' => 'La prueba A/B ya no está esperando ganador.']);

        return back();
    }

    public function show(Request $request, Campaign $campaign, CampaignReport $report, AbTester $tester): Response
    {
        $campaign->load(['audience', 'mailConnection']);
        $user = $request->user();
        $canReport = $user?->can(Permission::ReportsView->value) ?? false;
        $filters = $request->validate([
            'activity' => ['nullable', Rule::in(array_keys(CampaignReport::FILTERS))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $timezone = $this->organization()->timezone;

        return Inertia::render('campaigns/Show', [
            'campaign' => $this->detail($campaign),
            'stats' => fn () => $report->metrics($campaign) + [
                'pending' => $campaign->recipients()->whereIn('status', ['pending', 'sending'])->count(),
            ],
            'links' => fn () => $canReport ? $report->links($campaign) : [],
            'timeline' => fn () => $canReport ? $report->timeline($campaign, $timezone) : null,
            'recipients' => fn () => $canReport && ! $campaign->is_historic
                ? $report->recipients($campaign, $filters['activity'] ?? null, $filters['q'] ?? null)
                    ->paginate(25)->withQueryString()->through(fn (CampaignRecipient $r) => CampaignReport::recipientRow($r))
                : null,
            'abResults' => fn () => $campaign->isAbTest() ? $tester->results($campaign) : null,
            'filters' => ['activity' => $filters['activity'] ?? '', 'q' => $filters['q'] ?? ''],
            'activityOptions' => CampaignReport::FILTERS,
            'canSend' => $user?->can(Permission::CampaignsSend->value) ?? false,
            'canManage' => $user?->can(Permission::CampaignsManage->value) ?? false,
            'canReport' => $canReport,
        ]);
    }

    public function export(Request $request, Campaign $campaign, CampaignReport $report, #[CurrentUser] User $user): StreamedResponse
    {
        $activity = $request->validate(['activity' => ['nullable', Rule::in(array_keys(CampaignReport::FILTERS))]])['activity'] ?? null;

        Audit::record('campaigns.report_exported', 'Exportación del reporte de campaña', $campaign, ['activity' => $activity], $user);

        $filename = Str::slug($campaign->name).'-reporte-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($campaign, $report, $activity) {
            $csv = Writer::createFromPath('php://output', 'w');
            $csv->setOutputBOM(Writer::BOM_UTF8);
            $csv->addFormatter(new EscapeFormula);
            $csv->insertOne(['correo', 'nombre', 'estado_envio', 'enviado', 'aperturas', 'clics', 'rebote', 'baja', 'queja_spam', 'ultima_actividad', 'error']);

            $report->recipients($campaign, $activity)->lazyById(1000, 'campaign_recipients.id', 'id')
                ->each(function (CampaignRecipient $r) use ($csv) {
                    $row = CampaignReport::recipientRow($r);
                    $csv->insertOne([
                        $row['email'], $row['name'], $row['status_label'], $row['sent_at'], $row['opens'], $row['clicks'],
                        $row['bounce'], $row['unsubscribed'] ? 'sí' : 'no', $row['complained'] ? 'sí' : 'no', $row['last_activity_at'], $row['error'],
                    ]);
                });
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Campaign $campaign, CampaignRenderer $renderer): HttpResponse
    {
        if ($campaign->is_historic) {
            // Mailchimp history is shown as it was sent (sanitized), without our footer.
            return PreviewResponse::make((string) $campaign->html);
        }

        $campaign->load('audience');

        return PreviewResponse::make($renderer->render($campaign, $this->organization(), $campaign->audience, null, null)->html);
    }

    public function duplicate(Campaign $campaign, #[CurrentUser] User $user): RedirectResponse
    {
        $copy = $campaign->replicate([
            'status', 'scheduled_at', 'started_at', 'finished_at', 'recipients_count', 'sent_count', 'failed_count', 'skipped_count',
            'status_reason', 'job_batch_id', 'last_test_sent_at', 'is_historic', 'mailchimp_id', 'mailchimp_web_id', 'report_summary',
        ]);
        $copy->name = mb_substr($campaign->name.' (copia)', 0, 150);
        $copy->status = CampaignStatus::Draft;
        $copy->created_by = $user->id;

        if ($campaign->is_historic) {
            $copy->editor = 'html'; // Mailchimp content is plain HTML
        }

        $copy->save();

        return to_route('campaigns.edit', $copy);
    }

    public function destroy(Campaign $campaign, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($campaign->status === CampaignStatus::Draft, 422, 'Solo se pueden eliminar borradores.');

        $campaign->delete();
        Audit::record('campaigns.deleted', 'Campaña eliminada', $campaign, [], $user);

        return to_route('campaigns.index');
    }

    /**
     * Version B of a content A/B test (?variant=B). Starts as a copy of version A.
     */
    private function contentVariant(Request $request, Campaign $campaign): ?CampaignVariant
    {
        if ($campaign->ab_type !== 'content' || strtoupper((string) $request->input('variant')) !== 'B') {
            return null;
        }

        $variant = $campaign->variants()->firstOrCreate(['label' => 'B']);

        if (blank($variant->html) && blank($variant->mjml)) {
            $variant->update(['mjml' => $campaign->mjml, 'design' => $campaign->design, 'html' => $campaign->html]);
        }

        return $variant;
    }

    /** Automation emails can be edited at any time; regular campaigns only before sending. */
    private function contentEditable(Campaign $campaign): bool
    {
        return $campaign->isAutomation() || ($campaign->status->isEditable() && ! $campaign->is_historic);
    }

    private function toAutomation(Campaign $campaign): RedirectResponse
    {
        $automationId = AutomationStep::query()->where('campaign_id', $campaign->id)->value('automation_id');

        return $automationId !== null ? to_route('automations.edit', $automationId) : to_route('campaigns.show', $campaign);
    }

    private function ensureEditable(Campaign $campaign): void
    {
        if (! $this->contentEditable($campaign)) {
            throw ValidationException::withMessages(['campaign' => 'Esta campaña ya no se puede modificar.']);
        }
    }

    private function organization(): Organization
    {
        return $this->current->get() ?? throw new RuntimeException('Sin organización.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'subject' => $campaign->subject,
            'audience' => $campaign->audience?->name,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'is_historic' => $campaign->is_historic,
            'recipients_count' => $campaign->recipients_count,
            'sent_count' => $campaign->sent_count,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'finished_at' => $campaign->finished_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Campaign $campaign): array
    {
        return $this->summary($campaign) + [
            'preheader' => $campaign->preheader,
            'from_name' => $campaign->from_name,
            'from_email' => $campaign->from_email,
            'reply_to' => $campaign->reply_to,
            'audience_id' => $campaign->audience_id,
            'tag_ids' => $campaign->tag_ids,
            'mail_connection_id' => $campaign->mail_connection_id,
            'connection' => $campaign->mailConnection?->name,
            'editor' => $campaign->editor,
            'has_content' => filled($campaign->html) || filled($campaign->mjml),
            'rate_per_minute' => $campaign->rate_per_minute,
            'track_opens' => $campaign->track_opens,
            'track_clicks' => $campaign->track_clicks,
            'failed_count' => $campaign->failed_count,
            'skipped_count' => $campaign->skipped_count,
            'status_reason' => $campaign->status_reason,
            'started_at' => $campaign->started_at?->toIso8601String(),
            'last_test_sent_at' => $campaign->last_test_sent_at?->toIso8601String(),
            'timezone' => $campaign->timezone,
            'report_summary' => $campaign->report_summary,
            'kind' => $campaign->kind,
            'segment_id' => $campaign->segment_id,
            'segment' => $campaign->segment?->name,
            'ab_type' => $campaign->ab_type,
            'ab_sample_percent' => $campaign->ab_sample_percent,
            'ab_metric' => $campaign->ab_metric,
            'ab_wait_hours' => $campaign->ab_wait_hours,
            'ab_winner_variant_id' => $campaign->ab_winner_variant_id,
            'ab_decided_at' => $campaign->ab_decided_at?->toIso8601String(),
            'variants' => $campaign->variants->map(fn (CampaignVariant $v) => [
                'id' => $v->id,
                'label' => $v->label,
                'subject' => $v->subject,
                'from_name' => $v->from_name,
                'has_content' => filled($v->html) || filled($v->mjml),
            ])->values(),
        ];
    }
}
