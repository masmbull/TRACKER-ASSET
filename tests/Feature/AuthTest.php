<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Need users table seeded for auth test
        User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'viewer',
        ]);
    }

    public function test_user_can_login_with_local_auth(): void
    {
        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs(User::first());
    }

    public function test_user_cannot_login_with_wrong_password(): void
    {
        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);

        $response->assertSessionHasErrors();
        $this->assertGuest();
    }
}
