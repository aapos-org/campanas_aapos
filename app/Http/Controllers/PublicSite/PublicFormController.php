<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\MergeField;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Forms\FormSubscriber;
use App\Services\Forms\PublicLinks;
use App\Services\Forms\TurnstileVerifier;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Public signup forms: hosted page (/f/{slug}), embeds and double opt-in confirmation.
 * No authentication; the tenant is taken from the form.
 */
class PublicFormController extends Controller
{
    private const int MIN_SECONDS_TO_SUBMIT = 3;

    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly TurnstileVerifier $turnstile,
    ) {}

    public function show(Request $request, string $slug): View
    {
        $form = $this->resolve($slug);

        return view('public.form', $this->viewData($request, $form));
    }

    public function submit(Request $request, string $slug, FormSubscriber $subscriber): RedirectResponse|Response
    {
        $form = $this->resolve($slug);
        $embed = $request->boolean('embed');

        // Honeypot: real people never fill it. Answer as if it worked.
        if (filled($request->input('website'))) {
            return $this->resultRedirect($form, FormSubscriber::RESULT_PENDING, $embed);
        }

        $errors = new MessageBag;

        if (! $this->passesTimeTrap($request->input('_ts'))) {
            $errors->add('form', 'Envío demasiado rápido. Espera un momento y vuelve a intentarlo.');
        }

        if ($form->captcha && $this->turnstile->isConfigured()
            && ! $this->turnstile->verify((string) $request->input('cf-turnstile-response'), $request->ip())) {
            $errors->add('form', 'No pudimos verificar que no eres un robot. Inténtalo de nuevo.');
        }

        if ($errors->isEmpty()) {
            try {
                $result = $this->current->runAs($this->organization($form), fn () => $subscriber->subscribe($form, $request->all(), $request->ip()));

                return $this->resultRedirect($form, $result, $embed);
            } catch (ValidationException $e) {
                $errors->merge($e->errors());
            }
        }

        // Re-render instead of redirecting back: third-party iframes may not keep the session cookie.
        return response()->view('public.form', [
            'formErrors' => $errors,
            'old' => $request->except(['_ts', 'website', 'cf-turnstile-response']),
        ] + $this->viewData($request, $form), 422);
    }

    public function result(Request $request, string $slug): View
    {
        $form = $this->resolve($slug);
        $state = in_array($request->query('estado'), [FormSubscriber::RESULT_SUBSCRIBED, FormSubscriber::RESULT_PENDING, FormSubscriber::RESULT_ALREADY], true)
            ? (string) $request->query('estado')
            : FormSubscriber::RESULT_PENDING;

        return view('public.message', [
            'form' => $form,
            'embed' => $request->boolean('embed'),
            'title' => $form->text('title'),
            'message' => $form->text(match ($state) {
                FormSubscriber::RESULT_SUBSCRIBED => 'success',
                FormSubscriber::RESULT_ALREADY => 'already',
                default => 'pending',
            }),
        ]);
    }

    public function confirm(Request $request, int $subscription, int $form, string $hash, FormSubscriber $subscriber): View
    {
        $formModel = Form::query()->withoutGlobalScope(OrganizationScope::class)
            ->with(['audience' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->whereHas('audience', fn ($q) => $q->withoutGlobalScope(OrganizationScope::class))
            ->findOrFail($form);
        $organization = $this->organization($formModel);

        return $this->current->runAs($organization, function () use ($request, $subscription, $formModel, $hash, $subscriber) {
            $model = Subscription::query()->with('contact')->where('audience_id', $formModel->audience_id)->findOrFail($subscription);

            abort_unless(hash_equals(PublicLinks::emailHash($model->contactOrFail()->email), $hash), 403);

            $subscriber->confirm($model, $formModel, $request->ip());

            return view('public.message', [
                'form' => $formModel,
                'embed' => false,
                'title' => $formModel->text('title'),
                'message' => $formModel->text('confirmed'),
            ]);
        });
    }

    /**
     * JS embed: injects an auto-resizing iframe of the hosted form.
     */
    public function script(string $slug): Response
    {
        $form = $this->resolve($slug);
        $src = route('forms.public.show', ['slug' => $form->slug, 'embed' => 1]);
        $id = 'campanas-form-'.$form->slug;

        $js = <<<'JS'
            (function () {
                var current = document.currentScript;
                var frame = document.createElement('iframe');
                frame.src = %s;
                frame.id = %s;
                frame.title = %s;
                frame.loading = 'lazy';
                frame.style.cssText = 'width:100%%;max-width:560px;border:0;min-height:420px;';
                current.parentNode.insertBefore(frame, current);
                window.addEventListener('message', function (event) {
                    if (event.source === frame.contentWindow && event.data && event.data.type === 'campanas-form-height') {
                        frame.style.height = Math.min(Number(event.data.height) || 420, 4000) + 'px';
                    }
                });
            })();
            JS;

        return response(sprintf($js, json_encode($src), json_encode($id), json_encode($form->text('title'))), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function resolve(string $slug): Form
    {
        // Only the tenant scope is removed: soft-deleted forms/audiences stay hidden.
        return Form::query()->withoutGlobalScope(OrganizationScope::class)
            ->with(['audience' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->whereHas('audience', fn ($q) => $q->withoutGlobalScope(OrganizationScope::class))
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function organization(Form $form): Organization
    {
        return Organization::query()->findOrFail($form->organization_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(Request $request, Form $form): array
    {
        return [
            'form' => $form,
            'embed' => $request->boolean('embed'),
            'timestamp' => Crypt::encryptString((string) time()),
            'turnstileSiteKey' => $form->captcha ? $this->turnstile->siteKey() : null,
            'formErrors' => new MessageBag,
            'old' => [],
            'fieldTypes' => $this->current->runAs($this->organization($form), fn () => MergeField::query()->pluck('type', 'key')->map(fn ($t) => $t->value)->all()),
            'fieldOptions' => $this->current->runAs($this->organization($form), fn () => MergeField::query()->pluck('options', 'key')->all()),
        ];
    }

    /**
     * The hosted form carries an encrypted render time; bots that post instantly are rejected.
     * Plain HTML embeds have no timestamp and rely on the honeypot, rate limit and captcha.
     */
    private function passesTimeTrap(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return true;
        }

        try {
            $renderedAt = (int) Crypt::decryptString($value);
        } catch (Throwable) {
            return false;
        }

        $elapsed = time() - $renderedAt;

        return $elapsed >= self::MIN_SECONDS_TO_SUBMIT && $elapsed <= 86400;
    }

    private function resultRedirect(Form $form, string $result, bool $embed): RedirectResponse
    {
        if ($form->redirect_url !== null && ! $embed) {
            return redirect()->away($form->redirect_url);
        }

        return redirect()->route('forms.public.result', array_filter(['slug' => $form->slug, 'estado' => $result, 'embed' => $embed ? 1 : null]));
    }
}
