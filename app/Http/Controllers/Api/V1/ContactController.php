<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Services\Contacts\ContactWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Contacts. A contact is unique by email in the organization; its membership in each
 * audience is a subscription (see /audiences/{id}/subscribe).
 */
class ContactController extends Controller
{
    public function __construct(private readonly ContactWriter $writer) {}

    /**
     * List contacts (paginated, filterable).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'audience_id' => ['nullable', 'integer'],
            /** Membership status in `audience_id`. */
            'status' => ['nullable', Rule::enum(ContactStatus::class)],
            'email' => ['nullable', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:100'],
            /** ISO-8601 date: only contacts modified since then. */
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $contacts = Contact::query()->with(['tags:id,name', 'subscriptions'])
            ->when($filters['email'] ?? null, fn ($q, $email) => $q->where('email', Contact::normalizeEmail($email)))
            ->when($filters['audience_id'] ?? null, fn ($q, $id) => $q->whereHas('subscriptions', fn ($s) => $s->where('audience_id', $id)
                ->when($filters['status'] ?? null, fn ($w, $status) => $w->where('status', $status))))
            ->when($filters['tag'] ?? null, fn ($q, $tag) => $q->whereHas('tags', fn ($t) => $t->where('name', $tag)))
            ->when($filters['updated_since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();

        return ContactResource::collection($contacts);
    }

    /**
     * Show one contact.
     */
    public function show(Contact $contact): ContactResource
    {
        return new ContactResource($contact->load(['tags:id,name', 'subscriptions']));
    }

    /**
     * Create or update a contact by email (upsert). Does not subscribe it to any audience.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, required: true);
        $existing = Contact::query()->where('email', Contact::normalizeEmail($data['email']))->first();

        $contact = $this->writer->save($existing, $data + ['fields' => []]);
        $this->syncTags($contact, $data['tags'] ?? null);

        return (new ContactResource($contact->load(['tags:id,name', 'subscriptions'])))
            ->response()->setStatusCode($existing === null ? 201 : 200);
    }

    /**
     * Update a contact (only the fields sent).
     */
    public function update(Request $request, Contact $contact): ContactResource
    {
        $data = $this->validated($request, required: false);

        $contact = $this->writer->save($contact, array_merge([
            'email' => $contact->email,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'phone' => $contact->phone,
        ], $data));
        $this->syncTags($contact, $data['tags'] ?? null);

        return new ContactResource($contact->load(['tags:id,name', 'subscriptions']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $required): array
    {
        return $request->validate([
            'email' => [$required ? 'required' : 'sometimes', 'email:rfc', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            /** Custom fields by key. */
            'fields' => ['sometimes', 'array'],
            /** Tag names to add (existing tags are kept). */
            'tags' => ['sometimes', 'array', 'max:50'],
            'tags.*' => ['string', 'max:100'],
        ], [], ['email' => 'correo', 'first_name' => 'nombre', 'last_name' => 'apellido', 'phone' => 'teléfono']);
    }

    /**
     * @param  list<string>|null  $names
     */
    private function syncTags(Contact $contact, ?array $names): void
    {
        if ($names !== null && $names !== []) {
            $this->writer->attachTags([$contact->id], $names);
        }
    }
}
