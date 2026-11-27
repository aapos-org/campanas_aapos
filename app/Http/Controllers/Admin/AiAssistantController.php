<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAssistantSetting;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiReviewQuota;
use App\Support\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Asistente de IA: model, API key (encrypted; only the last 4 characters
 * ever leave the server), behaviour, Smart Brevity, tone guide and usage caps.
 */
class AiAssistantController extends Controller
{
    /** Settings audited on change (never the key). */
    private const array AUDITED = ['enabled', 'model', 'live_review_enabled', 'manual_review_enabled', 'debounce_ms', 'min_chars_change', 'categories', 'max_findings', 'smart_brevity_enabled', 'smart_brevity_max_words', 'daily_limit', 'monthly_limit', 'timeout_seconds'];

    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly AiProvider $provider,
        private readonly AiReviewQuota $quota,
    ) {}

    public function edit(): Response
    {
        $settings = AiAssistantSetting::current();

        return Inertia::render('admin/ai/Edit', [
            'settings' => [
                'provider' => $settings->provider,
                'model' => $settings->model,
                'has_key' => $settings->hasKey(),
                'masked_key' => $settings->maskedKey(),
                'enabled' => $settings->enabled,
                'live_review_enabled' => $settings->live_review_enabled,
                'manual_review_enabled' => $settings->manual_review_enabled,
                'debounce_ms' => $settings->debounce_ms,
                'min_chars_change' => $settings->min_chars_change,
                'categories' => $settings->categories,
                'max_findings' => $settings->max_findings,
                'smart_brevity_enabled' => $settings->smart_brevity_enabled,
                'smart_brevity_max_words' => $settings->smart_brevity_max_words,
                'tone_guidelines' => $settings->tone_guidelines,
                'daily_limit' => $settings->daily_limit,
                'monthly_limit' => $settings->monthly_limit,
                'timeout_seconds' => $settings->timeout_seconds,
                'status' => $settings->status,
                'last_verified_at' => $settings->last_verified_at?->toIso8601String(),
                'last_error' => $settings->last_error,
            ],
            'categoryOptions' => collect(AiAssistantSetting::CATEGORIES)->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'suggestedModels' => config('services.anthropic.suggested_models'),
            'usage' => $this->quota->usage($settings, $this->current->getOrFail()),
        ]);
    }

    public function update(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'model' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9.\-]*$/'],
            'live_review_enabled' => ['boolean'],
            'manual_review_enabled' => ['boolean'],
            'debounce_ms' => ['required', 'integer', 'between:800,5000'],
            'min_chars_change' => ['required', 'integer', 'between:1,500'],
            'categories' => ['array'],
            'categories.*' => [Rule::in(array_keys(AiAssistantSetting::CATEGORIES))],
            'max_findings' => ['required', 'integer', 'between:1,20'],
            'smart_brevity_enabled' => ['boolean'],
            'smart_brevity_max_words' => ['required', 'integer', 'between:50,2000'],
            'tone_guidelines' => ['nullable', 'string', 'max:2000'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'monthly_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'timeout_seconds' => ['required', 'integer', 'between:5,120'],
        ], [], [
            'model' => 'modelo', 'debounce_ms' => 'pausa de la revisión en vivo', 'min_chars_change' => 'cambio mínimo',
            'max_findings' => 'máximo de hallazgos', 'smart_brevity_max_words' => 'máximo de palabras',
            'tone_guidelines' => 'guía de tono', 'daily_limit' => 'límite diario', 'monthly_limit' => 'límite mensual',
            'timeout_seconds' => 'tiempo de espera',
        ]);

        $settings = AiAssistantSetting::current();
        $before = $settings->exists ? $settings->only(self::AUDITED) : [];
        $modelChanged = $settings->model !== $data['model'];

        $settings->fill($data + ['categories' => array_values($data['categories'] ?? [])]);

        if ($modelChanged && $settings->hasKey()) {
            $settings->status = 'unverified';
        }

        $settings->save();

        Audit::record('ai.settings_updated', 'Configuración del asistente de IA modificada', $settings, [
            'before' => $before,
            'after' => $settings->only(self::AUDITED),
            'tone_guidelines_changed' => $settings->wasChanged('tone_guidelines'),
        ], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configuración del asistente guardada.']);

        return back();
    }

    /**
     * Saves or replaces the key and verifies it right away. An empty field never deletes it.
     */
    public function storeKey(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:20', 'max:400', 'regex:/^\S+$/'],
        ], ['api_key.regex' => 'La API key no puede tener espacios.'], ['api_key' => 'API key']);

        $settings = AiAssistantSetting::current();
        $replaced = $settings->hasKey();
        $settings->api_key = trim($data['api_key']);
        $settings->status = 'unverified';
        $settings->last_error = null;
        $settings->save();

        Audit::record($replaced ? 'ai.key_replaced' : 'ai.key_added', $replaced ? 'API key del asistente reemplazada' : 'API key del asistente agregada', $settings, [
            'key_last4' => $settings->keyHint(),
        ], $user);

        $this->runVerification($settings, $user);

        return back();
    }

    public function destroyKey(#[CurrentUser] User $user): RedirectResponse
    {
        $settings = AiAssistantSetting::current();

        if ($settings->hasKey()) {
            $hint = $settings->keyHint();
            $settings->forceFill(['api_key' => null, 'status' => 'unverified', 'last_error' => null, 'last_verified_at' => null])->save();
            Audit::record('ai.key_deleted', 'API key del asistente eliminada', $settings, ['key_last4' => $hint], $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'API key eliminada. El asistente queda sin revisión con IA.']);

        return back();
    }

    public function verify(#[CurrentUser] User $user): RedirectResponse
    {
        $settings = AiAssistantSetting::current();

        if (! $settings->hasKey()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Primero guarda una API key.']);

            return back();
        }

        $this->runVerification($settings, $user);

        return back();
    }

    private function runVerification(AiAssistantSetting $settings, User $user): void
    {
        $result = $this->provider->verify($settings);

        $settings->forceFill($result->ok
            ? ['status' => 'active', 'last_verified_at' => now(), 'last_error' => null]
            : ['status' => 'error', 'last_error' => $result->error])->save();

        Audit::record('ai.verified', 'Verificación de la conexión del asistente de IA', $settings, [
            'ok' => $result->ok,
            'error' => $result->error,
            'model' => $settings->model,
        ], $user);

        Inertia::flash('toast', $result->ok
            ? ['type' => 'success', 'message' => 'Conexión verificada con el modelo '.$settings->model.'.']
            : ['type' => 'error', 'message' => (string) $result->error]);
    }
}
