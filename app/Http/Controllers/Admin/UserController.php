<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\User;
use App\Notifications\UserInvitation;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $users = User::query()
            ->with('roles')
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $q->whereLike('name', $like, caseSensitive: false)->orWhereLike('email', $like, caseSensitive: false);
            }))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user) => $this->present($user));

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Form', [
            'user' => null,
            'roles' => Role::options(),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                // Unusable random password until the user accepts the invitation.
                'password' => Str::password(40),
                'email_verified_at' => now(),
            ]);
            $user->assignRole($data['role']);

            return $user;
        });

        $user->notify(new UserInvitation((string) $request->user()?->name));

        Audit::record('users.created', 'Usuario creado e invitado', $user, ['role' => $data['role']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Se envió la invitación a {$user->email}."]);

        return to_route('admin.users.index');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/Form', [
            'user' => $this->present($user->load('roles')),
            'roles' => Role::options(),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $newRole = Role::from($data['role']);
        $isActive = (bool) ($data['is_active'] ?? $user->is_active);
        $actor = $request->user();

        if ($actor !== null && $actor->is($user) && (! $isActive || $newRole !== Role::Admin) && $user->isAdmin()) {
            throw ValidationException::withMessages([
                'role' => 'No puedes quitarte el rol de administrador ni desactivar tu propia cuenta.',
            ]);
        }

        if ($user->isAdmin() && ($newRole !== Role::Admin || ! $isActive) && $this->activeAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'role' => 'Debe existir al menos un administrador activo.',
            ]);
        }

        $previousRole = $user->primaryRole();

        DB::transaction(function () use ($user, $data, $newRole, $isActive) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $isActive,
            ]);
            $user->syncRoles([$newRole->value]);
        });

        if ($previousRole !== $newRole) {
            Audit::record('users.role_changed', 'Rol de usuario modificado', $user, [
                'from' => $previousRole?->value,
                'to' => $newRole->value,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Usuario actualizado.']);

        return to_route('admin.users.index');
    }

    public function resendInvitation(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->is_active, 422, 'El usuario está desactivado.');

        $user->notify(new UserInvitation((string) $request->user()?->name));

        Audit::record('users.invitation_resent', 'Invitación reenviada', $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Invitación reenviada a {$user->email}."]);

        return back();
    }

    private function activeAdminCount(): int
    {
        return User::query()->role(Role::Admin->value)->where('is_active', true)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        $role = $user->primaryRole();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'is_active' => $user->is_active,
            'two_factor_enabled' => $user->hasConfirmedTwoFactor(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
