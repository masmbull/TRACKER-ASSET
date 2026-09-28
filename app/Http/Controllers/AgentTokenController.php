<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use Illuminate\Http\Request;

class AgentTokenController extends Controller
{
    public function index()
    {
        $tokens = AgentApiToken::orderByDesc('id')->get();

        return view('agent.index', compact('tokens'));
    }

    public function download(AgentApiToken $token, Request $request)
    {
        $token->update(['last_used_at' => now()]);

        $exePath = storage_path('app/agent/TrackerAgent.exe');
        if (! file_exists($exePath)) {
            return back()->with('error', 'Agent executable not found. Build the agent first.');
        }

        return response()->streamDownload(function () use ($exePath, $request) {
            $this->streamFile($exePath);
            echo self::configTrailer($request);
        }, 'TrackerAgent.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
        ]);
    }

    /**
     * Stream the binary in 1 MB chunks: the self-contained agent is ~70 MB and
     * must never be loaded into PHP memory.
     */
    private function streamFile(string $path): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            while (! feof($handle)) {
                echo fread($handle, 1024 * 1024);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Zero-config install breadcrumb: the downloaded installer reads the tail
     * of its own executable (`AgentConfigTrailer.cs`), so the binary is served
     * with a `<<<TRACKER-AGENT-CONFIG:<base64 json>:END>>>` trailer naming the
     * server it was downloaded from — no URL prompt during installation.
     *
     * Verified against the .NET 8 single-file host: it locates its bundle via an
     * absolute offset (not an end-of-file footer), so trailing bytes are ignored.
     */
    private static function configTrailer(Request $request): string
    {
        $urls = array_values(array_unique(array_filter(array_map(
            fn (?string $url) => rtrim((string) $url, '/'),
            [$request->getSchemeAndHttpHost(), config('app.url')]
        ))));

        $payload = base64_encode((string) json_encode([
            'server_urls' => $urls,
            'issued_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));

        return "\n<<<TRACKER-AGENT-CONFIG:{$payload}:END>>>\n";
    }

    public function downloadLatest()
    {
        // Only an agent that actually reports something may be used to serve an
        // update. Previously this took the newest row, or invented one with an
        // empty token hash and no device_id when the table was empty — so a
        // machine with a freshly wiped database would get a download tied to a
        // token that can never authenticate or be shown in the fleet.
        $token = AgentApiToken::query()
            ->whereNotNull('device_id')
            ->orderByDesc('id')
            ->first();

        if (! $token) {
            return redirect()->route('agent.index')
                ->with('error', 'No enrolled agent to serve an update from. Deploy the agent on a machine first.');
        }

        return redirect()->route('agent.download', $token);
    }

    public function revoke(AgentApiToken $token)
    {
        $token->update(['is_active' => false]);

        // Close out the commands that can no longer be delivered. Leaving them
        // pending would pin the fleet page to an endless "Uninstall queued" for
        // a machine the server cannot even authenticate any more.
        $orphaned = AgentCommand::query()
            ->where('agent_token_id', $token->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'executed_at' => now(),
                'result' => ['message' => 'Not delivered: the agent token was revoked.'],
            ]);

        return back()->with('success', sprintf(
            "Device '%s' revoked.%s",
            $token->name,
            $orphaned > 0 ? sprintf(' %d queued command(s) closed as undeliverable.', $orphaned) : ''
        ));
    }
}
