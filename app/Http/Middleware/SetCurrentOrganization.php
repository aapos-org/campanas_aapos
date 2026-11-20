<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the authenticated user and logs out deactivated users.
 */
class SetCurrentOrganization
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            if (! $user->is_active) {
                auth()->guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'Tu cuenta está desactivada. Contacta a un administrador.');
            }

            $this->current->set($user->organization);
        }

        return $next($request);
    }
}
