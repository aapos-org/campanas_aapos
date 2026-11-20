<?php

namespace App\Http\Controllers\PublicSite;

use App\Enums\ContactStatus;
use App\Enums\SuppressionReason;
use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Contacts\SubscriptionManager;
use App\Services\Contacts\SuppressionService;
use App\Services\Forms\PublicLinks;
use App\Support\Audit;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Public preferences page and unsubscribe (signed links from every email).
 *
 * GET never unsubscribes (mail scanners follow links); the RFC 8058 one-click
 * unsubscribe is a POST (List-Unsubscribe-Post: List-Unsubscribe=One-Click).
 */
class PreferencesController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly SubscriptionManager $subscriptions,
        private readonly SuppressionService $suppressions,
    ) {}

    public function show(Request $request, int $contact, string $hash): View
    {
        return $this->asContact($contact, $hash, fn (Contact $model) => view('public.preferences', [
            'contact' => $model,
            'organization' => Organization::query()->findOrFail($model->organization_id),
            'subscriptions' => Subscription::query()->with('audience')->where('contact_id', $model->id)->whereHas('audience')->get(),
            'actionUrl' => URL::signedRoute('preferences.update', ['contact' => $model->id, 'hash' => $hash]),
            'done' => $request->query('hecho'),
        ]));
    }

    public function update(Request $request, int $contact, string $hash): RedirectResponse
    {
        $data = $request->validate([
            'audience' => ['nullable', 'integer'],
            'all' => ['nullable', 'boolean'],
        ]);

        return $this->asContact($contact, $hash, function (Contact $model) use ($data, $hash) {
            if (! empty($data['all'])) {
                $this->suppressions->suppress($model->email, SuppressionReason::Unsubscribed, null, 'preferencias');
                Audit::record('contacts.unsubscribed_all', 'La persona se dio de baja de todas las audiencias', $model, [], organizationId: $model->organization_id);
            } elseif (! empty($data['audience'])) {
                $audience = Audience::query()->findOrFail((int) $data['audience']);
                $this->applyUnsubscribe($model, $audience, 'preferencias');
            }

            return redirect()->to(URL::signedRoute('preferences.show', ['contact' => $model->id, 'hash' => $hash, 'hecho' => 1]));
        });
    }

    /** Confirmation page of the one-click link (does not unsubscribe by itself). */
    public function unsubscribeShow(int $contact, int $audience, string $hash): View
    {
        return $this->asContact($contact, $hash, function (Contact $model) use ($audience, $hash) {
            $audienceModel = Audience::query()->findOrFail($audience);

            return view('public.unsubscribe', [
                'contact' => $model,
                'audience' => $audienceModel,
                'organization' => Organization::query()->findOrFail($model->organization_id),
                'actionUrl' => URL::signedRoute('unsubscribe.store', ['contact' => $model->id, 'audience' => $audienceModel->id, 'hash' => $hash]),
                'preferencesUrl' => URL::signedRoute('preferences.show', ['contact' => $model->id, 'hash' => $hash]),
                'done' => Subscription::query()->where('contact_id', $model->id)->where('audience_id', $audienceModel->id)->value('status') === ContactStatus::Unsubscribed,
            ]);
        });
    }

    /** RFC 8058 one-click (mail clients POST here) and the button of the confirmation page. */
    public function unsubscribe(Request $request, int $contact, int $audience, string $hash): Response|View
    {
        return $this->asContact($contact, $hash, function (Contact $model) use ($request, $audience, $hash) {
            $audienceModel = Audience::query()->findOrFail($audience);
            $this->applyUnsubscribe($model, $audienceModel, $request->has('List-Unsubscribe') ? 'one-click' : 'enlace');

            if ($request->has('List-Unsubscribe')) {
                return response('', 200);
            }

            return $this->unsubscribeShow($model->id, $audienceModel->id, $hash);
        });
    }

    private function applyUnsubscribe(Contact $contact, Audience $audience, string $source): void
    {
        $subscription = Subscription::query()->where('contact_id', $contact->id)->where('audience_id', $audience->id)->first();

        if ($subscription !== null && ! $subscription->status->isTerminal()) {
            $this->subscriptions->setStatus($contact, $audience, ContactStatus::Unsubscribed);
        } else {
            $this->suppressions->suppress($contact->email, SuppressionReason::Unsubscribed, $audience, $source);
        }

        Audit::record('contacts.unsubscribed', 'La persona se dio de baja', $contact, ['audience_id' => $audience->id, 'source' => $source], organizationId: $contact->organization_id);
    }

    /**
     * @template T
     *
     * @param  \Closure(Contact): T  $callback
     * @return T
     */
    private function asContact(int $contactId, string $hash, \Closure $callback): mixed
    {
        $contact = Contact::query()->withoutGlobalScope(OrganizationScope::class)->findOrFail($contactId);

        abort_unless(hash_equals(PublicLinks::emailHash($contact->email), $hash), 403);

        return $this->current->runAs(Organization::query()->findOrFail($contact->organization_id), fn () => $callback($contact));
    }
}
