<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Accounts are provisioned interactively, never with a shared/default password.
 *
 * Kept as a no-op because DatabaseSeeder includes it and existing installations
 * may still call it directly. Use `php artisan tracker:admin:create` for the
 * first administrator, then invite/manage other users in the application.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('No default users created. Provision an administrator with tracker:admin:create.');
    }
}
