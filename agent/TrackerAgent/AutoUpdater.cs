using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Threading;

namespace TrackerAgent;

public static class AutoUpdater
{
    // A 160 MB single-file build takes well over two minutes on a slow link.
    // The old timeout aborted the transfer and left the command stuck at 5%.
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromMinutes(10) };

    public static bool CheckForUpdate(string serverUrl, string token, out string message)
    {
        message = "No update available.";
        string? tempPath = null;

        try
        {
            var currentExe = Environment.ProcessPath
                ?? Path.Combine(AppContext.BaseDirectory, "TrackerAgent.exe");

            if (!File.Exists(currentExe))
            {
                message = "The current agent executable could not be located.";
                return false;
            }

            var currentDir = Path.GetDirectoryName(currentExe)!;
            tempPath = Path.Combine(currentDir, $"_check_TrackerAgent_{Guid.NewGuid():N}.exe");
            var downloadUrl = $"{serverUrl.TrimEnd('/')}/agent/download";

            using (var req = new HttpRequestMessage(HttpMethod.Get, downloadUrl))
            {
                if (!string.IsNullOrWhiteSpace(token))
                {
                    req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
                }

                using var resp = Http.Send(req, HttpCompletionOption.ResponseHeadersRead);
                if (!resp.IsSuccessStatusCode)
                {
                    message = $"The update server rejected the check (HTTP {(int)resp.StatusCode}).";
                    return false;
                }

                using var stream = resp.Content.ReadAsStream();
                using var fs = new FileStream(tempPath, FileMode.Create, FileAccess.Write, FileShare.None);
                stream.CopyTo(fs);
            }

            var currentIdentity = InstallCheck.ReadBuildIdentity(currentExe);
            var latestIdentity = InstallCheck.ReadBuildIdentity(tempPath);

            if (string.IsNullOrWhiteSpace(latestIdentity))
            {
                message = "The downloaded update file is invalid or unreadable.";
                return false;
            }

            if (InstallCheck.IsSameBuild(currentIdentity, latestIdentity))
            {
                message = "No update is available for this device.";
                return false;
            }

            message = $"Update available: {InstallCheck.ReadVersion(tempPath)?.ToString() ?? latestIdentity}";
            return true;
        }
        catch (Exception ex)
        {
            message = $"Could not contact the update server. {ex.Message}";
            return false;
        }
        finally
        {
            if (!string.IsNullOrWhiteSpace(tempPath) && File.Exists(tempPath))
            {
                try { File.Delete(tempPath); }
                catch { }
            }
        }
    }

    /// <summary>
    /// Checks and executes self-update from the server's /agent/download endpoint.
    /// Spawns a background helper script, replaces the executable, and restarts.
    /// </summary>
    /// <param name="payloadUrl">
    /// Download URL supplied by the server in the command payload, if any. The
    /// "Update all" button sends one so the binary is pulled from the exact
    /// route the operator pointed at. This used to be ignored in favour of a
    /// hardcoded /agent/download, which made the payload decoration only.
    /// </param>
    /// <param name="resumeAsDaemon">
    /// True when this process is the SYSTEM daemon, so the swap re-triggers the
    /// boot task instead of launching a tray. A tray started from session 0 has no
    /// desktop and exits, which is how an updated machine went silent.
    /// </param>
    public static bool PerformUpdate(string serverUrl, string token, string? payloadUrl = null, Action<int, string>? onProgress = null, bool resumeAsDaemon = false)
    {
        try
        {
            var currentExe = Environment.ProcessPath
                ?? Path.Combine(AppContext.BaseDirectory, "TrackerAgent.exe");

            if (!File.Exists(currentExe))
            {
                Console.Error.WriteLine("[!] Current executable not found for update.");
                return false;
            }

            var currentDir = Path.GetDirectoryName(currentExe)!;
            var tempNewExe = Path.Combine(currentDir, $"_new_TrackerAgent_{Guid.NewGuid():N}.exe");
            var updateScript = Path.Combine(currentDir, "_update_runner.bat");

            onProgress?.Invoke(5, "Downloading the new version...");

            // Prefer the URL the server sent with the command. Falling back to
            // /agent/download keeps --update (run by hand, no payload) working.
            var downloadUrl = !string.IsNullOrWhiteSpace(payloadUrl)
                ? payloadUrl
                : $"{serverUrl.TrimEnd('/')}/agent/download";
            Console.WriteLine($"[*] Downloading latest agent from: {downloadUrl}");

            using (var req = new HttpRequestMessage(HttpMethod.Get, downloadUrl))
            {
                if (!string.IsNullOrWhiteSpace(token))
                {
                    req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
                }

                using var resp = Http.Send(req, HttpCompletionOption.ResponseHeadersRead);
                if (!resp.IsSuccessStatusCode)
                {
                    Console.Error.WriteLine($"[X] Failed to download update: HTTP {(int)resp.StatusCode} {resp.ReasonPhrase}");
                    return false;
                }

                using var stream = resp.Content.ReadAsStream();
                using var fs = new FileStream(tempNewExe, FileMode.Create, FileAccess.Write, FileShare.None);

                // Chunked copy so the bar moves and, more importantly, so the
                // command keeps a fresh updated_at while the 160 MB downloads. A
                // single CopyTo reported nothing for the whole transfer, and the
                // server's stall detector then declared a slow-but-healthy download
                // dead after its grace window.
                var total = resp.Content.Headers.ContentLength ?? 0;
                var buffer = new byte[81920];
                long copied = 0;
                var lastReport = 0;
                var lastReportAt = DateTime.UtcNow;

                int read;
                while ((read = stream.Read(buffer, 0, buffer.Length)) > 0)
                {
                    fs.Write(buffer, 0, read);
                    copied += read;

                    if (onProgress != null)
                    {
                        // 5%..85% spans the transfer. When the server streams
                        // without Content-Length (Laravel streamDownload), fall
                        // back to a byte-based estimate and a time heartbeat so
                        // the bar and the command's updated_at keep moving.
                        var pct = total > 0
                            ? 5 + (int)(copied * 80 / total)
                            : 5 + (int)Math.Min(80, copied / (2L * 1024 * 1024));
                        var now = DateTime.UtcNow;
                        if (pct >= lastReport + 5 || now - lastReportAt > TimeSpan.FromSeconds(10))
                        {
                            lastReport = Math.Max(lastReport, pct);
                            lastReportAt = now;
                            // Clean stage: the server's progressLabel() adds the
                            // percentage itself. Including it here produced
                            // "…(15%) (15%) · 20s" in the dialog.
                            var detail = total > 0 ? null : $" ({copied / 1024 / 1024} MB)";
                            onProgress(pct, "Downloading the new version…" + detail);
                        }
                    }
                }
            }

            var fi = new FileInfo(tempNewExe);
            if (fi.Length < 1024 * 50) // sanity check: at least 50KB
            {
                Console.Error.WriteLine("[X] Downloaded file is too small or corrupt. Aborting update.");
                try { File.Delete(tempNewExe); } catch { }
                return false;
            }

            // A stale file on the server's persistent volume used to be served
            // without complaint: the agent swapped in an older build, acked
            // "done", and the tray still showed the old version. Refuse anything
            // that is not newer than the running exe.
            var downloadedVersion = FileVersionInfo.GetVersionInfo(tempNewExe).ProductVersion;
            var installedVersion = FileVersionInfo.GetVersionInfo(currentExe).ProductVersion;
            if (! IsNewerBuild(downloadedVersion, installedVersion))
            {
                Console.Error.WriteLine(
                    $"[X] Server served build {downloadedVersion ?? "unknown"}, not newer than installed {installedVersion ?? "unknown"}. Refusing to swap.");
                try { File.Delete(tempNewExe); } catch { }
                return false;
            }

            // Download is the whole wait (160 MB), so report it complete before
            // the near-instant swap: an operator watching the bar sees it move
            // through download and finish, instead of sitting at 5% until the
            // process vanishes.
            onProgress?.Invoke(90, "Installing and restarting...");

            Console.WriteLine($"[OK] Downloaded new version ({fi.Length / 1024 / 1024:N1} MB). Preparing swap...");

            // Restart without CLI flags so installed config launches the tray
            // and repairs the Run key instead of reviving a headless daemon.
            //
            // Bounded, and it ALWAYS ends by launching the installed exe.
            //
            // The previous version looped `del` until the old exe was gone and
            // only then moved the new one in. If the delete never succeeded -
            // antivirus holding the 160 MB file, a handle still open after the
            // agent exited - that loop ran forever: the move and the restart
            // never happened, and the machine was left with NO agent at all,
            // silent on the fleet until someone walked over to it. Two machines
            // died exactly this way. Now the retry is capped; on success it swaps
            // and launches the new build, and on failure it launches whatever exe
            // is present - so the worst case is "still the old version, still
            // reporting", never "no agent".
            // How the agent comes back after the swap. A SYSTEM daemon must resume as
// the daemon (re-trigger the boot task), not as a tray - a tray launched
// from session 0 has no desktop and dies, leaving the machine silent. A
// user-mode agent resumes as the tray. The caller tells us which we are.
            var restartLine = resumeAsDaemon
                // Re-run the registered task; it carries the daemon's own
                // --url/--token, so nothing has to be reconstructed here.
                ? $"schtasks /Run /TN \"{AgentRunner.SystemTaskNameForRestart}\" >nul 2>&1"
                : "if exist \"%TARGET%\" start \"\" \"%TARGET%\"";

            var statusPath = Path.Combine(currentDir, $"_update_status_{Guid.NewGuid():N}.txt");
            var scriptContent = $@"@echo off
timeout /t 3 /nobreak >nul
set ""TARGET={currentExe}""
set ""NEW={tempNewExe}""
set ""STATUS={statusPath}""
set /a tries=0

:retry
del /f /q ""%TARGET%"" >nul 2>&1
if not exist ""%TARGET%"" goto install
set /a tries+=1
if %tries% geq 30 goto fallback
timeout /t 1 /nobreak >nul
goto retry

:install
move /y ""%NEW%"" ""%TARGET%"" >nul 2>&1
if exist ""%TARGET%"" (echo swapped > ""%STATUS%"") else (echo failed > ""%STATUS%"")
goto launch

:fallback
rem Could not replace the exe. Keep the working one, but record the failure so
rem the caller does NOT ack this update as done while still running the old build.
if exist ""%NEW%"" del /f /q ""%NEW%"" >nul 2>&1
echo fallback > ""%STATUS%""

:launch
{restartLine}
del /f /q ""%~f0"" >nul 2>&1
";
            File.WriteAllText(updateScript, scriptContent, System.Text.Encoding.ASCII);

            var startInfo = new ProcessStartInfo
            {
                FileName = updateScript,
                UseShellExecute = true,
                CreateNoWindow = true,
                WindowStyle = ProcessWindowStyle.Hidden
            };

            Process.Start(startInfo);

            // Wait briefly for the helper to report whether the swap actually
            // happened. The old code acked "done" even when the helper had to
            // fall back and left the previous build in place - which is why the
            // fleet showed a successful update while the laptop kept its old
            // version.
            var status = string.Empty;
            for (var attempt = 0; attempt < 20; attempt++)
            {
                if (File.Exists(statusPath))
                {
                    status = File.ReadAllText(statusPath).Trim();
                    break;
                }
                Thread.Sleep(500);
            }
            try { File.Delete(statusPath); } catch { }

            if (status == "swapped")
            {
                Console.WriteLine("[OK] Update runner swapped the executable successfully.");
                return true;
            }

            Console.Error.WriteLine($"[X] Update swap did not complete (status: '{status}'). Keeping the current build; update reported as failed.");
            return false;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[X] Auto-update failed: {ex.Message}");
            return false;
        }
    }

    /// <summary>
    /// ProductVersion carries a "+git hash" suffix. Compare only the numeric
    /// part. An unreadable version leaves the old behaviour intact rather than
    /// blocking an update on a machine whose exe lacks version metadata.
    /// </summary>
    private static bool IsNewerBuild(string? candidate, string? installed)
    {
        static Version? Parse(string? value)
        {
            if (string.IsNullOrWhiteSpace(value)) return null;

            return Version.TryParse(value.Split('+')[0].Trim(), out var parsed) ? parsed : null;
        }

        var downloaded = Parse(candidate);
        var running = Parse(installed);

        return downloaded is null || running is null || downloaded > running;
    }
}
