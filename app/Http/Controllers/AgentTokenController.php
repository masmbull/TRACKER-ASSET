<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
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
        $token = AgentApiToken::orderByDesc('id')->first();

        if (! $token) {
            $token = AgentApiToken::create([
                'name' => 'device-'.now()->format('Ymd-His'),
                'token_hash' => hash('sha256', ''),
                'token_plain' => '',
            ]);
        }

        return redirect()->route('agent.download', $token);
    }

    public function revoke(AgentApiToken $token)
    {
        $token->update(['is_active' => false]);

        return back()->with('success', "Device '{$token->name}' revoked.");
    }
}
