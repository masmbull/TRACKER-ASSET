<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Microsoft Entra ID SSO (PRD §12, §38) — full authorization-code flow with faked HTTP.
 */
class EntraAuthTest extends TestCase
{
    use RefreshDatabase;

    private function enableEntra(): void
    {
        config([
            'services.entra.enabled' => true,
            'services.entra.client_id' => 'client-123',
            'services.entra.client_secret' => 'secret-123',
            'services.entra.tenant_id' => 'tenant-123',
        ]);
    }

    public function test_sso_disabled_returns_404(): void
    {
        $this->get('/auth/microsoft')->assertNotFound();
        $this->get('/auth/microsoft/callback')->assertNotFound();
    }

    public function test_redirect_starts_authorization_flow(): void
    {
        $this->enableEntra();

        $response = $this->get('/auth/microsoft');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/tenant-123/oauth2/v2.0/authorize?', $location);
        $this->assertStringContainsString('client_id=client-123', $location);
        $this->assertNotNull(session('entra_state'));
    }

    public function test_callback_provisions_new_directory_user(): void
    {
        $this->enableEntra();

        Http::fake([
            '*/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/me' => Http::response([
                'id' => 'entra-object-id',
                'displayName' => 'Budi Santoso',
                'mail' => 'budi.santoso@mito.co.id',
                'department' => 'Finance',
                'jobTitle' => 'Analyst',
            ]),
        ]);

        $this->withSession(['entra_state' => 'valid-state'])
            ->get('/auth/microsoft/callback?code=auth-code&state=valid-state');

        $this->assertAuthenticated();

        $user = User::where('email', 'budi.santoso@mito.co.id')->firstOrFail();
        $this->assertSame('Budi Santoso', $user->name);
        $this->assertSame('entra-object-id', $user->entra_id);
        $this->assertSame('Finance', $user->department);
        $this->assertSame('viewer', $user->role);
        $this->assertTrue((bool) $user->is_active);

        $this->assertDatabaseHas('activity_logs', ['action' => 'LOGIN', 'source' => 'Microsoft Entra ID']);
    }

    public function test_callback_links_existing_user_and_logs_in(): void
    {
        $this->enableEntra();

        $existing = User::factory()->create([
            'email' => 'budi.santoso@mito.co.id',
            'role' => 'support',
            'entra_id' => null,
        ]);

        Http::fake([
            '*/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token']),
            'https://graph.microsoft.com/v1.0/me' => Http::response([
                'id' => 'entra-object-id',
                'mail' => 'budi.santoso@mito.co.id',
            ]),
        ]);

        $this->withSession(['entra_state' => 'valid-state'])
            ->get('/auth/microsoft/callback?code=auth-code&state=valid-state')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($existing);
        $this->assertSame('entra-object-id', $existing->fresh()->entra_id);
        // Existing roles are never overwritten by SSO.
        $this->assertSame('support', $existing->fresh()->role);
    }

    public function test_callback_rejects_deactivated_user(): void
    {
        $this->enableEntra();

        User::factory()->create(['email' => 'budi.santoso@mito.co.id', 'is_active' => false]);

        Http::fake([
            '*/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token']),
            'https://graph.microsoft.com/v1.0/me' => Http::response(['id' => 'x', 'mail' => 'budi.santoso@mito.co.id']),
        ]);

        $this->withSession(['entra_state' => 'valid-state'])
            ->get('/auth/microsoft/callback?code=auth-code&state=valid-state')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_callback_rejects_state_mismatch(): void
    {
        $this->enableEntra();

        $this->withSession(['entra_state' => 'real-state'])
            ->get('/auth/microsoft/callback?code=auth-code&state=forged-state')
            ->assertStatus(419);

        $this->assertGuest();
    }
}
