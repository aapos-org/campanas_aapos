<?php

namespace App\Http\Controllers\Contacts;

use App\Enums\ContactStatus;
use App\Enums\EventType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\AuditLog;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\MergeField;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use App\Services\Contacts\ContactWriter;
use App\Services\Contacts\SubscriptionManager;
use App\Services\Contacts\SuppressionService;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function __construct(
        private readonly ContactWriter $writer,
        private readonly SubscriptionManager $subscriptions,
        private readonly SuppressionService $suppressions,
    ) {}

    public function create(Audience $audience): Response
    {
        return Inertia::render('contacts/Form', [
            'contact' => null,
            'audience' => $audience->only(['id', 'name']),
            'fields' => $this->fieldDefinitions(),
            'tags' => Tag::query()->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request, Audience $audience, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $this->validateContact($request, creating: true);

        $contact = DB::transaction(function () use ($data, $audience, $request, $user) {
            $contact = $this->writer->save(null, $data);
            $this->writer->attachTags([$contact->id], $data['tags'] ?? []);

            $status = ContactStatus::from($data['status']);
            $this->subscriptions->setStatus($contact, $audience, $status, $status === ContactStatus::Subscribed ? [
                'source' => 'manual',
                'detail' => $data['consent_detail'] ?? null,
                'ip' => null,
            ] : [], $user);

            Audit::record('contacts.created', 'Contacto creado manualmente', $contact, [
                'audience_id' => $audience->id,
                'status' => $status->value,
                'ip' => $request->ip(),
            ], $user);

            return $contact;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contacto agregado.']);

        return to_route('contacts.show', $contact);
    }

    public function show(Request $request, Contact $contact): Response
    {
        $contact->load(['tags:id,name', 'subscriptions.audience:id,name']);
        $fieldDefinitions = $this->fieldDefinitions();

        $activity = AuditLog::query()
            ->where('subject_type', $contact->getMorphClass())
            ->where('subject_id', $contact->id)
            ->with('causer')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'description' => $log->description,
                'event' => $log->event,
                'causer' => $log->causer instanceof User ? $log->causer->name : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('contacts/Show', [
            'contact' => [
                'id' => $contact->id,
                'email' => $contact->email,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'full_name' => $contact->full_name,
                'phone' => $contact->phone,
                'fields' => $contact->fields,
                'created_at' => $contact->created_at?->toIso8601String(),
                'tags' => $contact->tags->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name])->values(),
            ],
            'fields' => $fieldDefinitions,
            'subscriptions' => $contact->subscriptions->map(fn (Subscription $s) => [
                'id' => $s->id,
                'audience' => $s->audience?->only(['id', 'name']),
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'subscribed_at' => $s->subscribed_at?->toIso8601String(),
                'unsubscribed_at' => $s->unsubscribed_at?->toIso8601String(),
                'unsubscribe_reason' => $s->unsubscribe_reason,
                'consent_at' => $s->consent_at?->toIso8601String(),
                'consent_ip' => $s->consent_ip,
                'consent_source' => $s->consent_source,
                'consent_detail' => $s->consent_detail,
                'block_reason' => $s->audience ? $this->subscriptions->blockReason($contact, $s->audience, $s) : null,
            ])->values(),
            'suppression' => ($found = $this->suppressions->find($contact->email)) ? [
                'reason' => $found->reason->label(),
                'created_at' => $found->created_at?->toIso8601String(),
            ] : null,
            'statuses' => ContactStatus::options(),
            'activity' => $activity,
            'campaigns' => $this->campaignHistory($contact),
            'canManage' => $request->user()?->can(Permission::ContactsManage->value) ?? false,
        ]);
    }

    /**
     * Last campaigns sent to the contact with their activity (opens, clicks, bounces…).
     *
     * @return list<array<string, mixed>>
     */
    private function campaignHistory(Contact $contact): array
    {
        $activity = DB::table('email_events')
            ->where('contact_id', $contact->id)
            ->whereNotNull('campaign_recipient_id')
            ->selectRaw('campaign_recipient_id')
            ->selectRaw("count(*) FILTER (WHERE type = 'open') as opens")
            ->selectRaw("count(*) FILTER (WHERE type = 'click') as clicks")
            ->selectRaw("string_agg(DISTINCT type, ',') FILTER (WHERE type IN ('bounce_hard', 'bounce_soft', 'complaint', 'unsubscribe')) as issues")
            ->groupBy('campaign_recipient_id');

        return array_values(CampaignRecipient::query()
            ->where('campaign_recipients.contact_id', $contact->id)
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->leftJoinSub($activity, 'a', 'a.campaign_recipient_id', '=', 'campaign_recipients.id')
            ->whereNull('campaigns.deleted_at')
            ->orderByDesc('campaign_recipients.sent_at')->orderByDesc('campaign_recipients.id')
            ->limit(50)
            ->get(['campaign_recipients.*', 'campaigns.name as campaign_name', 'a.opens', 'a.clicks', 'a.issues'])
            ->map(function (CampaignRecipient $r) {
                $issues = array_filter(explode(',', (string) $r->getAttribute('issues')));

                return [
                    'id' => $r->id,
                    'campaign_id' => $r->campaign_id,
                    'campaign' => (string) $r->getAttribute('campaign_name'),
                    'status_label' => $r->status->label(),
                    'sent_at' => $r->sent_at?->toIso8601String(),
                    'opens' => (int) $r->getAttribute('opens'),
                    'clicks' => (int) $r->getAttribute('clicks'),
                    'issues' => array_values(array_map(fn (string $t) => EventType::from($t)->label(), $issues)),
                ];
            })->all());
    }

    public function edit(Contact $contact): Response
    {
        return Inertia::render('contacts/Form', [
            'contact' => [
                'id' => $contact->id,
                'email' => $contact->email,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'phone' => $contact->phone,
                'fields' => $contact->fields,
                'tags' => $contact->tags()->pluck('name'),
            ],
            'audience' => null,
            'fields' => $this->fieldDefinitions(),
            'tags' => Tag::query()->orderBy('name')->pluck('name'),
        ]);
    }

    public function update(Request $request, Contact $contact, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $this->validateContact($request, creating: false);
        $previousEmail = $contact->email;

        DB::transaction(function () use ($contact, $data) {
            $this->writer->save($contact, $data);
            $contact->tags()->sync($this->writer->tagIds($data['tags'] ?? []));
        });

        Audit::record('contacts.updated', 'Contacto modificado', $contact, array_filter([
            'email_changed' => $previousEmail !== $contact->email ? [$previousEmail, $contact->email] : null,
        ]), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contacto actualizado.']);

        return to_route('contacts.show', $contact);
    }

    public function updateStatus(Request $request, Subscription $subscription, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ContactStatus::class)],
            'consent_detail' => ['nullable', 'string', 'max:500'],
        ]);

        $status = ContactStatus::from($data['status']);
        $previous = $subscription->status;
        $contact = $subscription->contact()->firstOrFail();
        $audience = $subscription->audience()->firstOrFail();

        $this->subscriptions->setStatus($contact, $audience, $status, $status === ContactStatus::Subscribed ? [
            'source' => filled($data['consent_detail'] ?? null) ? 'manual' : null,
            'detail' => $data['consent_detail'] ?? null,
        ] : [], $user);

        Audit::record('contacts.status_changed', 'Estado de suscripción modificado', $contact, [
            'audience_id' => $audience->id,
            'from' => $previous->value,
            'to' => $status->value,
        ], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Estado cambiado a «{$status->label()}»."]);

        return back();
    }

    /**
     * Bulk actions on contacts of an audience (selected ids, or every contact matching the filters).
     */
    public function bulk(Request $request, Audience $audience, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['add_tags', 'remove_tags'])],
            'contact_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'contact_ids.*' => ['integer'],
            'tags' => ['required', 'array', 'min:1'],
            'tags.*' => ['string', 'max:100'],
        ], [], ['tags' => 'etiquetas', 'contact_ids' => 'contactos']);

        // Only contacts that belong to this audience (and therefore to this organization).
        $contactIds = Subscription::query()
            ->where('audience_id', $audience->id)
            ->whereIn('contact_id', $data['contact_ids'])
            ->pluck('contact_id');

        if ($data['action'] === 'add_tags') {
            $this->writer->attachTags($contactIds, $data['tags']);
            $message = "Etiquetas agregadas a {$contactIds->count()} contactos.";
        } else {
            $tagIds = Tag::query()->whereIn('name', $data['tags'])->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $this->writer->detachTags($contactIds, $tagIds);
            $message = "Etiquetas quitadas de {$contactIds->count()} contactos.";
        }

        Audit::record('contacts.bulk_tags', 'Etiquetas asignadas en bloque', $audience, [
            'action' => $data['action'],
            'tags' => $data['tags'],
            'contacts' => $contactIds->count(),
        ], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function destroy(Contact $contact, #[CurrentUser] User $user): RedirectResponse
    {
        $contact->delete();

        Audit::record('contacts.deleted', 'Contacto eliminado', $contact, ['email' => $contact->email], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contacto eliminado. Si estaba suprimido, la supresión se conserva.']);

        return to_route('audiences.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateContact(Request $request, bool $creating): array
    {
        return $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'fields' => ['array'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:100'],
            'status' => $creating ? ['required', Rule::in([ContactStatus::Subscribed->value, ContactStatus::NonSubscribed->value])] : ['prohibited'],
            'consent_detail' => $creating ? ['required_if:status,subscribed', 'nullable', 'string', 'max:500'] : ['prohibited'],
        ], [
            'consent_detail.required_if' => 'Indica cómo obtuviste el consentimiento (p. ej. «Formulario firmado en el evento del 12/03»).',
        ], [
            'email' => 'correo electrónico',
            'first_name' => 'nombre',
            'last_name' => 'apellido',
            'phone' => 'teléfono',
            'consent_detail' => 'origen del consentimiento',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fieldDefinitions(): array
    {
        return MergeField::query()->orderBy('position')->orderBy('label')->get()
            ->map(fn (MergeField $f) => [
                'key' => $f->key,
                'label' => $f->label,
                'type' => $f->type->value,
                'options' => $f->options,
                'required' => $f->required,
            ])->values()->all();
    }
}
