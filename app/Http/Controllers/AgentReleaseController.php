<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Releases page: ship a build, and roll the fleet onto it.
 *
 * Before this, shipping a new agent was a chain nobody could see: bump the
 * version in the .csproj, run a Windows-only build.bat, run an artisan command
 * to record the number, then hope the binary the download route served was the
 * one that build just wrote. Two of those steps happened on a developer's
 * laptop, and the page could only show the *number* - never the file, its size,
 * or a hash. An operator could not tell a release that shipped from one whose
 * upload half-failed.
 *
 * This page makes the binary the unit of record. Each release owns its own
 * file under storage/app/agent/releases, with a size and a SHA-256, and exactly
 * one is current. "Activate" makes a stored build the one the download button
 * and the fleet update serve; that is what makes a rollback a click rather than
 * a file copy.
 */
class AgentReleaseController extends Controller
{
    /**
     * Registry of shipped builds, newest first.
     */
    public function index()
    {
        $releases = AgentRelease::query()
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->get();

        $current = AgentRelease::current();

        // How many devices sit on each recorded version, so the page can say
        // "3 devices need this" next to a release instead of a bare number.
        $byVersion = AgentApiToken::query()
            ->where('is_active', true)
            ->get()
            ->groupBy(fn (AgentApiToken $t) => AgentRelease::normalize((string) $t->agent_version))
            ->map->count();

        $outdated = AgentApiToken::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (AgentApiToken $t) => $t->isOutdated() === true)
            ->count();

        $unknown = AgentApiToken::query()
            ->where('is_active', true)
            ->whereNull('agent_version')
            ->count();

        return view('agent.releases', compact('releases', 'current', 'byVersion', 'outdated', 'unknown'));
    }

    /**
     * Accept a freshly built TrackerAgent.exe and record it as a release.
     *
     * The upload *is* the release: version, size and hash are read off the file
     * rather than typed in, so the page cannot describe a build that was never
     * uploaded. The file is checked for the Windows PE magic ("MZ") before it is
     * stored - a truncated upload or a stray HTML error page saved as .exe is
     * rejected here instead of being handed to every agent on the next update.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:64', 'regex:/\d+(?:\.\d+)*/'],
            'notes' => ['nullable', 'string', 'max:500'],
            'binary' => ['required', 'file', 'max:262144'],
        ], [
            'version.regex' => 'The version needs a number in it, like 1.4.0.',
            'binary.max' => 'The agent binary may not be larger than 256 MB.',
        ]);

        $upload = $request->file('binary');
        $real = $upload->getRealPath();

        if (! is_string($real) || ! $this->looksLikePe($real)) {
            return back()->withInput()->with('error', 'That file is not a Windows executable (no MZ header). Build TrackerAgent.exe and upload it.');
        }

        $version = trim((string) $validated['version']);

        if (AgentRelease::where('version', $version)->exists()) {
            return back()->withInput()->with('error', "Release {$version} already exists. Bump the version in TrackerAgent.csproj or delete the old release first.");
        }

        $size = (int) $upload->getSize();
        $sha = (string) hash_file('sha256', $real);

        // Stored under a stable, version-named path so the file on disk and the
        // row that describes it cannot drift apart.
        //
        // putFileAs streams the upload to storage. Calling ->get() here would
        // pull the whole ~160 MB self-contained binary into PHP memory, which is
        // how an upload turns into an out-of-memory 500 that names nothing.
        $path = 'agent/releases/'.$version.'-'.substr($sha, 0, 12).'.exe';
        Storage::disk('local')->putFileAs('agent/releases', $upload, basename($path));

        $release = AgentRelease::ship($version, $validated['notes'] ?? null);
        $release->update([
            'file_path' => $path,
            'file_size' => $size,
            'sha256' => $sha,
        ]);

        return redirect()->route('agent.releases')
            ->with('success', "Release {$version} uploaded (".number_format($size / 1024 / 1024, 1).' MB) and is now current.');
    }

    /**
     * Make a stored release the one the download and updates serve.
     *
     * This is the rollback path: an older build that is still on disk becomes
     * current again, without rebuilding or copying any file. Exactly one row
     * stays current, the same invariant AgentRelease::ship maintains.
     */
    public function activate(AgentRelease $release): RedirectResponse
    {
        if (! $release->hasBinary()) {
            return back()->with('error', "Release {$release->version} has no stored binary, so it cannot be activated.");
        }

        AgentRelease::query()->update(['is_current' => false]);
        $release->update(['is_current' => true]);

        return back()->with('success', "Release {$release->version} is now the current build. Agents already on it are left alone.");
    }

    /**
     * Point every device that is not on the current release at it.
     *
     * Targeted rows rather than the broadcast the fleet page uses: here we know
     * exactly which build we want and which devices are missing it, so a device
     * already carrying it is never handed a 160 MB re-download. A device that
     * never reported a version is included - "unknown" may well be old, and
     * leaving it out would strand precisely the machines this page exists to
     * bring forward.
     */
    public function push(Request $request, AgentRelease $release): JsonResponse|RedirectResponse
    {
        if (! $release->hasBinary()) {
            return $this->respond($request, "Release {$release->version} has no stored binary to push.", false);
        }

        if (! $release->is_current) {
            // Pushing an old build without making it current would leave the
            // download button serving something else, so the next unqualified
            // update would undo this. Refuse rather than ship a contradiction.
            return $this->respond($request, "Activate {$release->version} first, then push it to the fleet.", false);
        }

        $url = route('agent.releases.download', $release);

        $targets = AgentApiToken::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->filter(fn (AgentApiToken $t) => $t->isOutdated() !== false && $t->isUsable());

        foreach ($targets as $token) {
            AgentCommand::create([
                'agent_token_id' => $token->id,
                'command' => 'update',
                'payload' => ['url' => $url],
                'status' => 'pending',
            ]);
        }

        return $this->respond($request, sprintf(
            'Update to %s queued for %d device(s). Each re-pulls the binary and restarts in the background.',
            $release->version,
            $targets->count()
        ));
    }

    /**
     * Stream this release's binary, with the zero-config trailer.
     *
     * Public like the other download routes: an agent re-pulling during an
     * update carries its bearer token but this endpoint is reachable without a
     * session, which is what lets the very first install happen on an empty
     * fleet. Only ever serves the file the row recorded - never a path from the
     * request.
     */
    public function download(Request $request, AgentRelease $release): Response|StreamedResponse|RedirectResponse
    {
        $path = $release->absolutePath();

        if ($path === null || ! is_file($path)) {
            return back()->with('error', "Release {$release->version} has no stored binary.");
        }

        return response()->streamDownload(function () use ($path, $request) {
            AgentTokenController::streamBinaryFile($path);
            echo AgentTokenController::buildConfigTrailer($request);
        }, 'TrackerAgent.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
        ]);
    }

    /**
     * Delete a release and its stored binary.
     *
     * Refuses the current one: deleting what the fleet is being shipped from
     * would leave the download button pointing at nothing, which is worse than
     * making the operator activate a different build first.
     */
    public function destroy(AgentRelease $release): RedirectResponse
    {
        if ($release->is_current) {
            return back()->with('error', "Release {$release->version} is current. Activate another release before deleting this one.");
        }

        if ($release->file_path && Storage::disk('local')->exists($release->file_path)) {
            Storage::disk('local')->delete($release->file_path);
        }

        $release->delete();

        return back()->with('success', "Release {$release->version} deleted.");
    }

    /** True when the file starts with the DOS "MZ" signature of a Windows PE. */
    private function looksLikePe(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, 2) === 'MZ';
        } finally {
            fclose($handle);
        }
    }

    private function respond(Request $request, string $message, bool $success = true): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $success, 'message' => $message], $success ? 200 : 422);
        }

        return back()->with($success ? 'success' : 'error', $message);
    }
}
