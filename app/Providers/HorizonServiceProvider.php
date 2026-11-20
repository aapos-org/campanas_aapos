<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Horizon is only available to users with the "system.monitor" permission (administrators).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->can(Permission::SystemMonitor->value) ?? false);
    }
}
