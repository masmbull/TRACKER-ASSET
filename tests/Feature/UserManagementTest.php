<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * User management (admin only roles) (PRD §13, §46).
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_active_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'New Support',
            'email' => 'new-support@mito.co.id',
            'role' => 'support',
            'password' => 'secretpass1',
            'password_confirmation' => 'secretpass1',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'new-support@mito.co.id', 'role' => 'support', 'is_active' => 1]);

        // Password must actually work for login.
        $user = User::where('email', 'new-support@mito.co.id')->firstOrFail();
        $this->assertTrue(Hash::check('secretpass1', $user->password));
    }

    public function test_admin_can_change_role_and_deactivate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'support',
            'is_active' => '0',
        ])->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertSame('support', $user->role);
        $this->assertFalse($user->is_active);
    }

    public function test_support_cannot_access_user_management(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($support)->get(route('users.index'))->assertForbidden();
        $this->actingAs($support)->get(route('users.create'))->assertForbidden();
        $this->actingAs($support)->put(route('users.update', $user), [
            'name' => 'Hacked',
            'email' => $user->email,
            'role' => 'admin',
        ])->assertForbidden();

        $this->assertNotSame('Hacked', $user->fresh()->name);
    }

    public function test_deactivated_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'blocked@mito.co.id',
            'password' => Hash::make('password'),
            'is_active' => false,
        ]);

        $response = $this->post('/login', [
            'email' => 'blocked@mito.co.id',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
