<?php

namespace App\Jobs;

use App\Enums\ContactStatus;
use App\Mail\Transport\OutgoingEmail;
use App\Mail\Transport\TransportException;
use App\Models\Form;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Forms\PublicLinks;
use App\Services\Sending\SystemMailer;
use App\Support\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Sends the double opt-in confirmation email through the organization's default connection.
 */
class SendOptInConfirmation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public readonly int $requestedAt;

    public function __construct(public readonly int $subscriptionId, public readonly int $formId)
    {
        $this->requestedAt = time();
    }

    public function handle(CurrentOrganization $current, SystemMailer $mailer, PublicLinks $links): void
    {
        $subscription = Subscription::query()->withoutGlobalScopes()->find($this->subscriptionId);

        if ($subscription === null || $subscription->status === ContactStatus::Subscribed) {
            return;
        }

        $organization = Organization::query()->findOrFail($subscription->organization_id);

        $current->runAs($organization, function () use ($subscription, $mailer, $links, $organization) {
            $form = Form::query()->with('audience')->findOrFail($this->formId);
            $contact = $subscription->contactOrFail();
            $connection = $mailer->connection();

            if ($connection === null) {
                Audit::record('forms.optin_not_sent', 'No hay conexión de envío para la confirmación de suscripción', $contact, ['form_id' => $form->id]);

                throw new RuntimeException('No hay ninguna conexión de envío configurada para enviar la confirmación.');
            }

            $url = $links->confirmation($subscription, $form, $contact);
            $html = view('emails.optin-confirmation', [
                'form' => $form,
                'organization' => $organization,
                'url' => $url,
                'days' => PublicLinks::CONFIRMATION_DAYS,
            ])->render();

            try {
                $mailer->send(new OutgoingEmail(
                    fromEmail: $form->audience->default_from_email ?: $connection->from_email,
                    fromName: $form->audience->default_from_name ?: $connection->from_name,
                    to: $contact->email,
                    toName: $contact->full_name ?: null,
                    subject: $form->text('confirmation_subject'),
                    html: $html,
                    replyTo: $connection->reply_to,
                    headers: ['X-Campanas-Type' => 'optin'],
                    reference: "optin-{$subscription->id}-{$this->requestedAt}",
                ), $connection);
            } catch (TransportException $e) {
                if ($e->retryable) {
                    throw $e;
                }

                Audit::record('forms.optin_failed', 'Falló el envío de la confirmación de suscripción', $contact, ['error' => $e->getMessage()]);

                $this->fail($e);
            }
        });
    }
}
