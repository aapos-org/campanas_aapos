<?php

namespace App\Providers;

use App\Services\Limits\ContactLimit;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
        $this->app->scoped(ContactLimit::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();

        // Public signup forms: per IP, and per form + email (avoids flooding someone's inbox).
        RateLimiter::for('public-forms', fn (Request $request) => [
            Limit::perMinute(10)->by('form-ip:'.$request->ip()),
            Limit::perHour(5)->by('form-email:'.$request->route('slug').'|'.mb_strtolower(trim((string) $request->input('email')))),
        ]);
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
