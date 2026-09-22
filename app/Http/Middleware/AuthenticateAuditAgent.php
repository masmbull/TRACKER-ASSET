<?php

namespace App\Http\Middleware;

use App\Models\AgentApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token authentication for the lightweight audit agent API (PRD §11).
 *
 * Expects: Authorization: Bearer <token>
 * Tokens are issued with `php artisan audit:token`; only SHA-256 hashes
 * are stored, so a leaked database never exposes usable tokens.
 */
class AuthenticateAuditAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainText = $request->bearerToken();

        if (! $plainText) {
            return response()->json(['message' => 'Audit API token required.'], 401);
        }

        $token = AgentApiToken::where('token_hash', hash('sha256', $plainText))->first();

        if (! $token || ! $token->isUsable()) {
            return response()->json(['message' => 'Invalid or expired audit API token.'], 401);
        }

        $token->update(['last_used_at' => now()]);

        $request->attributes->set('agent_token_id', $token->id);
        $request->attributes->set('agent_token_name', $token->name);

        return $next($request);
    }
}
