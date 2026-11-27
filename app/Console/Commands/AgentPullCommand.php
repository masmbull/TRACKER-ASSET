<?php

namespace App\Console\Commands;

use App\Models\AgentRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Download a published TrackerAgent.exe from the GitHub Release and register
 * it locally. This is the deployment counterpart to the agent-release workflow:
 * CI builds the binary, the server pulls it without needing a dotnet SDK.
 */
class AgentPullCommand extends Command
{
    protected $signature = 'agent:pull {version? : Release version, defaults to the current release}';

    protected $description = 'Download a published TrackerAgent.exe and register it as the current release';

    public function handle(): int
    {
        $version = $this->argument('version');
        $release = $version
            ? AgentRelease::where('version', $version)->first()
            : AgentRelease::current();

        if (! $release) {
            $this->error('No release found. Run agent:release first, or pass a version.');

            return self::FAILURE;
        }

        $tag = 'agent-'.$release->version;
        $token = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: null;

        $headers = ['Accept' => 'application/octet-stream', 'User-Agent' => 'TRACKER-ASSET-agent-pull'];
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $this->info("Downloading TrackerAgent {$release->version} from GitHub Release {$tag}...");

        // Resolve the asset through the API first: the tag may exist while the
        // asset name differs, and a private repo needs the auth header on the
        // API call too. Fall back to the well-known download URL when the API
        // is unreachable (e.g. a network that blocks api.github.com).
        $assetUrl = "https://github.com/masmbull/TRACKER-ASSET/releases/download/{$tag}/TrackerAgent.exe";
        $apiHeaders = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'TRACKER-ASSET-agent-pull'];
        if ($token) {
            $apiHeaders['Authorization'] = 'Bearer '.$token;
        }

        try {
            $api = Http::withHeaders($apiHeaders)->timeout(30)
                ->get('https://api.github.com/repos/masmbull/TRACKER-ASSET/releases/tags/'.$tag);

            if ($api->successful()) {
                $asset = collect($api->json('assets', []))
                    ->first(fn (array $a) => strtolower($a['name'] ?? '') === 'trackeragent.exe');

                if ($asset && ! empty($asset['browser_download_url'])) {
                    $assetUrl = $asset['browser_download_url'];
                }
            }
        } catch (\Throwable) {
            // The direct URL below is still a valid fallback.
        }

        $response = Http::withHeaders($headers)->timeout(300)->get($assetUrl);

        if (! $response->successful()) {
            $this->error("Download failed with HTTP {$response->status()} from {$assetUrl}. The release may be private - set GITHUB_TOKEN.");

            return self::FAILURE;
        }

        $binary = $response->body();
        if (! str_starts_with($binary, 'MZ')) {
            $this->error('Downloaded file is not a Windows executable (no MZ header).');

            return self::FAILURE;
        }

        $sha = hash('sha256', $binary);
        if ($release->sha256 && ! hash_equals($release->sha256, $sha)) {
            $this->error("SHA-256 mismatch: expected {$release->sha256}, downloaded {$sha}.");

            return self::FAILURE;
        }

        $stored = $release->file_path ?: 'agent/releases/'.$release->version.'-'.substr($sha, 0, 12).'.exe';
        Storage::disk('local')->put($stored, $binary);
        Storage::disk('local')->put('agent/TrackerAgent.exe', $binary);

        $release->update([
            'file_path' => $stored,
            'file_size' => strlen($binary),
            'sha256' => $sha,
        ]);

        $this->info("Stored {$release->version} (".number_format(strlen($binary) / 1024 / 1024, 1).' MB).');
        $this->line("SHA-256: {$sha}");
        $this->comment('The agent download route now serves this build.');

        return self::SUCCESS;
    }
}
