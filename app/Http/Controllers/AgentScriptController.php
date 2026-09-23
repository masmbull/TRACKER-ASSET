<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the audit agent PowerShell scripts with server URL + token baked in.
 *
 * GET /agent/run.ps1   → one-shot collector
 * GET /agent/daemon.ps1 → resident background daemon
 */
class AgentScriptController extends Controller
{
    private function serveAgent(string $file, Request $request): Response
    {
        $token = (string) $request->query('token', '');

        if ($token !== '') {
            $model = AgentApiToken::where('token_hash', hash('sha256', $token))->first();
            abort_unless($model?->isUsable() ?? false, 404, 'Unknown or revoked agent token.');
        }

        $script = (string) file_get_contents(base_path(sprintf('agent/%s', $file)));
        $url = rtrim((string) config('app.url'), '/');

        $script = str_replace('$env:TRACKER_API_URL', sprintf("'%s'", $url), $script);
        $script = str_replace('$env:TRACKER_AUDIT_TOKEN', sprintf("'%s'", $token), $script);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => sprintf('inline; filename="%s"', $file),
        ]);
    }

    public function script(Request $request)
    {
        return $this->serveAgent('tracker-audit-agent.ps1', $request);
    }

    public function daemon(Request $request)
    {
        return $this->serveAgent('tracker-agent-daemon.ps1', $request);
    }
}
