<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Microsoft Entra ID / Microsoft 365 authentication (PRD §12, §38).
 *
 * Standard OAuth 2.0 authorization-code flow against the v2.0 endpoints,
 * with the user profile read from Microsoft Graph /v1.0/me.
 *
 * Enabled only when services.entra.enabled is true (ENTRA_ENABLED=true in .env).
 * When disabled the application falls back to local email/password auth.
 */
class EntraOAuthService
{
    public function enabled(): bool
    {
        return (bool) config('services.entra.enabled');
    }

    public function authorizationUrl(string $state): string
    {
        return $this->authority().'/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => (string) config('services.entra.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $state,
        ]);
    }

    /**
     * Exchange the authorization code for tokens.
     *
     * @return array{access_token:string, id_token:string, expires_in:int}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post($this->authority().'/oauth2/v2.0/token', [
            'client_id' => (string) config('services.entra.client_id'),
            'client_secret' => (string) config('services.entra.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'openid profile email User.Read',
        ])->throw();

        return $response->json();
    }

    /**
     * Read the signed-in user's directory profile from Microsoft Graph.
     */
    public function getUser(string $accessToken): array
    {
        return Http::withToken($accessToken)
            ->get('https://graph.microsoft.com/v1.0/me')
            ->throw()
            ->json();
    }

    public function profileEmail(array $profile): ?string
    {
        return $profile['mail'] ?? $profile['userPrincipalName'] ?? null;
    }

    private function authority(): string
    {
        $tenant = (string) (config('services.entra.tenant_id') ?: 'common');

        return "https://login.microsoftonline.com/{$tenant}";
    }

    private function redirectUri(): string
    {
        return (string) (config('services.entra.redirect') ?: config('app.url').'/auth/microsoft/callback');
    }
}
