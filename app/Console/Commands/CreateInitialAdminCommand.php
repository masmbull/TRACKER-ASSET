<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateInitialAdminCommand extends Command
{
    protected $signature = 'tracker:admin:create';

    protected $description = 'Interactively provision the first administrator without storing a default credential';

    public function handle(): int
    {
        if (User::query()->where('role', 'admin')->exists()) {
            $this->error('An administrator already exists. Create additional accounts from Access Control.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Administrator name'));
        $email = strtolower(trim((string) $this->ask('Administrator email')));

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A name and a valid email address are required.');

            return self::INVALID;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('That email address is already registered. Sign in and use Access Control instead.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Administrator password (12+ characters)');
        $confirmation = (string) $this->secret('Confirm password');

        if (strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
            $this->error('The password must be at least 12 characters and both entries must match.');

            return self::INVALID;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Complete the seeders that need an administrator as their creator.
        $this->call('db:seed', ['--class' => DocumentTemplateSeeder::class, '--force' => true]);

        $this->info('Initial administrator created. Sign in with the address you entered; the password is not displayed or written to logs.');

        return self::SUCCESS;
    }
}
