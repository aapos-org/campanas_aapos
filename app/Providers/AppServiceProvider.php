<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AnthropicProvider;
use App\Services\Limits\ContactLimit;
use App\Services\Webhooks\OutgoingWebhooks;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
        $this->app->scoped(ContactLimit::class);
        $this->app->scoped(OutgoingWebhooks::class);
        $this->app->bind(AiProvider::class, AnthropicProvider::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();

        // Public signup forms: per IP, and per form + email (avoids flooding someone's inbox).
        RateLimiter::for('public-forms', fn (Request $request) => [
            Limit::perMinute(10)->by('form-ip:'.$request->ip()),
            Limit::perHour(5)->by('form-email:'.$request->route('slug').'|'.mb_strtolower(trim((string) $request->input('email')))),
        ]);

        // OpenAPI documentation (/docs/api): administrators with API access.
        Gate::define('viewApiDocs', fn (User $user) => $user->can(Permission::ApiManage->value));

        // Public API: per token (falls back to IP for unauthenticated calls).
        RateLimiter::for('api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            $key = $token !== null && property_exists($token, 'id') ? 'token:'.$token->id : 'ip:'.$request->ip();

            return Limit::perMinute((int) config('services.api.rate_per_minute', 120))->by($key)
                ->response(fn (Request $r, array $headers) => response()->json(['message' => 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.'], 429, $headers));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
