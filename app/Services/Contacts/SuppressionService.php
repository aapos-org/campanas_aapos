<?php

namespace App\Services\Contacts;

use App\Enums\ContactStatus;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\Suppression;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Suppression list rules (docs/DECISIONES.md D08):
 *  - Hard bounces, complaints and "cleaned" are always global.
 *  - An unsubscribe applies to one audience, or to all of them ("baja de todo").
 *  - A suppressed address is never (re)subscribed by imports or automatic processes.
 */
class SuppressionService
{
    public function isSuppressed(string $email, ?int $audienceId = null): bool
    {
        return $this->query(Contact::normalizeEmail($email), $audienceId)->exists();
    }

    /**
     * Suppression that blocks the address for the audience (global first), if any.
     */
    public function find(string $email, ?int $audienceId = null): ?Suppression
    {
        return $this->query(Contact::normalizeEmail($email), $audienceId)
            ->orderByRaw('audience_id IS NOT NULL')
            ->first();
    }

    /**
     * Emails (normalized) from the list that are suppressed for the audience. One query.
     *
     * @param  list<string>  $emails
     * @return array<string, SuppressionReason>
     */
    public function suppressedAmong(array $emails, int $audienceId): array
    {
        if ($emails === []) {
            return [];
        }

        return Suppression::query()
            ->whereIn('email', $emails)
            ->where(fn ($q) => $q->whereNull('audience_id')->orWhere('audience_id', $audienceId))
            ->get(['email', 'reason'])
            ->mapWithKeys(fn (Suppression $s) => [$s->email => $s->reason])
            ->all();
    }

    /**
     * Suppresses an address and updates the affected subscriptions.
     */
    public function suppress(
        string $email,
        SuppressionReason $reason,
        ?Audience $audience = null,
        ?string $source = null,
        ?string $detail = null,
        ?User $by = null,
    ): Suppression {
        $email = Contact::normalizeEmail($email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Correo inválido: {$email}");
        }

        $audienceId = $reason->isAlwaysGlobal() ? null : $audience?->id;

        return DB::transaction(function () use ($email, $reason, $audienceId, $source, $detail, $by) {
            $existing = Suppression::query()
                ->where('email', $email)
                ->when($audienceId === null, fn ($q) => $q->whereNull('audience_id'), fn ($q) => $q->where('audience_id', $audienceId))
                ->first();

            $suppression = $existing ?? Suppression::create([
                'email' => $email,
                'audience_id' => $audienceId,
                'reason' => $reason,
                'source' => $source,
                'detail' => $detail,
                'created_by' => $by?->id,
            ]);

            // A stronger reason (bounce/complaint) replaces a weaker one on the same scope.
            if ($existing !== null && $existing->reason !== $reason && $reason->isAlwaysGlobal()) {
                $existing->update(['reason' => $reason, 'source' => $source, 'detail' => $detail]);
            }

            $this->applyToSubscriptions($email, $reason, $audienceId);

            if ($existing === null) {
                Audit::record('suppressions.created', 'Dirección agregada a la lista de supresión', $suppression, [
                    'email' => $email,
                    'reason' => $reason->value,
                    'scope' => $audienceId === null ? 'global' : 'audience:'.$audienceId,
                ], $by);
            }

            return $suppression;
        });
    }

    /**
     * Only manual suppressions can be lifted. Subscriptions are not reactivated:
     * the person must subscribe again.
     */
    public function lift(Suppression $suppression, ?User $by = null): void
    {
        if (! $suppression->reason->isRemovable()) {
            throw new InvalidArgumentException('Solo se pueden quitar las supresiones agregadas manualmente.');
        }

        $suppression->delete();

        Audit::record('suppressions.removed', 'Supresión manual eliminada', null, [
            'email' => $suppression->email,
            'scope' => $suppression->audience_id === null ? 'global' : 'audience:'.$suppression->audience_id,
        ], $by);
    }

    private function applyToSubscriptions(string $email, SuppressionReason $reason, ?int $audienceId): void
    {
        $contact = Contact::query()->where('email', $email)->first();

        if ($contact === null) {
            return;
        }

        $newStatus = match ($reason) {
            SuppressionReason::HardBounce, SuppressionReason::Cleaned => ContactStatus::Cleaned,
            default => ContactStatus::Unsubscribed,
        };

        /** @var Collection<int, Subscription> $subscriptions */
        $subscriptions = Subscription::query()
            ->where('contact_id', $contact->id)
            ->when($audienceId !== null, fn ($q) => $q->where('audience_id', $audienceId))
            ->whereIn('status', [ContactStatus::Subscribed->value, ContactStatus::Pending->value, ContactStatus::NonSubscribed->value])
            ->get();

        foreach ($subscriptions as $subscription) {
            $subscription->update([
                'status' => $newStatus,
                'unsubscribed_at' => now(),
                'unsubscribe_reason' => $reason->value,
            ]);
        }
    }

    /**
     * @return Builder<Suppression>
     */
    private function query(string $email, ?int $audienceId)
    {
        return Suppression::query()
            ->where('email', $email)
            ->where(fn ($q) => $q->whereNull('audience_id')->when($audienceId !== null, fn ($q) => $q->orWhere('audience_id', $audienceId)));
    }
}
