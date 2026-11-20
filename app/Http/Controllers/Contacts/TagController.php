<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('tags/Index', [
            'tags' => Tag::query()->withCount('contacts')->orderBy('name')->get()
                ->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name, 'contacts_count' => $t->getAttribute('contacts_count')]),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $tag = Tag::create($this->validated($request, null));

        Audit::record('tags.created', 'Etiqueta creada', $tag, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Etiqueta «{$tag->name}» creada."]);

        return back();
    }

    public function update(Request $request, Tag $tag, #[CurrentUser] User $user): RedirectResponse
    {
        $tag->update($this->validated($request, $tag));

        Audit::record('tags.updated', 'Etiqueta renombrada', $tag, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Etiqueta actualizada.']);

        return back();
    }

    public function destroy(Tag $tag, #[CurrentUser] User $user): RedirectResponse
    {
        $tag->delete();

        Audit::record('tags.deleted', 'Etiqueta eliminada', $tag, ['name' => $tag->name], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Etiqueta eliminada.']);

        return back();
    }

    /**
     * @return array{name: string}
     */
    private function validated(Request $request, ?Tag $tag): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        /** @var array{name: string} */
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('tags', 'name')
                    ->where('organization_id', $request->user()?->organization_id)
                    ->ignore($tag?->id),
            ],
        ], [], ['name' => 'nombre']);
    }
}
