<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentRelease;
use Illuminate\Http\Request;

class AgentTokenController extends Controller
{
    public function index()
    {
        $tokens = AgentApiToken::orderByDesc('id')->get();

        // What the download button hands out, and what was live before it.
        //
        // Shown on the page rather than buried in a build script because the
        // question it answers is asked while looking at a laptop, not while
        // building: "is the file I just downloaded the new one, and do these
        // machines still have the old one?"
        $currentRelease = AgentRelease::current();
        $previousRelease = AgentRelease::previous();

        return view('agent.index', compact('tokens', 'currentRelease', 'previousRelease'));
    }

    public function download(AgentApiToken $token, Request $request)
    {
        $token->update(['last_used_at' => now()]);

        return $this->streamBinary($request);
    }

    /**
     * Stream the self-contained binary plus the zero-config trailer.
     *
     * Deliberately takes no token and needs no enrolled device. The agent
     * registers itself against /api/agent/register the first time it runs, using
     * the server address carried in the trailer, so requiring an existing token
     * to obtain the binary made the very first deployment impossible - a
     * chicken-and-egg deadlock with no way out except editing the database by
     * hand.
     *
     * This is also the URL every deployed agent re-pulls from when the fleet is
     * told to update. A redirect there was doubly wrong: an agent that follows
     * it would end up on a token-scoped URL belonging to some other machine, and
     * one that does not follow it would save a 49 KB HTML page as TrackerAgent.exe.
     */
    public function downloadLatest(Request $request)
    {
        return $this->streamBinary($request);
    }

    private function streamBinary(Request $request)
    {
        // The current release owns its own file; fall back to the legacy shared
        // binary for any release row that predates per-release storage, so an
        // environment mid-migration still serves what it did before.
        $current = AgentRelease::current();
        $exePath = ($current && $current->hasBinary())
            ? $current->absolutePath()
            : storage_path('app/agent/TrackerAgent.exe');

        if (! is_file($exePath)) {
            return back()->with('error', 'Agent executable not found. Build the agent first.');
        }

        return response()->streamDownload(function () use ($exePath, $request) {
            self::streamBinaryFile($exePath);
            echo self::buildConfigTrailer($request);
        }, 'TrackerAgent.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
        ]);
    }

    /**
     * Stream the binary in 1 MB chunks: the self-contained agent is ~160 MB and
     * must never be loaded into PHP memory.
     *
     * Public and static so the per-release download route in
     * AgentReleaseController can serve a specific stored build through the exact
     * same code path - one streaming implementation, not two that can drift.
     */
    public static function streamBinaryFile(string $path): void
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
     *
     * Public and static so the per-release download route appends the identical
     * trailer; a second copy of this format string is exactly how a trailer and
     * the reader that parses it drift apart.
     */
    public static function buildConfigTrailer(Request $request): string
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
