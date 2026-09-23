<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Web UI for fleet agent tokens (PRD §11): generate, copy one-liner install
 * command, revoke. Plaintext token is shown exactly once at creation.
 */
class AgentTokenController extends Controller
{
    public function index()
    {
        $tokens = AgentApiToken::orderByDesc('id')->get();

        return view('agent.index', compact('tokens'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:agent_api_tokens,name',
            'expires_days' => 'nullable|integer|min:1|max:3650',
        ]);

        $plainText = sprintf('tat_%s', Str::random(48));
        $expiresDays = $validated['expires_days'] ?? null;

        AgentApiToken::create([
            'name' => $validated['name'],
            'token_hash' => hash('sha256', $plainText),
            'abilities' => ['audit:submit', 'assets:lookup'],
            'expires_at' => $expiresDays ? now()->addDays((int) $expiresDays) : null,
        ]);

        $daemonUrl = route('agent.daemon', ['token' => $plainText]);

        return redirect()
            ->route('agent.index')
            ->with('created_token', [
                'name' => $validated['name'],
                'plain_text' => $plainText,
                'install' => sprintf(
                    "powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command \"iwr '%s' -OutFile '%%LOCALAPPDATA%%\\TRACKER\\daemon.ps1'; powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File '%%LOCALAPPDATA%%\\TRACKER\\daemon.ps1'\"",
                    $daemonUrl,
                ),
            ]);
    }

    public function revoke(AgentApiToken $token)
    {
        $token->update(['is_active' => false]);

        return redirect()
            ->route('agent.index')
            ->with('success', "Token '{$token->name}' revoked — laptops using it are cut off at their next run.");
    }
}
