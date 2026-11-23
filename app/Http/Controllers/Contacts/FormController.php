<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\Form;
use App\Models\MergeField;
use App\Models\User;
use App\Services\Forms\TurnstileVerifier;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FormController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('forms/Index', [
            'forms' => Form::query()->with('audience:id,name,double_opt_in')->orderBy('name')->get()->map(fn (Form $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'audience' => $f->audience->name,
                'is_active' => $f->is_active,
                'double_opt_in' => $f->usesDoubleOptIn(),
                'submissions_count' => $f->submissions_count,
                'public_url' => route('forms.public.show', ['slug' => $f->slug]),
            ]),
        ]);
    }

    public function create(Request $request, TurnstileVerifier $turnstile): Response
    {
        return Inertia::render('forms/Edit', $this->editorData(null, $turnstile) + [
            'preselectedAudience' => $request->integer('audience') ?: null,
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $form = Form::create($this->validated($request));

        Audit::record('forms.created', 'Formulario de suscripción creado', $form, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Formulario creado. Copia el código para publicarlo.']);

        return to_route('forms.edit', $form);
    }

    public function edit(Form $form, TurnstileVerifier $turnstile): Response
    {
        return Inertia::render('forms/Edit', $this->editorData($form, $turnstile) + ['preselectedAudience' => null]);
    }

    public function update(Request $request, Form $form, #[CurrentUser] User $user): RedirectResponse
    {
        $form->update($this->validated($request));

        Audit::record('forms.updated', 'Formulario de suscripción modificado', $form, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Formulario guardado.']);

        return to_route('forms.edit', $form);
    }

    public function destroy(Form $form, #[CurrentUser] User $user): RedirectResponse
    {
        $form->delete();

        Audit::record('forms.deleted', 'Formulario de suscripción eliminado', $form, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Formulario eliminado; sus enlaces dejaron de funcionar.']);

        return to_route('forms.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function editorData(?Form $form, TurnstileVerifier $turnstile): array
    {
        $available = [
            ['key' => 'email', 'label' => 'Correo electrónico', 'type' => 'email'],
            ['key' => 'first_name', 'label' => 'Nombre', 'type' => 'text'],
            ['key' => 'last_name', 'label' => 'Apellido', 'type' => 'text'],
            ['key' => 'phone', 'label' => 'Teléfono', 'type' => 'phone'],
            ...MergeField::query()->orderBy('position')->get()
                ->map(fn (MergeField $f) => ['key' => $f->key, 'label' => $f->label, 'type' => $f->type->value])->all(),
        ];

        $embed = null;
        if ($form !== null) {
            $url = route('forms.public.show', ['slug' => $form->slug]);
            $iframe = route('forms.public.show', ['slug' => $form->slug, 'embed' => 1]);
            $embed = [
                'url' => $url,
                'iframe' => sprintf('<iframe src="%s" title="%s" style="width:100%%;max-width:560px;min-height:520px;border:0" loading="lazy"></iframe>', e($iframe), e($form->text('title'))),
                'script' => sprintf('<script src="%s" async></script>', e(route('forms.public.script', ['slug' => $form->slug]))),
                'html' => $this->htmlSnippet($form),
            ];
        }

        return [
            'form' => $form ? [
                'id' => $form->id,
                'name' => $form->name,
                'audience_id' => $form->audience_id,
                'is_active' => $form->is_active,
                'fields' => $form->fields,
                'texts' => array_merge(Form::DEFAULT_TEXTS, $form->texts),
                'style' => array_merge(Form::DEFAULT_STYLE, $form->style),
                'double_opt_in' => $form->double_opt_in,
                'captcha' => $form->captcha,
                'tags' => $form->tags,
                'redirect_url' => $form->redirect_url,
                'submissions_count' => $form->submissions_count,
            ] : null,
            'defaults' => ['texts' => Form::DEFAULT_TEXTS, 'style' => Form::DEFAULT_STYLE],
            'audiences' => Audience::query()->orderBy('name')->get(['id', 'name', 'double_opt_in']),
            'availableFields' => $available,
            'turnstileConfigured' => $turnstile->isConfigured(),
            'embed' => $embed,
        ];
    }

    /**
     * Plain HTML embed (no JavaScript). Uses the site's own styles.
     */
    private function htmlSnippet(Form $form): string
    {
        $lines = [sprintf('<form method="post" action="%s">', e(route('forms.public.submit', ['slug' => $form->slug])))];

        foreach ($form->fields as $field) {
            $type = $field['key'] === 'email' ? 'email' : ($field['key'] === 'phone' ? 'tel' : 'text');
            $lines[] = sprintf('  <label>%s<br><input type="%s" name="%s"%s></label><br>', e($field['label']), $type, e($field['key']), ($field['required'] || $field['key'] === 'email') ? ' required' : '');
        }

        $lines[] = '  <div style="position:absolute;left:-10000px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>';
        $lines[] = sprintf('  <button type="submit">%s</button>', e($form->text('button')));
        $lines[] = '</form>';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $fieldKeys = ['email', 'first_name', 'last_name', 'phone', ...MergeField::query()->pluck('key')->all()];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'audience_id' => ['required', 'integer', Rule::exists('audiences', 'id')->where('organization_id', $request->user()?->organization_id)->whereNull('deleted_at')],
            'is_active' => ['boolean'],
            'fields' => ['required', 'array', 'min:1', 'max:30'],
            'fields.*.key' => ['required', 'string', Rule::in($fieldKeys), 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:100'],
            'fields.*.required' => ['boolean'],
            'texts' => ['array'],
            'texts.*' => ['nullable', 'string', 'max:1000'],
            'style' => ['array'],
            'style.*' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'double_opt_in' => ['nullable', 'boolean'],
            'captcha' => ['boolean'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:100'],
            'redirect_url' => ['nullable', 'url:https', 'max:500'],
        ], [
            'redirect_url.url' => 'La página de destino debe ser una dirección https://.',
            'style.*.regex' => 'Los colores deben tener el formato #RRGGBB.',
        ], ['name' => 'nombre', 'audience_id' => 'audiencia', 'fields' => 'campos', 'redirect_url' => 'página de destino']);

        /** @var list<array{key: string, label: string, required?: bool}> $rawFields */
        $rawFields = $data['fields'];
        $fields = collect($rawFields)->map(fn (array $f) => [
            'key' => (string) $f['key'],
            'label' => trim((string) $f['label']),
            'required' => $f['key'] === 'email' || (bool) ($f['required'] ?? false),
        ]);

        // The email is mandatory and always first.
        if (! $fields->contains('key', 'email')) {
            $fields->prepend(['key' => 'email', 'label' => 'Correo electrónico', 'required' => true]);
        }

        return [
            'name' => $data['name'],
            'audience_id' => (int) $data['audience_id'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'fields' => $fields->sortBy(fn ($f) => $f['key'] === 'email' ? 0 : 1)->values()->all(),
            'texts' => array_filter(array_intersect_key($data['texts'] ?? [], Form::DEFAULT_TEXTS), fn ($v) => filled($v)),
            'style' => array_filter(array_intersect_key($data['style'] ?? [], Form::DEFAULT_STYLE), fn ($v) => filled($v)),
            'double_opt_in' => $data['double_opt_in'] ?? null,
            'captcha' => (bool) ($data['captcha'] ?? false),
            'tags' => array_values(array_filter(array_map('trim', $data['tags'] ?? []))),
            'redirect_url' => $data['redirect_url'] ?? null,
        ];
    }
}
