<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like RequirePassword, but safe for PUT/DELETE from Inertia forms: when the confirmation
 * expired it sends the user to confirm the password and back to $returnRoute (a GET page)
 * instead of to the original non-GET URL.
 */
class EnsurePasswordConfirmed
{
    public function handle(Request $request, Closure $next, string $returnRoute): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (time() - $confirmedAt > (int) config('auth.password_timeout', 10800)) {
            $request->session()->put('url.intended', route($returnRoute));

            return redirect()->route('password.confirm');
        }

        return $next($request);
    }
}
