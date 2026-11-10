<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing out (logout).
 *
 * Logging out has to end the server-side session, not only forget the user:
 * the next request on the same cookie must be treated as a guest, and the
 * session data built up during the shift must not survive.
 */
class AuthLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_invalidates_the_session_and_returns_to_login(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $response = $this->actingAs($user)
            ->withSession(['shift-marker' => 'still-here'])
            ->post(route('logout'));

        $response->assertRedirect('/login');
        $response->assertSessionMissing('shift-marker');

        $this->assertGuest();

        // The same client cannot walk back into a protected page with the old
        // session cookie.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_guest_posting_logout_is_sent_to_the_login_page(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
    }
}
