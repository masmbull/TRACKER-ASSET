using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;

namespace TrackerAgent;

public static class AutoUpdater
{
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromMinutes(2) };

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

                int read;
                while ((read = stream.Read(buffer, 0, buffer.Length)) > 0)
                {
                    fs.Write(buffer, 0, read);
                    copied += read;

                    if (total > 0 && onProgress != null)
                    {
                        // 5%..85% spans the transfer; the rest is the swap. Only
                        // every ~5% so we are not posting a request per 80 KB.
                        var pct = 5 + (int)(copied * 80 / total);
                        if (pct >= lastReport + 5)
                        {
                            lastReport = pct;
                            onProgress(pct, $"Downloading the new version… ({pct}%)");
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

            var scriptContent = $@"@echo off
timeout /t 3 /nobreak >nul
set ""TARGET={currentExe}""
set ""NEW={tempNewExe}""
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
goto launch

:fallback
rem Could not replace the exe. Leave the working one in place and start it, so
rem the machine keeps its agent rather than going dark.
if exist ""%NEW%"" del /f /q ""%NEW%"" >nul 2>&1

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
            Console.WriteLine("[OK] Update runner spawned. Exiting current process for swap.");
            return true;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[X] Auto-update failed: {ex.Message}");
            return false;
        }
    }
}
