<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Api\ApiAbility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('api.ability:contacts:write'). The token must have the ability and its
 * owner must still be active and have the matching role permission.
 */
class AuthorizeApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        $required = ApiAbility::from($ability);

        if (! $user instanceof User || ! $user->is_active) {
            return response()->json(['message' => 'El usuario dueño del token está desactivado.'], 403);
        }

        if (! $user->tokenCan($required->value)) {
            return response()->json(['message' => "El token no tiene el permiso «{$required->value}»."], 403);
        }

        if (! $user->can($required->permission()->value)) {
            return response()->json(['message' => 'El rol del dueño del token ya no permite esta operación.'], 403);
        }

        return $next($request);
    }
}
