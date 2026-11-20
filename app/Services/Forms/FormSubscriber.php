<?php

namespace App\Services\Forms;

use App\Enums\ContactStatus;
use App\Enums\SuppressionReason;
use App\Jobs\SendOptInConfirmation;
use App\Models\Contact;
use App\Models\Form;
use App\Models\MergeField;
use App\Models\Subscription;
use App\Models\Suppression;
use App\Services\Contacts\ContactWriter;
use App\Services\Contacts\FieldNormalizer;
use App\Services\Contacts\SuppressionService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Signup through a public form.
 *
 *  - Double opt-in (form or audience setting): the subscription stays "pending" and a
 *    confirmation email with a signed, expiring link is sent.
 *  - Single opt-in: subscribed immediately, with consent evidence (date, IP, form).
 *  - Someone previously unsubscribed, bounced or suppressed ALWAYS goes through confirmation,
 *    and their current status is not touched until they confirm (a third party typing
 *    their address cannot change anything).
 *  - Existing data is never overwritten by a public form (only empty fields are filled).
 */
class FormSubscriber
{
    public const string RESULT_SUBSCRIBED = 'subscribed';

    public const string RESULT_PENDING = 'pending';

    public const string RESULT_ALREADY = 'already';

    public function __construct(
        private readonly FieldNormalizer $normalizer,
        private readonly SuppressionService $suppressions,
        private readonly ContactWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return string one of the RESULT_* constants
     *
     * @throws ValidationException
     */
    public function subscribe(Form $form, array $input, ?string $ip): string
    {
        $email = Contact::normalizeEmail((string) ($input['email'] ?? ''));
        [$attributes, $errors] = $this->validate($form, $input);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors['email'] = 'Escribe un correo electrónico válido.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $audience = $form->audience;

        return DB::transaction(function () use ($form, $audience, $email, $attributes, $ip) {
            $contact = Contact::query()->withTrashed()->where('email', $email)->first();

            if ($contact === null) {
                $contact = Contact::create(['email' => $email] + $attributes);
            } else {
                if ($contact->trashed()) {
                    $contact->restore();
                }
                $this->fillMissing($contact, $attributes);
            }

            /** @var Subscription|null $subscription */
            $subscription = Subscription::query()->where('audience_id', $audience->id)->where('contact_id', $contact->id)->first();

            DB::table('forms')->where('id', $form->id)->increment('submissions_count');

            if ($subscription?->status === ContactStatus::Subscribed) {
                return self::RESULT_ALREADY;
            }

            $blocked = $this->suppressions->isSuppressed($email, $audience->id)
                || ($subscription !== null && $subscription->status->isTerminal());

            $signupDetail = sprintf('Formulario «%s» · solicitud %s desde IP %s', $form->name, now()->toIso8601String(), $ip ?? 'desconocida');

            if (! $form->usesDoubleOptIn() && ! $blocked) {
                $subscription = $this->upsertSubscription($subscription, $form, $contact, [
                    'status' => ContactStatus::Subscribed,
                    'subscribed_at' => now(),
                    'unsubscribed_at' => null,
                    'consent_at' => now(),
                    'consent_ip' => $ip,
                    'consent_source' => 'form',
                    'consent_detail' => mb_substr('Alta simple · '.$signupDetail, 0, 500),
                ]);
                $this->applyTags($form, $contact);
                Audit::record('forms.subscribed', 'Alta por formulario (sin doble opt-in)', $contact, ['form_id' => $form->id, 'audience_id' => $audience->id]);

                return self::RESULT_SUBSCRIBED;
            }

            // Manually suppressed: answer neutrally and send nothing.
            if ($this->hasManualSuppression($email, $audience->id)) {
                Audit::record('forms.optin_blocked', 'Solicitud ignorada: dirección suprimida manualmente', $contact, ['form_id' => $form->id, 'audience_id' => $audience->id]);

                return self::RESULT_PENDING;
            }

            // Double opt-in (or a blocked address): status changes only after confirmation.
            if ($subscription === null || ! $subscription->status->isTerminal()) {
                $subscription = $this->upsertSubscription($subscription, $form, $contact, [
                    'status' => ContactStatus::Pending,
                    'consent_detail' => mb_substr('Pendiente de confirmar · '.$signupDetail, 0, 500),
                ]);
            }

            SendOptInConfirmation::dispatch($subscription->id, $form->id)->afterCommit();
            Audit::record('forms.optin_requested', 'Solicitud de suscripción pendiente de confirmar', $contact, ['form_id' => $form->id, 'audience_id' => $audience->id]);

            return self::RESULT_PENDING;
        });
    }

    /**
     * The person clicked the signed confirmation link: subscribe and lift their own suppressions.
     */
    public function confirm(Subscription $subscription, Form $form, ?string $ip): void
    {
        if ($subscription->status === ContactStatus::Subscribed) {
            return;
        }

        $contact = $subscription->contactOrFail();

        if ($this->hasManualSuppression($contact->email, $subscription->audience_id)) {
            Audit::record('forms.optin_blocked', 'Confirmación ignorada: dirección suprimida manualmente', $contact, ['form_id' => $form->id]);

            return;
        }

        DB::transaction(function () use ($subscription, $form, $contact, $ip) {
            // The person proves control of the mailbox and asks to receive email again:
            // lift the suppressions of this audience and the global ones.
            $lifted = Suppression::query()
                ->where('email', $contact->email)
                ->where(fn ($q) => $q->whereNull('audience_id')->orWhere('audience_id', $subscription->audience_id))
                ->where('reason', '!=', SuppressionReason::Manual)
                ->get();

            foreach ($lifted as $suppression) {
                $suppression->delete();
            }

            $subscription->update([
                'status' => ContactStatus::Subscribed,
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
                'unsubscribe_reason' => null,
                'consent_at' => now(),
                'consent_ip' => $ip,
                'consent_source' => 'form',
                'consent_detail' => mb_substr(sprintf('Doble opt-in confirmado %s · formulario «%s»', now()->toIso8601String(), $form->name), 0, 500),
            ]);

            $this->applyTags($form, $contact);

            Audit::record('forms.optin_confirmed', 'Suscripción confirmada por la persona (doble opt-in)', $contact, [
                'form_id' => $form->id,
                'audience_id' => $subscription->audience_id,
                'suppressions_lifted' => $lifted->map(fn (Suppression $s) => $s->reason->value)->all(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validate(Form $form, array $input): array
    {
        $definitions = MergeField::query()->get()->keyBy('key');
        $attributes = ['fields' => []];
        $errors = [];

        foreach ($form->fields as $field) {
            $key = $field['key'];

            if ($key === 'email') {
                continue;
            }

            $value = $input[$key] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value === '') {
                if ($field['required']) {
                    $errors[$key] = "Completa el campo «{$field['label']}».";
                }

                continue;
            }

            if (in_array($key, ['first_name', 'last_name'], true)) {
                $attributes[$key] = mb_substr($value, 0, 255);
            } elseif ($key === 'phone') {
                [$phone, $error] = $this->normalizer->phone($value, $field['label']);
                $error ? $errors[$key] = $error : $attributes['phone'] = $phone;
            } elseif (($definition = $definitions->get($key)) !== null) {
                [$normalized, $error] = $this->normalizer->normalize($definition, $value);
                $error ? $errors[$key] = $error : $attributes['fields'][$key] = $normalized;
            }
        }

        return [$attributes, $errors];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fillMissing(Contact $contact, array $attributes): void
    {
        foreach (['first_name', 'last_name', 'phone'] as $column) {
            if (blank($contact->getAttribute($column)) && filled($attributes[$column] ?? null)) {
                $contact->setAttribute($column, $attributes[$column]);
            }
        }

        $contact->fields = array_merge((array) ($attributes['fields'] ?? []), $contact->fields);

        if ($contact->isDirty()) {
            $contact->save();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertSubscription(?Subscription $subscription, Form $form, Contact $contact, array $attributes): Subscription
    {
        $subscription ??= new Subscription([
            'organization_id' => $form->organization_id,
            'audience_id' => $form->audience_id,
            'contact_id' => $contact->id,
        ]);

        $subscription->fill($attributes)->save();

        return $subscription;
    }

    private function applyTags(Form $form, Contact $contact): void
    {
        if ($form->tags !== []) {
            $this->writer->attachTags([$contact->id], $form->tags);
        }
    }

    /**
     * A manual suppression (staff decision, e.g. a removal request) is never lifted by a public form.
     */
    private function hasManualSuppression(string $email, int $audienceId): bool
    {
        return Suppression::query()
            ->where('email', $email)
            ->where('reason', SuppressionReason::Manual)
            ->where(fn ($q) => $q->whereNull('audience_id')->orWhere('audience_id', $audienceId))
            ->exists();
    }
}
