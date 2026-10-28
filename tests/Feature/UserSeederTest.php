<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_seeder_does_not_create_default_accounts(): void
    {
        (new UserSeeder)->run();

        $this->assertSame(0, User::query()->count());
    }
}
