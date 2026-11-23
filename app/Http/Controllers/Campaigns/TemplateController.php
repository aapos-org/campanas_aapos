<?php

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\User;
use App\Services\Campaigns\ContentPreparer;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TemplateController extends Controller
{
    /** Base designs of our own (resources/email-templates/*.mjml). */
    public const array LIBRARY = [
        'boletin' => ['name' => 'Boletín', 'description' => 'Encabezado, noticia principal con imagen y dos columnas.'],
        'anuncio' => ['name' => 'Anuncio', 'description' => 'Un mensaje destacado con un botón grande.'],
        'texto' => ['name' => 'Carta', 'description' => 'Texto personal, como una carta. Llega mejor a la bandeja de entrada.'],
        'blanco' => ['name' => 'En blanco', 'description' => 'Empieza desde cero.'],
    ];

    public function __construct(private readonly ContentPreparer $preparer) {}

    public function index(): Response
    {
        return Inertia::render('templates/Index', [
            'templates' => Template::query()->latest('updated_at')->get()->map(fn (Template $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'editor' => $t->editor,
                'source' => $t->source,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ]),
            'library' => collect(self::LIBRARY)->map(fn ($t, $key) => $t + ['key' => $key])->values(),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'base' => ['required', Rule::in([...array_keys(self::LIBRARY), 'html'])],
        ]);

        $template = Template::create([
            'name' => $data['name'],
            'editor' => $data['base'] === 'html' ? 'html' : 'visual',
            'mjml' => $data['base'] === 'html' ? null : self::baseMjml($data['base']),
            'html' => $data['base'] === 'html' ? "<html>\n<body>\n  <h1>Hola {{ nombre | \"amigo\" }}</h1>\n  <p>Tu contenido aquí.</p>\n</body>\n</html>" : null,
            'created_by' => $user->id,
        ]);

        Audit::record('templates.created', 'Plantilla creada', $template, ['base' => $data['base']], $user);

        return to_route('templates.edit', $template);
    }

    public function edit(Request $request, Template $template): Response|SymfonyResponse
    {
        // The editor needs its own CSP (inline styles), which only applies on a full page load.
        if ($request->header('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        return Inertia::render('templates/Edit', [
            'template' => $template->only(['id', 'name', 'description', 'editor', 'mjml', 'design', 'html', 'source']),
            'mergeTags' => $this->mergeTags(),
        ]);
    }

    public function update(Request $request, Template $template, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'mjml' => ['nullable', 'string', 'max:2000000'],
            'design' => ['nullable', 'array'],
            'html' => ['nullable', 'string', 'max:2000000'],
        ]);

        $template->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'mjml' => $template->editor === 'visual' ? ($data['mjml'] ?? $template->mjml) : null,
            'design' => $template->editor === 'visual' ? ($data['design'] ?? $template->design) : null,
            'html' => $this->preparer->prepare((string) ($data['html'] ?? '')),
        ]);

        Audit::record('templates.updated', 'Plantilla modificada', $template, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plantilla guardada.']);

        return back();
    }

    public function duplicate(Template $template, #[CurrentUser] User $user): RedirectResponse
    {
        $copy = $template->replicate(['mailchimp_id']);
        $copy->name = mb_substr($template->name.' (copia)', 0, 150);
        $copy->source = 'own';
        $copy->created_by = $user->id;
        $copy->save();

        return to_route('templates.edit', $copy);
    }

    public function destroy(Template $template, #[CurrentUser] User $user): RedirectResponse
    {
        $template->delete();

        Audit::record('templates.deleted', 'Plantilla eliminada', $template, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plantilla eliminada.']);

        return to_route('templates.index');
    }

    /**
     * Saved HTML for the sandboxed preview iframe (no scripts allowed).
     */
    public function preview(Template $template): HttpResponse
    {
        return PreviewResponse::make((string) $template->html);
    }

    public static function baseMjml(string $key): string
    {
        $path = resource_path("email-templates/{$key}.mjml");

        return is_file($path)
            ? (string) file_get_contents($path)
            : '<mjml><mj-body><mj-section><mj-column><mj-text>Hola {{ nombre | "amigo" }}</mj-text></mj-column></mj-section></mj-body></mjml>';
    }

    /**
     * @return list<array{tag: string, label: string}>
     */
    private function mergeTags(): array
    {
        return MergeTags::all();
    }
}
