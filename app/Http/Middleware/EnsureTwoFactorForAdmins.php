<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administrators must enable two-factor authentication before using the panel.
 * They can still reach the security settings (and the 2FA endpoints) and log out.
 */
class EnsureTwoFactorForAdmins
{
    /** Route names that remain reachable without 2FA. */
    private const array ALLOWED_ROUTES = [
        'security.edit',
        'logout',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'user-password.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->mustEnableTwoFactor()) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => 'Como administrador debes activar la autenticación en dos pasos antes de continuar.',
        ]);

        return redirect()->route('security.edit');
    }
}
