using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;

namespace TrackerAgent;

public static class AutoUpdater
{
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromMinutes(2) };

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
    public static bool PerformUpdate(string serverUrl, string token, string? payloadUrl = null)
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
                stream.CopyTo(fs);
            }

            var fi = new FileInfo(tempNewExe);
            if (fi.Length < 1024 * 50) // sanity check: at least 50KB
            {
                Console.Error.WriteLine("[X] Downloaded file is too small or corrupt. Aborting update.");
                try { File.Delete(tempNewExe); } catch { }
                return false;
            }

            Console.WriteLine($"[OK] Downloaded new version ({fi.Length / 1024 / 1024:N1} MB). Preparing swap...");

            // Pass the original command line args to restart identically
            var args = Environment.GetCommandLineArgs();
            var forwardArgs = args.Length > 1
                ? string.Join(" ", args.Skip(1).Select(a => a.Contains(' ') ? $"\"{a}\"" : a))
                : "";

            // Create batch script that waits for current process to exit, swaps exe, and restarts it
            var scriptContent = $@"@echo off
timeout /t 2 /nobreak >nul
:loop
del /f /q ""{currentExe}"" >nul 2>&1
if exist ""{currentExe}"" (
    timeout /t 1 /nobreak >nul
    goto loop
)
move /y ""{tempNewExe}"" ""{currentExe}"" >nul
start """" ""{currentExe}"" {forwardArgs}
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
