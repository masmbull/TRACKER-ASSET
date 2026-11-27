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
        // Never silently serve the legacy shared binary: it is a persistent
        // volume and can be any old build (the observed case was 1.7.32 while
        // the shipped build was 1.7.39). That made "Update from Live Fleet"
        // install an older exe and still ack success.
        [$exePath, $version] = $this->resolveBinary();

        if ($exePath === null) {
            return response('Agent executable not found. Build and ship the agent first.', 404);
        }

        // Build the trailer once so the response can carry a real Content-Length.
        // Without it the agent's progress loop (which divides bytes copied by
        // Content-Length) never advances past its initial 5% and the command
        // looks stalled even though the 160 MB transfer is healthy.
        $trailer = self::buildConfigTrailer($request);
        $size = filesize($exePath) + strlen($trailer);

        return response()->streamDownload(function () use ($exePath, $trailer) {
            self::streamBinaryFile($exePath);
            echo $trailer;
        }, 'TrackerAgent.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Content-Length' => $size,
            // Lets the agent reject a stale download before it swaps. A HEAD
            // request is cheap; installing the wrong build is not.
            'X-Agent-Version' => $version ?? '',
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null} path and release version
     */
    private function resolveBinary(): array
    {
        $current = AgentRelease::current();

        if ($current && $current->hasBinary() && is_file($current->absolutePath())) {
            return [$current->absolutePath(), $current->version];
        }

        // The build shipped with this image is authoritative when a release row
        // exists but its file was never uploaded. Reassemble it into storage so
        // the streaming path stays identical for every source.
        $parts = glob(resource_path('agent-build/TrackerAgent.exe.part*')) ?: [];
        sort($parts);

        if ($parts !== []) {
            $target = storage_path('app/agent/TrackerAgent.exe');
            $expectedSize = array_sum(array_map('filesize', $parts));

            if (! is_file($target) || filesize($target) !== $expectedSize) {
                $tmp = $target.'.tmp';
                $out = fopen($tmp, 'wb');

                if ($out !== false) {
                    foreach ($parts as $part) {
                        $in = fopen($part, 'rb');
                        if ($in === false) {
                            continue;
                        }
                        while (! feof($in)) {
                            fwrite($out, fread($in, 1024 * 1024));
                        }
                        fclose($in);
                    }
                    fclose($out);
                    rename($tmp, $target);
                }
            }

            if (is_file($target)) {
                return [$target, $this->shippedVersion()];
            }
        }

        // Last resort for an environment with neither a release file nor the
        // packaged parts. No version is advertised, so the agent can refuse it.
        $legacy = storage_path('app/agent/TrackerAgent.exe');

        return [is_file($legacy) ? $legacy : null, null];
    }

    private function shippedVersion(): ?string
    {
        $csproj = base_path('agent/TrackerAgent/TrackerAgent.csproj');

        if (is_file($csproj) && preg_match('/<Version>([^<]+)<\/Version>/', (string) file_get_contents($csproj), $m)) {
            return trim($m[1]);
        }

        return null;
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
            array_merge(
                [$request->getSchemeAndHttpHost(), config('app.url')],
                self::serverLanIps()
            )
        ))));

        $payload = base64_encode((string) json_encode([
            'server_urls' => $urls,
            'issued_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));

        return "\n<<<TRACKER-AGENT-CONFIG:{$payload}:END>>>\n";
    }

    /**
     * The server's own LAN IP addresses (e.g. 192.168.x.x, 10.x.x.x), so an
     * agent downloaded from http://127.0.0.1:8000 on the server machine can
     * still reach it from a different laptop on the same network.
     */
    private static function serverLanIps(): array
    {
        $ips = [];

        // Linux / macOS
        foreach (explode("\n", @shell_exec('hostname -I 2>/dev/null')) as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && str_starts_with($ip, '127.')) {
                continue;
            }
            if ($ip !== '') {
                $ips[] = "http://{$ip}:8000";
            }
        }

        // Windows
        if (empty($ips)) {
            $output = @shell_exec('ipconfig 2>/dev/null');
            if ($output !== false) {
                foreach (explode("\n", $output) as $line) {
                    if (stripos($line, 'IPv4') !== false && preg_match('/(\d+\.\d+\.\d+\.\d+)/', $line, $m)) {
                        $ip = $m[1];
                        if (str_starts_with($ip, '127.')) {
                            continue;
                        }
                        $ips[] = "http://{$ip}:8000";
                    }
                }
            }
        }

        return array_values(array_unique($ips));
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
