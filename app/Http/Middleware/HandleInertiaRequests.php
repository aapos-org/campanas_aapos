<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $organization = app(CurrentOrganization::class)->get();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'app' => [
                'timezone' => config('app.display_timezone'),
                'locale' => 'es-GT',
            ],
            'organization' => $organization !== null ? ['id' => $organization->id, 'name' => $organization->name] : null,
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->primaryRole()?->value,
                    'role_label' => $user->primaryRole()?->label(),
                    'two_factor_enabled' => $user->hasConfirmedTwoFactor(),
                    'must_enable_two_factor' => $user->mustEnableTwoFactor(),
                ] : null,
                'permissions' => $user instanceof User ? $user->getAllPermissions()->pluck('name')->values() : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
