<?php

use App\Http\Middleware\AuthorizeApiAbility;
use App\Http\Middleware\EnsureTwoFactorForAdmins;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Public endpoints that receive posts from other sites (form embeds, RFC 8058 one-click).
        $middleware->validateCsrfTokens(except: ['f/*', 'baja/*', 'webhooks/*']);

        $middleware->web(prepend: [
            SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            SetCurrentOrganization::class,
            EnsureTwoFactorForAdmins::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The tenant must be resolved before route model binding so that scoped
        // models (e.g. /admin/users/{user}) never resolve across organizations.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentOrganization::class,
        );

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'api.ability' => AuthorizeApiAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('api/*') ? response()->json(['message' => __('Unauthenticated.')], 401) : null;
        });

        // API: never reveal model class names or ids in 404 messages.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'No encontrado.'], 404);
            }

            return null;
        });
    })->create();
