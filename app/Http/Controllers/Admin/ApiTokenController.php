<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiAbility;
use App\Support\Audit;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tokens for the public API. The plain token is shown once, right after creating it.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $userIds = User::query()->pluck('name', 'id');

        return Inertia::render('admin/api/Index', [
            'tokens' => PersonalAccessToken::query()
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $userIds->keys())
                ->latest('id')->get()
                ->map(fn (PersonalAccessToken $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'owner' => $userIds[$t->tokenable_id] ?? '—',
                    'abilities' => $t->abilities,
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                    'expires_at' => $t->expires_at?->toIso8601String(),
                    'created_at' => $t->created_at?->toIso8601String(),
                ]),
            'abilities' => ApiAbility::options(),
            'newToken' => $request->session()->get('api_token'),
            'baseUrl' => url('/api/v1'),
            'docsUrl' => url('/docs/api'),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::enum(ApiAbility::class)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ], [], ['name' => 'nombre', 'abilities' => 'permisos', 'expires_in_days' => 'vigencia']);

        // A token can never do more than its owner's role allows.
        $abilities = array_values(array_filter(
            array_unique(array_map('strval', (array) $data['abilities'])),
            fn (string $a) => $user->can(ApiAbility::from($a)->permission()->value),
        ));

        $token = $user->createToken(
            $data['name'],
            $abilities,
            isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
        );

        Audit::record('api.token_created', 'Token de API creado', null, ['name' => $data['name'], 'abilities' => $abilities, 'token_id' => $token->accessToken->id], $user);

        return to_route('admin.api.index')->with('api_token', $token->plainTextToken);
    }

    public function destroy(PersonalAccessToken $token, #[CurrentUser] User $user): RedirectResponse
    {
        // Only tokens of users in this organization (the global scope applies to User).
        abort_unless($token->tokenable_type === (new User)->getMorphClass() && User::query()->whereKey($token->tokenable_id)->exists(), 404);

        $token->delete();
        Audit::record('api.token_revoked', 'Token de API revocado', null, ['name' => $token->name, 'token_id' => $token->id], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Token revocado: deja de funcionar de inmediato.']);

        return to_route('admin.api.index');
    }
}
