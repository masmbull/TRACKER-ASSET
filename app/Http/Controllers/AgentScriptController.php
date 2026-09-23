<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use Illuminate\Http\Request;

/**
 * Serves the audit agent PowerShell script (PRD §11) with the server URL and
 * a pre-issued token baked in, so fleet rollout is a single paste on each
 * laptop — no .exe, no install, revocable from the web.
 *
 * GET /agent/run.ps1              → script with the requested token
 * GET /agent/run.ps1?token=...&install=1 → same, pre-set to register the
 *                                          daily scheduled task
 */
class AgentScriptController extends Controller
{
    public function script(Request $request)
    {
        $token = (string) $request->query('token', '');

        if ($token !== '') {
            $model = AgentApiToken::where('token_hash', hash('sha256', $token))->first();

            abort_unless($model?->isUsable() ?? false, 404, 'Unknown or revoked agent token.');
        }

        $script = (string) file_get_contents(base_path('agent/tracker-audit-agent.ps1'));

        // The script is static; these two bindings make it deployment-specific.
        $url = rtrim((string) config('app.url'), '/');

        $script = str_replace(
            '$env:TRACKER_API_URL',
            "'" . $url . "'",
            $script,
            $countApiUrl
        );

        $script = str_replace(
            '$env:TRACKER_AUDIT_TOKEN',
            "'" . $token . "'",
            $script,
            $countToken
        );

        if ($request->boolean('install')) {
            $script = str_replace('[switch]$Install,', '[switch]$Install = $true,', $script, $countInstall);
        }

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="tracker-audit-agent.ps1"',
        ]);
    }
}
