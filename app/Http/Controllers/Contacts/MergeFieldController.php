<?php

namespace App\Http\Controllers\Contacts;

use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Models\MergeField;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MergeFieldController extends Controller
{
    /** Keys reserved for the built-in contact columns. */
    private const array RESERVED = ['email', 'first_name', 'last_name', 'phone', 'tags', 'status', 'language'];

    public function index(): Response
    {
        return Inertia::render('fields/Index', [
            'fields' => MergeField::query()->orderBy('position')->orderBy('label')->get()->map(fn (MergeField $f) => [
                'id' => $f->id,
                'key' => $f->key,
                'label' => $f->label,
                'type' => $f->type->value,
                'type_label' => $f->type->label(),
                'options' => $f->options,
                'required' => $f->required,
                'tag' => '{{ '.$f->key.' }}',
            ]),
            'types' => FieldType::options(),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $this->validated($request, null);
        $key = Str::snake(Str::ascii($data['label']));

        if (in_array($key, self::RESERVED, true) || MergeField::query()->where('key', $key)->exists()) {
            $key .= '_'.Str::lower(Str::random(4));
        }

        $field = MergeField::create($data + ['key' => mb_substr($key, 0, 50), 'position' => (int) MergeField::query()->max('position') + 1]);

        Audit::record('fields.created', 'Campo personalizado creado', $field, ['key' => $field->key, 'type' => $field->type->value], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Campo «{$field->label}» creado."]);

        return back();
    }

    public function update(Request $request, MergeField $field, #[CurrentUser] User $user): RedirectResponse
    {
        // The type and key cannot change: existing values were normalized for that type.
        $field->update($this->validated($request, $field));

        Audit::record('fields.updated', 'Campo personalizado modificado', $field, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Campo actualizado.']);

        return back();
    }

    public function destroy(MergeField $field, #[CurrentUser] User $user): RedirectResponse
    {
        $field->delete();

        Audit::record('fields.deleted', 'Campo personalizado eliminado', $field, ['key' => $field->key], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Campo eliminado. Los valores guardados en los contactos se conservan.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MergeField $field): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'type' => $field ? ['prohibited'] : ['required', Rule::enum(FieldType::class)],
            'options' => ['array', 'max:200'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'required' => ['boolean'],
        ], [], ['label' => 'nombre', 'type' => 'tipo', 'options' => 'opciones']);

        $type = $field->type ?? FieldType::from($data['type']);

        if ($type === FieldType::Select && count(array_filter($data['options'] ?? [])) === 0) {
            throw ValidationException::withMessages(['options' => 'Agrega al menos una opción a la lista.']);
        }

        return array_filter([
            'label' => trim($data['label']),
            'type' => $field ? null : $type,
            'options' => $type === FieldType::Select ? array_values(array_filter(array_map('trim', $data['options'] ?? []))) : [],
            'required' => (bool) ($data['required'] ?? false),
        ], fn ($v) => $v !== null);
    }
}
