<?php

namespace App\Providers;

use App\Models\Asset;
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
        Gate::before(function (User $user, string $ability, array $arguments) {
            if (! $user->is_active) {
                return false;
            }

            if ($user->isAdmin()) {
                return true;
            }

            $permission = $this->permissionForAbility($ability, $arguments);

            return $permission === null ? null : $user->permissionOverride($permission);
        });

        Gate::define('import-assets', fn (User $user) => $user->hasRole('admin', 'support'));

        Gate::define('export-assets', fn (User $user) => $user->hasRole('admin', 'support', 'technician'));

        Gate::define('manage-master-data', fn (User $user) => $user->hasRole('admin', 'support'));

        Gate::define('manage-users', fn (User $user) => $user->isAdmin());

        Gate::define('manage-agents', fn (User $user) => $user->isAdmin());

        Gate::define('view-activity-log', fn (User $user) => $user->hasRole('admin', 'support', 'technician'));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function permissionForAbility(string $ability, array $arguments): ?string
    {
        $namedPermissions = [
            'import-assets' => 'assets.import',
            'export-assets' => 'assets.export',
            'manage-master-data' => 'master.manage',
            'manage-users' => 'users.manage',
            'manage-agents' => 'agents.manage',
            'view-activity-log' => 'activity.view',
        ];

        if (isset($namedPermissions[$ability])) {
            return $namedPermissions[$ability];
        }

        $subject = $arguments[0] ?? null;
        $isAsset = $subject instanceof Asset || $subject === Asset::class;

        if (! $isAsset) {
            return null;
        }

        return match ($ability) {
            'view', 'viewAny' => 'assets.view',
            'create' => 'assets.create',
            'update' => 'assets.update',
            'archive' => 'assets.archive',
            'audit' => 'assets.audit',
            'transfer' => 'assets.transfer',
            'manageRepair' => 'assets.repair',
            'manageAttachments' => 'assets.attachments',
            'generateDocument' => 'assets.documents',
            default => null,
        };
    }
}
