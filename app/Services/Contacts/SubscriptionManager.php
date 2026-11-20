<?php

namespace App\Services\Contacts;

use App\Enums\ContactStatus;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * All status changes of a subscription go through here so the consent and
 * suppression rules are enforced in one place.
 */
class SubscriptionManager
{
    public function __construct(private readonly SuppressionService $suppressions) {}

    /**
     * Why the contact cannot be set to an active status (subscribed/pending), or null if it can.
     */
    public function blockReason(Contact $contact, Audience $audience, ?Subscription $current = null): ?string
    {
        $suppression = $this->suppressions->find($contact->email, $audience->id);

        if ($suppression !== null) {
            return $suppression->isGlobal()
                ? "La dirección está en la lista de supresión global ({$suppression->reason->label()})."
                : "La dirección se dio de baja de esta audiencia ({$suppression->reason->label()}).";
        }

        if ($current !== null && $current->status->isTerminal()) {
            return "El contacto está «{$current->status->label()}» en esta audiencia y no puede reactivarse automáticamente: debe suscribirse de nuevo por un formulario.";
        }

        return null;
    }

    /**
     * Adds or updates the membership done by staff (manual edit / import).
     *
     * @param  array<string, string|null>  $consent  source, detail, ip
     *
     * @throws ValidationException when an active status is blocked or lacks consent evidence
     */
    public function setStatus(
        Contact $contact,
        Audience $audience,
        ContactStatus $status,
        array $consent = [],
        ?User $by = null,
    ): Subscription {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->where('audience_id', $audience->id)
            ->where('contact_id', $contact->id)
            ->first();

        if ($subscription !== null && $subscription->status === $status) {
            return $subscription;
        }

        if (in_array($status, [ContactStatus::Subscribed, ContactStatus::Pending], true)) {
            if ($reason = $this->blockReason($contact, $audience, $subscription)) {
                throw ValidationException::withMessages(['status' => $reason]);
            }

            if ($status === ContactStatus::Subscribed && blank($consent['source'] ?? null) && $subscription?->consent_at === null) {
                throw ValidationException::withMessages([
                    'status' => 'Para marcar a alguien como suscrito hace falta indicar el origen de su consentimiento.',
                ]);
            }
        }

        $attributes = ['status' => $status];

        if ($status === ContactStatus::Subscribed) {
            $attributes['subscribed_at'] = now();
            $attributes['unsubscribed_at'] = null;

            if (filled($consent['source'] ?? null)) {
                $attributes['consent_at'] = now();
                $attributes['consent_source'] = $consent['source'];
                $attributes['consent_detail'] = $consent['detail'] ?? null;
                $attributes['consent_ip'] = $consent['ip'] ?? null;
            }
        }

        $subscription ??= new Subscription([
            'audience_id' => $audience->id,
            'contact_id' => $contact->id,
            'organization_id' => $contact->organization_id,
        ]);
        $subscription->fill($attributes)->save();

        // Unsubscribing / cleaning feeds the suppression list so it can never be undone silently.
        if ($status === ContactStatus::Unsubscribed) {
            $this->suppressions->suppress($contact->email, SuppressionReason::Unsubscribed, $audience, 'manual', null, $by);
        } elseif ($status === ContactStatus::Cleaned) {
            $this->suppressions->suppress($contact->email, SuppressionReason::Cleaned, null, 'manual', null, $by);
        }

        return $subscription->refresh();
    }
}
