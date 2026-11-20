<?php

namespace App\Http\Controllers\Contacts;

use App\Enums\ContactStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AudienceController extends Controller
{
    public function index(): Response
    {
        $counts = Subscription::query()
            ->selectRaw('audience_id, status, count(*) as total')
            ->groupBy('audience_id', 'status')
            ->get()
            ->groupBy('audience_id');

        $audiences = Audience::query()->orderBy('name')->get()->map(function (Audience $audience) use ($counts) {
            $byStatus = collect($counts->get($audience->id, []))->mapWithKeys(fn ($row) => [$row->status->value => (int) $row->getAttribute('total')]);

            return [
                'id' => $audience->id,
                'name' => $audience->name,
                'description' => $audience->description,
                'double_opt_in' => $audience->double_opt_in,
                'counts' => $byStatus,
                'total' => $byStatus->sum(),
                'created_at' => $audience->created_at?->toIso8601String(),
            ];
        });

        return Inertia::render('audiences/Index', ['audiences' => $audiences]);
    }

    public function create(): Response
    {
        return Inertia::render('audiences/Form', ['audience' => null]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $audience = Audience::create($this->validated($request));

        Audit::record('audiences.created', 'Audiencia creada', $audience, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Audiencia creada.']);

        return to_route('audiences.show', $audience);
    }

    public function show(Request $request, Audience $audience): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'tag' => ['nullable', 'integer'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $status = ContactStatus::tryFrom((string) ($filters['status'] ?? ''));

        $subscriptions = Subscription::query()
            ->where('audience_id', $audience->id)
            ->with(['contact' => fn ($q) => $q->with('tags:id,name')])
            ->whereHas('contact')
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
                $q->whereHas('contact', fn (Builder $c) => $c->where(fn (Builder $w) => $w
                    ->where('email', 'like', $like)
                    ->orWhereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like])));
            })
            ->when($filters['tag'] ?? null, fn (Builder $q, $tagId) => $q->whereExists(fn ($e) => $e
                ->from('contact_tag')
                ->whereColumn('contact_tag.contact_id', 'audience_contact.contact_id')
                ->where('contact_tag.tag_id', $tagId)))
            ->orderByDesc('audience_contact.id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Subscription $s) => [
                'subscription_id' => $s->id,
                'contact_id' => $s->contact_id,
                'email' => $s->contactOrFail()->email,
                'name' => $s->contactOrFail()->full_name,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'subscribed_at' => $s->subscribed_at?->toIso8601String(),
                'tags' => $s->contactOrFail()->tags->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name])->values(),
            ]);

        $counts = Subscription::query()->where('audience_id', $audience->id)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('audiences/Show', [
            'audience' => [
                'id' => $audience->id,
                'name' => $audience->name,
                'description' => $audience->description,
                'double_opt_in' => $audience->double_opt_in,
            ],
            'counts' => $counts,
            'subscriptions' => $subscriptions,
            'filters' => [
                'search' => $search,
                'status' => $status->value ?? '',
                'tag' => isset($filters['tag']) ? (int) $filters['tag'] : null,
            ],
            'statuses' => ContactStatus::options(),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()?->can(Permission::ContactsManage->value) ?? false,
        ]);
    }

    public function edit(Audience $audience): Response
    {
        return Inertia::render('audiences/Form', ['audience' => $audience->only([
            'id', 'name', 'description', 'default_from_name', 'default_from_email', 'permission_reminder', 'double_opt_in',
        ])]);
    }

    public function update(Request $request, Audience $audience, #[CurrentUser] User $user): RedirectResponse
    {
        $audience->update($this->validated($request));

        Audit::record('audiences.updated', 'Audiencia modificada', $audience, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Audiencia actualizada.']);

        return to_route('audiences.show', $audience);
    }

    public function destroy(Audience $audience, #[CurrentUser] User $user): RedirectResponse
    {
        $audience->delete();

        Audit::record('audiences.deleted', 'Audiencia eliminada', $audience, [], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Audiencia eliminada. Los contactos y sus supresiones se conservan.']);

        return to_route('audiences.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_from_name' => ['nullable', 'string', 'max:100'],
            'default_from_email' => ['nullable', 'email:rfc', 'max:255'],
            'permission_reminder' => ['nullable', 'string', 'max:500'],
            'double_opt_in' => ['boolean'],
        ], [], [
            'name' => 'nombre',
            'description' => 'descripción',
            'default_from_name' => 'nombre del remitente',
            'default_from_email' => 'correo del remitente',
            'permission_reminder' => 'recordatorio de permiso',
        ]);
    }
}
