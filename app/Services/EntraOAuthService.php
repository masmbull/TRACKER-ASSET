<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
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

    /**
     * An application-only access token, for reading the tenant directory.
     *
     * A different grant from the sign-in flow above, and deliberately not reused.
     * The delegated `User.Read` scope carried by a signed-in user can only read
     * that one user - asking it for the directory returns 403, or quietly a list
     * of one. Directory-wide reads need an application permission
     * (User.Read.All) and therefore the client-credentials grant.
     *
     * The token is cached until shortly before it expires. Directory search is
     * typeahead, so an uncached call would hit the token endpoint on every
     * keystroke, and Microsoft throttles that endpoint aggressively enough to
     * start failing the whole feature.
     */
    public function appToken(): string
    {
        $clientId = (string) config('services.entra.client_id');
        $secret = (string) config('services.entra.client_secret');
        $tenant = (string) config('services.entra.tenant_id');

        // `common` is fine for interactive sign-in and meaningless for an
        // application token: there is no single directory to read. Failing here
        // with a clear message beats a 400 from Microsoft later.
        if ($clientId === '' || $secret === '' || $tenant === '' || $tenant === 'common') {
            throw new DirectorySearchException(
                'Directory access needs ENTRA_TENANT_ID set to a real tenant, not "common", '
                .'plus client id and secret. Sign-in still works with "common"; directory search does not.'
            );
        }

        $cacheKey = 'entra:app-token';

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->post($this->authority().'/oauth2/v2.0/token', [
            'client_id' => $clientId,
            'client_secret' => $secret,
            'grant_type' => 'client_credentials',
            'scope' => 'https://graph.microsoft.com/.default',
        ])->throw();

        $token = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        // Expire a minute early rather than on the boundary: a token that dies
        // between the cache read and the Graph call produces a 401 that looks
        // like a permissions problem and gets diagnosed as one.
        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
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
