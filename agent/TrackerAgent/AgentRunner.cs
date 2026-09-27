using System.Diagnostics;
using System.IO;
using System.Management;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace TrackerAgent;

public static class AgentRunner
{
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(60) };

    private const int AttachParentProcess = -1;

    [DllImport("kernel32.dll")]
    static extern bool AttachConsole(int pid);

    [DllImport("kernel32.dll")]
    static extern bool AllocConsole();

    private const string RunKeyPath = @"Software\Microsoft\Windows\CurrentVersion\Run";

    private const string RunValueName = "MitoAuditTracker";

    public static int Run(string[] args)
    {
        // WinExe has no console - attach to the launching terminal so one-shot
        // results are visible when run from PowerShell/CMD.
        if (!AttachConsole(AttachParentProcess))
        {
            AllocConsole();
        }

        string serverUrl = ResolveDefaultServerUrl();
        string token = Environment.GetEnvironmentVariable("TRACKER_AUDIT_TOKEN") ?? "";
        string? assetId = null;
        bool quiet = false;
        bool daemon = false;
        int pollSeconds = 10;
        bool uninstall = false;
        bool update = false;

        for (int i = 0; i < args.Length; i++)
        {
            switch (args[i].ToLowerInvariant())
            {
                case "--url" or "-u": serverUrl = NextArg(args, ref i); break;
                case "--token" or "-t": token = NextArg(args, ref i); break;
                case "--asset-id" or "-a": assetId = NextArg(args, ref i); break;
                case "--quiet" or "-q": quiet = true; break;
                case "--daemon" or "-d": daemon = true; break;
                case "--poll": pollSeconds = Math.Max(1, int.Parse(NextArg(args, ref i))); break;
                case "--uninstall": uninstall = true; break;
                case "--update": update = true; break;
            }
        }

        if (quiet)
        {
            Console.SetOut(TextWriter.Null);
            Console.SetError(TextWriter.Null);
        }

        if (uninstall)
        {
            Uninstall();
            return 0;
        }

        if (update)
        {
            serverUrl = serverUrl.TrimEnd('/');
            bool updated = AutoUpdater.PerformUpdate(serverUrl, token);
            return updated ? 0 : 1;
        }

        if (string.IsNullOrWhiteSpace(token))
        {
            Console.Error.WriteLine("[X] No token provided.");
            return 1;
        }

        serverUrl = serverUrl.TrimEnd('/');

        if (!daemon)
        {
            return RunOnce(serverUrl, token, assetId);
        }

        var exitCode = RunDaemon(serverUrl, token, assetId, pollSeconds);

        // The server asked us to disappear (fleet page "Uninstall"). The poll
        // loop has already stopped, so nothing can re-register autostart or
        // recreate the folder we are about to delete. The short pause keeps a
        // console window visible for the last messages of the removal.
        if (UninstallRequested)
        {
            Uninstall();
            Thread.Sleep(TimeSpan.FromSeconds(3));
        }

        return exitCode;
    }

    static string NextArg(string[] args, ref int i) =>
        i + 1 < args.Length ? args[++i] : throw new ArgumentException($"Missing value for {args[i - 1]}");
    /// <summary>
    /// Default server address when no --url is given: environment override →
    /// address baked into the downloaded installer (AgentConfigTrailer) →
    /// local development server.
    /// </summary>
    static string ResolveDefaultServerUrl()
    {
        var fromEnvironment = Environment.GetEnvironmentVariable("TRACKER_API_URL");

        if (!string.IsNullOrWhiteSpace(fromEnvironment))
        {
            return fromEnvironment;
        }

        var baked = AgentConfigTrailer.TryRead()?.ServerUrls.FirstOrDefault();

        return string.IsNullOrWhiteSpace(baked) ? "http://127.0.0.1:8000" : baked;
    }

    /// <summary>
    /// Device identity written by the installer (config.json / HKCU registry),
    /// so Live Fleet can tie telemetry to the same device even before a token
    /// has been issued.
    /// </summary>
    static string? TryReadDeviceId()
    {
        try
        {
            using (var key = Registry.CurrentUser.OpenSubKey(@"Software\Mito\AuditTracker"))
            {
                if (key?.GetValue("DeviceId") is string fromRegistry && !string.IsNullOrWhiteSpace(fromRegistry))
                {
                    return fromRegistry;
                }
            }

            var configPath = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER",
                "config.json");

            if (!File.Exists(configPath))
            {
                return null;
            }

            using var doc = JsonDocument.Parse(File.ReadAllText(configPath));

            return doc.RootElement.TryGetProperty("device_id", out var id) && id.ValueKind == JsonValueKind.String
                ? id.GetString()
                : null;
        }
        catch
        {
            return null;
        }
    }


    /// <summary>
    /// Set by the poll loop when the server asked us to remove the agent, so
    /// the caller can run <see cref="Uninstall"/> after the loop has returned.
    /// </summary>
    static bool UninstallRequested { get; set; }

    static int RunOnce(string url, string token, string? assetId = null)
    {
        try
        {
            var payload = HardwareCollector.Collect();
            if (!string.IsNullOrWhiteSpace(assetId))
            {
                payload["asset_id"] = assetId;
            }
            var deviceId = TryReadDeviceId();
            if (!string.IsNullOrWhiteSpace(deviceId))
            {
                payload["device_id"] = deviceId;
            }


            var result = Api.Post(url, token, payload);

            if (result is null)
            {
                Console.Error.WriteLine("[X] Request failed.");
                return 1;
            }

            PrintResult(result.Value);
            return 0;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[X] {ex.Message}");
            return 1;
        }
    }

    /// <summary>
    /// Resident mode: registers the exe for autostart (HKCU Run), then polls
    /// GET /api/agent/inbox every N seconds, executing pending commands and acking them.
    /// </summary>
    static int RunDaemon(string url, string token, string? assetId, int pollSeconds)
    {
        RegisterAutostart(url, token, assetId, pollSeconds);
        Console.WriteLine($"[OK] Daemon started - polling {url} every {pollSeconds}s.");

        while (true)
        {
            try
            {
                var commands = Api.GetInbox(url, token);
                if (commands is not null)
                {
                    foreach (var cmd in commands)
                    {
                        var id = cmd.GetProperty("id").GetInt64();
                        var command = cmd.GetProperty("command").GetString() ?? "";

                        if (command == "scan")
                        {
                            int rc = RunOnce(url, token, assetId);
                            Api.Ack(url, token, id, rc == 0 ? "done" : "failed");
                            Console.WriteLine($"[OK] Command #{id} scan -> {(rc == 0 ? "done" : "failed")}");
                        }
                        else if (command == "update")
                        {
                            Console.WriteLine($"[*] Command #{id} update received. Acknowledging and updating...");
                            Api.Ack(url, token, id, "done");
                            if (AutoUpdater.PerformUpdate(url, token))
                            {
                                Environment.Exit(0);
                            }
                        }
                        else if (command == "uninstall")
                        {
                            // Ack FIRST. Uninstalling takes the process (and the
                            // token) down with it, so an unacked command would
                            // stay 'pending' on the server forever and the fleet
                            // page would keep offering "Uninstall" on a machine
                            // that no longer has an agent.
                            Console.WriteLine($"[*] Command #{id} uninstall received. Acknowledging...");
                            Api.Ack(url, token, id, "done", new Dictionary<string, string>
                            {
                                ["message"] = "Agent removed itself after receiving the uninstall command.",
                            });

                            // Leave the poll loop before touching the disk, so the
                            // sleep below cannot resurrect anything.
                            UninstallRequested = true;
                            return 0;
                        }
                        else
                        {
                            Api.Ack(url, token, id, "done");
                            Console.WriteLine($"[OK] Command #{id} {command} -> done (ack)");
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                Console.Error.WriteLine($"[!] Poll error: {ex.Message}");
            }

            Thread.Sleep(TimeSpan.FromSeconds(pollSeconds));
        }
    }

    static void PrintResult(JsonElement r)
    {
        var result = r.TryGetProperty("result", out var rv) ? rv.GetString() ?? "UNKNOWN" : "UNKNOWN";
        var asset = r.TryGetProperty("asset", out var av) && av.ValueKind != JsonValueKind.Null ? av.GetString() : "N/A";

        Console.ForegroundColor = result switch
        {
            "MATCH" => ConsoleColor.Green,
            "MISMATCH" => ConsoleColor.Yellow,
            _ => ConsoleColor.Magenta
        };
        Console.WriteLine($"\n[RESULT] {result}  (asset: {asset})");

        if (r.TryGetProperty("mismatches", out var mm) && mm.EnumerateObject().Any())
        {
            Console.ForegroundColor = ConsoleColor.Yellow;
            Console.WriteLine("[!] Mismatches:");
            foreach (var m in mm.EnumerateObject())
            {
                var val = m.Value;
                var reg = val.TryGetProperty("registered", out var rv2) ? rv2.GetString() : "?";
                var act = val.TryGetProperty("actual", out var av2) ? av2.GetString() : "?";
                Console.WriteLine($"    {m.Name}: registered \"{reg}\" vs actual \"{act}\"");
            }
        }

        if (r.TryGetProperty("message", out var msg))
        {
            Console.ForegroundColor = ConsoleColor.Cyan;
            Console.WriteLine(msg.GetString());
        }
        Console.ResetColor();
    }

    static void RegisterAutostart(string url, string token, string? assetId, int pollSeconds)
    {
        try
        {
            var exe = Environment.ProcessPath
                ?? Path.Combine(AppContext.BaseDirectory, "TrackerAgent.exe");

            var args = $"--daemon --url \"{url}\" --token \"{token}\" --poll {pollSeconds}";
            if (!string.IsNullOrWhiteSpace(assetId))
            {
                args += $" --asset-id \"{assetId}\"";
            }

            using var key = Registry.CurrentUser.CreateSubKey(RunKeyPath);
            key?.SetValue(RunValueName, $"\"{exe}\" {args}");
            Console.WriteLine("[OK] Registered for autostart (HKCU Run).");
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Autostart registration failed: {ex.Message}");
        }
    }

    /// <summary>
    /// Path of the binary that is actually running.
    ///
    /// <see cref="Environment.ProcessPath"/> is wrong for us: when the agent is
    /// launched as <c>dotnet TrackerAgent.dll</c> (the shape used by the tests and
    /// by the framework-dependent build) it returns the path of <c>dotnet.exe</c>,
    /// not the agent's own dll. Asking the loaded entry assembly gives the file we
    /// really are, which is the one holding the lock on the install folder.
    /// </summary>
    static string SelfBinaryPath()
    {
        try
        {
            var location = System.Reflection.Assembly
                .GetEntryAssembly()?.Location;

            if (!string.IsNullOrWhiteSpace(location) && File.Exists(location))
            {
                return location;
            }
        }
        catch
        {
            // Dynamic or single-file hosts may not expose a location.
        }

        return Environment.ProcessPath ?? string.Empty;
    }

    /// <summary>
    /// Full removal: autostart, device identity and the install folder.
    ///
    /// Order matters. The Run key is deleted FIRST so a reboot in the middle
    /// cannot resurrect the agent; only then do we kill the resident daemon and
    /// drop the folder.
    ///
    /// Self-deletion is the awkward part: this process always holds a file inside
    /// the install folder open (the exe, or the dll under the dotnet host), so
    /// <c>Directory.Delete</c> fails with "being used by another process". We
    /// therefore delete everything we can, then hand our own file to a detached
    /// cmd that removes it (and the folder) once we have exited.
    /// </summary>
    static void Uninstall()
    {
        try
        {
            // 1. Autostart first - nothing may restart the agent after this.
            using (var runKey = Registry.CurrentUser.OpenSubKey(RunKeyPath, true))
            {
                if (runKey?.GetValue(RunValueName) is not null)
                {
                    runKey.DeleteValue(RunValueName, false);
                    Console.WriteLine("[OK] Autostart entry removed.");
                }
            }

            // 2. Device identity (Live Fleet ties telemetry to this).
            using (var key = Registry.CurrentUser.OpenSubKey(@"Software\Mito\AuditTracker", true))
            {
                if (key is not null)
                {
                    foreach (var name in new[] { "DeviceId", "Token", "ServerUrl", "InstalledAt" })
                    {
                        key.DeleteValue(name, false);
                    }
                }
            }
            Console.WriteLine("[OK] Device identity cleared from registry.");

            var installDir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER");

            // 3. Stop the resident daemon (it holds TrackerAgent.exe open).
            var self = Environment.ProcessId;
            var killed = 0;
            foreach (var proc in Process.GetProcessesByName("TrackerAgent"))
            {
                if (proc.Id == self)
                {
                    continue;
                }

                try
                {
                    proc.Kill();
                    proc.WaitForExit(5000);
                    killed++;
                }
                catch
                {
                    // Access denied or already gone - keep going.
                }
                finally
                {
                    proc.Dispose();
                }
            }
            if (killed > 0)
            {
                Console.WriteLine($"[OK] Stopped {killed} running agent process(es).");
            }

            // 4. Folder. Our own binary lives inside it and is locked, so remove
            //    everything else now and let a detached cmd drop the rest.
            if (Directory.Exists(installDir))
            {
                var selfPath = SelfBinaryPath();
                var holdingOurFile = !string.IsNullOrEmpty(selfPath) &&
                    selfPath.StartsWith(installDir, StringComparison.OrdinalIgnoreCase);

                if (holdingOurFile)
                {
                    // Delete the siblings we can; a just-killed daemon can still be
                    // releasing handles, hence the retry.
                    foreach (var file in Directory.EnumerateFiles(installDir))
                    {
                        if (string.Equals(file, selfPath, StringComparison.OrdinalIgnoreCase))
                        {
                            continue;
                        }

                        TryDeleteWithRetry(file);
                    }

                    // Our own file is mapped into this process, so the helper has to
                    // do the last step - and it has to KEEP trying. `del` on a
                    // mapped image only marks it delete-pending: the entry stays in
                    // the directory until the last handle closes, which happens
                    // after this process exits. A single del+rmdir therefore fails
                    // and leaves an empty folder behind for good.
                    LaunchSelfDeleteHelper(installDir, selfPath);

                    Console.WriteLine("[OK] Handed self-deletion to a background helper.");
                    Console.WriteLine("Uninstalled successfully (the install folder disappears in a few seconds).");
                    return;
                }

                // Not running from there: kill leftovers and retry.
                for (var attempt = 0; attempt < 10; attempt++)
                {
                    try
                    {
                        Directory.Delete(installDir, true);
                        break;
                    }
                    catch (IOException) when (attempt < 9)
                    {
                        Thread.Sleep(300);
                    }
                }

                if (Directory.Exists(installDir))
                {
                    throw new IOException(
                        $"Could not delete {installDir} - close any Explorer window showing it and retry.");
                }
            }

            Console.WriteLine("[OK] Install folder removed.");
            Console.WriteLine("Uninstalled successfully.");
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[X] {ex.Message}");
        }
    }

    /// <summary>
    /// Drop a batch file in TEMP that keeps trying to remove the agent's own
    /// file and the install folder, then deletes itself.
    ///
    /// A retry loop is mandatory, not defensive padding: Windows keeps a
    /// delete-pending file visible in its directory until the last handle
    /// closes, so the first rmdir (issued while this process still holds the
    /// mapped image) always fails. The loop keeps knocking until our process
    /// has exited and the folder is really gone.
    ///
    /// The paths are passed as %1/%2 rather than interpolated into the script
    /// so a user name containing &amp;, % or spaces cannot corrupt it. The helper
    /// runs with an explicit working directory because Windows refuses to
    /// remove a folder that is a live process's current directory.
    /// </summary>
    static void LaunchSelfDeleteHelper(string installDir, string selfPath)
    {
        var scriptPath = Path.Combine(
            Path.GetTempPath(),
            $"ta_uninstall_{Guid.NewGuid():N}.cmd");

        File.WriteAllText(scriptPath,
            "@echo off\r\n" +
            "setlocal\r\n" +
            "set \"TARGET=%~1\"\r\n" +
            "set \"SELF=%~2\"\r\n" +
            "for /l %%i in (1,1,40) do (\r\n" +
            "  if exist \"%SELF%\" del /f /q \"%SELF%\" >nul 2>&1\r\n" +
            "  if exist \"%TARGET%\" rmdir /s /q \"%TARGET%\" >nul 2>&1\r\n" +
            "  if not exist \"%TARGET%\" goto :done\r\n" +
            "  ping 127.0.0.1 -n 2 >nul\r\n" +
            ")\r\n" +
            ":done\r\n" +
            "del /f /q \"%~f0\" >nul 2>&1\r\n");

        Process.Start(new ProcessStartInfo
        {
            FileName = "cmd.exe",
            // `call` keeps the quoted script path intact; without it cmd applies
            // its own quote-stripping rule when the path contains a space.
            Arguments = $"/c call \"{scriptPath}\" \"{installDir}\" \"{selfPath}\"",
            WorkingDirectory = Path.GetTempPath(),
            UseShellExecute = false,
            CreateNoWindow = true,
        });
    }

    /// <summary>Delete one file, tolerating a daemon that is still shutting down.</summary>
    static void TryDeleteWithRetry(string path)
    {
        for (var attempt = 0; attempt < 10; attempt++)
        {
            try
            {
                File.Delete(path);
                return;
            }
            catch (IOException) when (attempt < 9)
            {
                Thread.Sleep(300);
            }
            catch (UnauthorizedAccessException) when (attempt < 9)
            {
                Thread.Sleep(300);
            }
        }
    }
}
