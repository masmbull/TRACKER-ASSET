<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGates();
    }

    /**
     * Application wide authorization rules (PRD §13, §50).
     */
    protected function registerGates(): void
    {
        // Deactivated accounts can never act. IT Admin gets full access.
        Gate::before(function (User $user, string $ability) {
            if (! $user->is_active) {
                return false;
            }

            return $user->isAdmin() ? true : null;
        });

        Gate::define('import-assets', fn (User $user) => $user->hasRole('admin', 'support'));

        Gate::define('export-assets', fn (User $user) => $user->hasRole('admin', 'support', 'technician'));

        Gate::define('manage-master-data', fn (User $user) => $user->hasRole('admin', 'support'));

        Gate::define('view-activity-log', fn (User $user) => $user->hasRole('admin', 'support', 'technician'));
    }
}
