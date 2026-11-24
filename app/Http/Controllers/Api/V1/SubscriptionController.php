<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\ContactWriter;
use App\Services\Contacts\SubscriptionManager;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Subscribe and unsubscribe. The same rules as the panel apply: suppressed addresses
 * (bounces, complaints, previous unsubscribes) are never reactivated, and subscribing
 * requires recording where the person gave consent.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly ContactWriter $writer,
        private readonly SubscriptionManager $subscriptions,
    ) {}

    /**
     * Subscribe an email to the audience (creates the contact if needed).
     *
     * Returns 422 with the reason if the address cannot be subscribed (for example, it
     * unsubscribed before or bounced).
     */
    public function subscribe(Request $request, Audience $audience, #[CurrentUser] User $user): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'fields' => ['sometimes', 'array'],
            'tags' => ['sometimes', 'array', 'max:50'],
            'tags.*' => ['string', 'max:100'],
            /** Where and how the person agreed to receive emails (stored as consent evidence). */
            'consent_detail' => ['required', 'string', 'max:500'],
            /** IP address of the person when they agreed, if known. */
            'consent_ip' => ['nullable', 'ip'],
        ], [], ['email' => 'correo', 'consent_detail' => 'detalle del consentimiento']);

        $contact = DB::transaction(function () use ($data, $audience, $user) {
            $existing = Contact::query()->where('email', Contact::normalizeEmail($data['email']))->first();
            $contact = $this->writer->save($existing, array_merge(
                $existing !== null ? $existing->only(['first_name', 'last_name', 'phone']) : [],
                array_filter($data, fn ($v) => $v !== null),
                ['fields' => $data['fields'] ?? []],
            ));

            $this->subscriptions->setStatus($contact, $audience, ContactStatus::Subscribed, [
                'source' => 'api',
                'detail' => $data['consent_detail'],
                'ip' => $data['consent_ip'] ?? null,
            ], $user);

            if (! empty($data['tags'])) {
                $this->writer->attachTags([$contact->id], array_values($data['tags']));
            }

            return $contact;
        });

        // 200 even when the contact was just created: this is an action, not a resource creation.
        return (new ContactResource($contact->load(['tags:id,name', 'subscriptions'])))->response()->setStatusCode(200);
    }

    /**
     * Unsubscribe an email from the audience. It is added to the audience's suppression
     * list, so it cannot be subscribed again by mistake.
     */
    public function unsubscribe(Request $request, Audience $audience, #[CurrentUser] User $user): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']], [], ['email' => 'correo']);
        $contact = Contact::query()->where('email', Contact::normalizeEmail($data['email']))->first();

        if ($contact === null) {
            return response()->json(['message' => 'No existe un contacto con ese correo.'], 404);
        }

        $this->subscriptions->setStatus($contact, $audience, ContactStatus::Unsubscribed, [], $user);

        return (new ContactResource($contact->load(['tags:id,name', 'subscriptions'])))->response();
    }
}
