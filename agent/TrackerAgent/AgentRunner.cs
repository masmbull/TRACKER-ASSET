using System.Diagnostics;
using System.IO;
using System.Management;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace TrackerAgent;

public static class AgentRunner
{
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(60) };

    private const int AttachParentProcess = -1;

    /// <summary>
    /// How often the daemon pushes a telemetry ping while idle (PRD Â§11).
    /// </summary>
    /// <remarks>
    /// 60 seconds against a 2-minute online window on the server gives exactly
    /// one missed ping of slack before a machine flips to offline. The inbox poll
    /// (default 10s) is much faster but only proves the agent can reach the
    /// server; the heartbeat is what refreshes the fleet readings themselves.
    /// </remarks>
    // Deep inventory cadence. 24h is the trade-off between an inventory that
    // reflects reality and a registry + WMI walk per device every hour: the
    // software list changes on a scale of weeks, and the security posture only
    // matters when it changes.
    private const int FingerprintSeconds = 24 * 60 * 60;

    private const int HeartbeatSeconds = 60;

    /// <summary>
    /// How long the inbox request is parked waiting for a command.
    /// </summary>
    /// <remarks>
    /// The agent used to ask for commands every 10 seconds and sleep between
    /// tries, which cost every laptop in the office six requests a minute to be
    /// told "nothing to do". Parking the request instead makes an idle machine
    /// genuinely idle - no bytes, no wakeups - while a click in the browser still
    /// arrives immediately, because the server returns the moment a command is
    /// queued rather than at the end of an interval.
    ///
/// 55 rather than 60: the server clamps its own wait to 55 s, so a client
        /// asking for exactly that would race its own timeout, and the failure mode
        /// of losing that race is an exception every minute on every laptop. Staying
        /// under the cap means the server always returns first, cleanly.
    ///
    /// The cost is one php-fpm worker per parked agent, which is why pm.max_children
    /// was raised from its default of 5 in the Dockerfile. At the default, six
    /// laptops online would have taken every worker and taken the site down.
    /// </remarks>
    internal const int LongPollSeconds = 55;

    [DllImport("kernel32.dll")]
    static extern bool AttachConsole(int pid);

    /// <summary>
    /// Attach to the launching terminal if there is one, and report whether this
    /// process is interactive.
    /// </summary>
    static bool TryAttachConsole()
    {
        try
        {
            // Autostart through the Run key has no parent terminal, so this returns
            // false there. That is the case that must stay silent rather than
            // conjure a window - hence no AllocConsole fallback.
            return AttachConsole(AttachParentProcess);
        }
        catch
        {
            // AttachConsole can fail for reasons other than "no parent" on a
            // locked-down machine; either way there is no console to write to.
            return false;
        }
    }

    /// <summary>
    /// Discard output when there is nowhere to send it.
    /// </summary>
    /// <remarks>
    /// Without this, every Console.WriteLine from the poll loop throws or is
    /// silently dropped, and Console.ForegroundColor throws outright. Redirecting
    /// to null is what makes the headless path safe.
    /// </remarks>
    static void SilenceConsole()
    {
        Console.SetOut(TextWriter.Null);
        Console.SetError(TextWriter.Null);
    }

    private const string RunKeyPath = @"Software\Microsoft\Windows\CurrentVersion\Run";

    private const string RunValueName = "MitoAuditTracker";

    public static int Run(string[] args)
    {
        // WinExe has no console of its own. One-shot output is still worth seeing
        // when someone runs this from PowerShell or CMD, so the parent terminal is
        // adopted for that case.
        //
        // What it must never do is *create* a console. Autostart launches this
        // binary through the Run key with no parent terminal attached, and
        // AllocConsole() there opened a black window on every single boot - which
        // then filled with "Poll error" lines whenever the server was not
        // reachable, making a background agent look like a broken one. Only
        // attach to a terminal that already exists; create nothing.
        var interactive = TryAttachConsole();

        string serverUrl = ResolveDefaultServerUrl();
        string token = Environment.GetEnvironmentVariable("TRACKER_AUDIT_TOKEN") ?? "";
        string? assetId = null;
        bool quiet = false;
        bool daemon = false;
        int pollSeconds = 10;
        bool uninstall = false;
        bool update = false;
        bool installService = false;
        bool removeService = false;
        bool guard = false;
        string? targetDir = null;
        int? parentProcessId = null;

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
                case "--install-service": installService = true; break;
                case "--remove-service": removeService = true; break;
                case "--guard": guard = true; break;
                case "--target-dir": targetDir = NextArg(args, ref i); break;
                case "--parent-pid": parentProcessId = int.Parse(NextArg(args, ref i)); break;
            }
        }

        // Silence when there is no terminal to write to (the autostart case), then
        // mirror whatever the loop prints into agent.log. Autostart runs with
        // --quiet and no console at all, so before this the only record of a
        // daemon that 401ed on every poll - or died at startup, as the
        // 0xc00000fd build did - was a Windows Error Reporting entry nobody reads.
        // A silent agent and a broken one looked exactly alike.
        //
        // Order matters: MirrorConsole wraps the *current* writers, so silencing
        // first means the file still gets every line while the terminal gets
        // none. Mirroring first would wrap the real console and the log would
        // then be handed to TextWriter.Null by SilenceConsole.
        if (quiet || !interactive)
        {
            SilenceConsole();
        }

        AgentLog.MirrorConsole();

        if (uninstall)
        {
            Uninstall();
            return 0;
        }

        // Branding self-test: proves the tray icon really resolves to the embedded
        // MITO mark instead of silently falling back to SystemIcons.Information.
        // A wrong pack URI fails quietly at runtime and the only symptom is a
        // generic blue "i" in the notification area - which this rules out.
        if (args.Any(a => a.Equals("--self-test-icon", StringComparison.OrdinalIgnoreCase)))
        {
            using var icon = MitoIcon.Create();

            if (icon is null)
            {
                Console.Error.WriteLine("[X] MITO icon could not be loaded (embedded resource missing).");

                return 1;
            }

            Console.WriteLine($"OK: tray icon {icon.Width}x{icon.Height} loaded from the embedded mark.");

            return 0;
        }

        if (args.Any(a => a.Equals("--status", StringComparison.OrdinalIgnoreCase)))
        {
            return PrintStatus();
        }

        // Install the daemon as a one-shot SYSTEM scheduled task, so the agent
        // runs with machine rights and a pushed `run_as: system` script can write
        // HKLM. This is the whole point of the flag: the tray runs as the logged
        // in user, and a standard user cannot create the SYSTEM task that
        // RunScriptElevated needs - so the elevation has to be arranged once, at
        // install time, by an administrator.
        //
        // Reached from InstallerWindow after the user grants UAC. Refuses when it
        // is not elevated, because schtasks /RU SYSTEM is itself an admin action
        // and a silent failure here would leave the machine looking installed
        // while every system-level script kept dying.
        if (installService)
        {
            var result = InstallSystemTask(serverUrl, token, assetId, pollSeconds, parentProcessId);

            // The SYSTEM daemon and the self-heal guard are installed together:
            // both need the one elevation the user just granted, and a machine
            // with the daemon but no guard is exactly the one that goes dark when
            // someone deletes the folder. A guard failure does not fail the
            // install - the agent still works without it.
            if (result == 0)
            {
                InstallGuard(pollSeconds);
            }

            return result;
        }

        if (guard)
        {
            return RunGuard(targetDir);
        }

        if (removeService)
        {
            return RemoveSystemTask();
        }

        if (update)
        {
            serverUrl = serverUrl.TrimEnd('/');
            bool updated = AutoUpdater.PerformUpdate(serverUrl, token);
            return updated ? 0 : 1;
        }

        // Last resort before giving up: the credential the installer already put
        // on this machine. The daemon used to read a token from the command line
        // or the environment only, so any launch that did not repeat the original
        // arguments exited with "No token provided." while a perfectly good
        // token sat in config.json. Autostart is exactly that case after a server
        // database reset: it replays the old, now dead, token from the Run key.
        if (string.IsNullOrWhiteSpace(token))
        {
            token = TryReadStoredToken() ?? string.Empty;
        }

        // Still nothing (a first run with no installer config): mint one. Same
        // endpoint the installer uses, minus the UI, and persisted so the next
        // launch has something to fall back to.
        if (string.IsNullOrWhiteSpace(token) && TryReadDeviceId() is { Length: > 0 } deviceId)
        {
            var issued = Api.Register(serverUrl.TrimEnd('/'), deviceId, Environment.MachineName)
                ?? Api.Register(ResolveDefaultServerUrl(), deviceId, Environment.MachineName);

            if (!string.IsNullOrWhiteSpace(issued))
            {
                token = issued;
                PersistToken(issued);
            }
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
    /// Exposes the CLI-flag decision to the offline harness.
    ///
    /// The real list lives in App.OnStartup (a WPF type the harness cannot load
    /// without a message pump), so this mirrors it for the one question the
    /// harness asks: is this argument a CLI mode rather than a GUI launch? A mode
    /// added to one list and not the other is a mode that silently stops being
    /// honoured, so the two are kept in step by hand.
    /// </summary>
    internal static bool IsCliFlagForTest(string arg) => arg.ToLowerInvariant() switch
    {
        "--url" or "-u" or "--token" or "-t" or "--asset-id" or "-a" or
        "--quiet" or "-q" or "--daemon" or "-d" or "--poll" or "--uninstall" or "--update"
            or "--status" or "--self-test-icon" or "--install-service" or "--remove-service" or "--guard"
            or "--parent-pid" => true,
        _ => false,
    };
    /// <summary>
    /// Default server address when no --url is given: environment override â†’
    /// address baked into the downloaded installer (AgentConfigTrailer) â†’
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
    /// Credential the installer (or a previous heal) already stored on this
    /// machine: HKCU registry first, then config.json.
    /// </summary>
    static string? TryReadStoredToken()
    {
        try
        {
            using (var key = Registry.CurrentUser.OpenSubKey(@"Software\Mito\AuditTracker"))
            {
                if (key?.GetValue("Token") is string fromRegistry && !string.IsNullOrWhiteSpace(fromRegistry))
                {
                    return fromRegistry;
                }
            }
        }
        catch
        {
            // Fall through to config.json.
        }

        return ReadTokenFromConfig(Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "TRACKER",
            "config.json"));
    }


    /// <summary>
    /// The token out of an installer-written config.json, or null when the file
    /// is missing, unreadable, or carries no string "token".
    /// </summary>
    /// <remarks>
    /// Split out from the lookup above so the parse - the half that regressed,
    /// since on a wiped server the daemon had no other credential left - is
    /// exercisable without reading HKCU or the real config.json. See
    /// agent/TrackerAgent.Tests.
    /// </remarks>
    internal static string? ReadTokenFromConfig(string configPath)
    {
        try
        {
            if (!File.Exists(configPath))
            {
                return null;
            }

            using var doc = JsonDocument.Parse(File.ReadAllText(configPath));

            return doc.RootElement.TryGetProperty("token", out var token) && token.ValueKind == JsonValueKind.String
                ? token.GetString()
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

    internal static int RunFullScan(string url, string token, string? assetId = null, long? commandId = null)
    {
        try
        {
            // A scan driven by a fleet command reports where it has got to, so the
            // operator watching the modal sees "Uploading auditâ€¦" rather than a
            // spinner that says nothing for a minute. A heartbeat-driven scan has
            // no command id and stays silent. Best-effort throughout: a lost
            // progress ping must never abort real collection work.
            ReportScanProgress(url, token, commandId, 10, "Collecting hardwareâ€¦");

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

            ReportScanProgress(url, token, commandId, 40, "Uploading auditâ€¦");

            var result = Api.Post(url, token, payload);

            if (result is null)
            {
                Console.Error.WriteLine("[X] Request failed.");
                return 1;
            }

            PrintResult(result.Value);

            // The audit and the deep inventory are complementary halves of the
            // same report: the audit identifies and compares the hardware, the
            // fingerprint answers "what is installed and how safe is it". A
            // full scan is the natural moment to refresh the second one, since
            // the user is already waiting and the registry walk costs seconds.
            //
            ReportScanProgress(url, token, commandId, 65, "Collecting deep inventoryâ€¦");

            if (!SendFingerprint(url, token, commandId))
            {
                return 1;
            }

            ReportScanProgress(url, token, commandId, 95, "Finishingâ€¦");

            return 0;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[X] {ex.Message}");
            return 1;
        }
    }

    /// <summary>Report scan progress only when a command asked for this scan.</summary>
    private static void ReportScanProgress(string url, string token, long? commandId, int percent, string stage)
    {
        if (commandId is null)
        {
            return;
        }

        Api.ReportProgress(url, token, commandId.Value, percent, stage);
    }

    static int RunOnce(string url, string token, string? assetId = null, long? commandId = null)
        => RunFullScan(url, token, assetId, commandId);

    internal static int RunScriptCommand(string url, string token, long commandId, JsonElement command)
    {
        try
        {
            if (!command.TryGetProperty("payload", out var payload) || payload.ValueKind != JsonValueKind.Object)
            {
                Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
                {
                    ["message"] = "Script command payload was empty or malformed.",
                });
                return 1;
            }

            var scriptName = payload.TryGetProperty("script_name", out var nameEl) && nameEl.ValueKind == JsonValueKind.String
                ? nameEl.GetString() ?? "tracker-script"
                : "tracker-script";
            var scriptType = payload.TryGetProperty("script_type", out var typeEl) && typeEl.ValueKind == JsonValueKind.String
                ? typeEl.GetString() ?? "powershell"
                : "powershell";
            var scriptBody = payload.TryGetProperty("script_body", out var bodyEl) && bodyEl.ValueKind == JsonValueKind.String
                ? bodyEl.GetString() ?? string.Empty
                : string.Empty;
            var workingDirectory = payload.TryGetProperty("working_directory", out var dirEl) && dirEl.ValueKind == JsonValueKind.String
                ? dirEl.GetString()
                : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "TrackerAgent", "Scripts");
            var timeoutSeconds = payload.TryGetProperty("timeout_seconds", out var timeoutEl) && timeoutEl.ValueKind == JsonValueKind.Number
                ? (int)Math.Clamp(timeoutEl.GetInt32(), 10, 3600)
                : 300;

            if (string.IsNullOrWhiteSpace(scriptBody))
            {
                Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
                {
                    ["message"] = "The script body was empty.",
                });
                return 1;
            }

            var safeDirectory = workingDirectory is null || string.IsNullOrWhiteSpace(workingDirectory)
                ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "TrackerAgent", "Scripts")
                : workingDirectory;

            Directory.CreateDirectory(safeDirectory);

            var extension = scriptType switch
            {
                "ps1" or "powershell" => ".ps1",
                "bat" or "batch" or "cmd" => ".bat",
                "exe" => ".exe",
                _ => ".cmd",
            };

            var normalizedName = scriptName.EndsWith(extension, StringComparison.OrdinalIgnoreCase)
                ? scriptName
                : scriptName + extension;
            var scriptPath = Path.Combine(safeDirectory, normalizedName);

            File.WriteAllText(scriptPath, scriptBody);

            if (payload.TryGetProperty("sha256", out var hashValue) && hashValue.ValueKind == JsonValueKind.String)
            {
                var expectedHash = (hashValue.GetString() ?? string.Empty).Trim();
                if (!string.IsNullOrWhiteSpace(expectedHash))
                {
                    using var stream = File.OpenRead(scriptPath);
                    using var sha256 = SHA256.Create();
                    var actualHash = Convert.ToHexString(sha256.ComputeHash(stream));
                    if (!string.Equals(actualHash, expectedHash, StringComparison.OrdinalIgnoreCase))
                    {
                        Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
                        {
                            ["message"] = "SHA-256 mismatch. The script payload was modified before execution.",
                        });

                        return 1;
                    }
                }
            }

            if (scriptType == "exe")
            {
                Api.ReportProgress(url, token, commandId, 25, "Running executable...");
                using var process = Process.Start(new ProcessStartInfo
                {
                    FileName = scriptPath,
                    WorkingDirectory = safeDirectory,
                    UseShellExecute = true,
                });

                if (process is null)
                {
                    Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
                    {
                        ["message"] = "The executable could not be started.",
                    });
                    return 1;
                }

                var exitCode = process.WaitForExit(timeoutSeconds * 1000) ? process.ExitCode : 1;
                Api.Ack(url, token, commandId, exitCode == 0 ? "done" : "failed", new Dictionary<string, string>
                {
                    ["message"] = exitCode == 0 ? "Script completed successfully." : "The script timed out or exited with an error.",
                });

                return exitCode;
            }

            var startInfo = scriptType == "powershell" || scriptType == "ps1"
                ? new ProcessStartInfo
                {
                    FileName = "powershell.exe",
                    Arguments = $"-NoProfile -ExecutionPolicy Bypass -NonInteractive -File \"{scriptPath}\"",
                    WorkingDirectory = safeDirectory,
                    UseShellExecute = false,
                }
                : new ProcessStartInfo
                {
                    FileName = "cmd.exe",
                    Arguments = $"/c \"\"{scriptPath}\"\"",
                    WorkingDirectory = safeDirectory,
                    UseShellExecute = false,
                };

            Api.ReportProgress(url, token, commandId, 25, "Running script...");

            // A script that changes machine-wide settings needs elevation; the
            // agent runs as the logged-in user and a plain `cmd /c` cannot write
            // HKLM. run_as was accepted by the API and shown in the form but
            // ignored here, so an endpoint-policy script failed with an access
            // denied the operator never saw. admin/system now route the script
            // through a one-shot scheduled task with highest privileges.
            var runAs = payload.TryGetProperty("run_as", out var runAsEl) && runAsEl.ValueKind == JsonValueKind.String
                ? runAsEl.GetString() ?? "current-user"
                : "current-user";

            if (runAs is "admin" or "system")
            {
                return RunScriptElevated(scriptPath, scriptType, safeDirectory, commandId, timeoutSeconds, url, token);
            }

            using var launched = Process.Start(startInfo);
            if (launched is null)
            {
                Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
                {
                    ["message"] = "The script could not be launched.",
                });
                return 1;
            }

            var exitCodeFinal = launched.WaitForExit(timeoutSeconds * 1000) ? launched.ExitCode : 1;

            Api.Ack(url, token, commandId, exitCodeFinal == 0 ? "done" : "failed", new Dictionary<string, string>
            {
                ["message"] = exitCodeFinal == 0 ? "Script completed successfully." : "The script timed out or exited with an error.",
            });

            return exitCodeFinal;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Script execution failed: {ex.Message}");
            Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
            {
                ["message"] = $"The script execution failed: {ex.Message}",
            });
            return 1;
        }
    }

    /// <summary>
    /// Run a script with machine-wide privileges, for run_as admin/system.
    ///
    /// The tray agent runs as the logged-in user, so a `reg add HKLM\...` or a
    /// service change fails with access denied. Elevating the already-running
    /// process is not possible without a UAC prompt nobody is there to answer, so
    /// the work is handed to a one-shot scheduled task owned by SYSTEM (the same
    /// mechanism Windows uses for unattended tasks): it is created with highest
    /// privileges, run once, waited on, and deleted. A wrapper writes the inner
    /// script's exit code to a file the task itself can then be read from, since
    /// schtasks does not surface the exit code of what it ran.
    /// </summary>
    private static int RunScriptElevated(
        string scriptPath,
        string scriptType,
        string directory,
        long commandId,
        int timeoutSeconds,
        string url,
        string token)
    {
        var taskName = $"TrackerAgent-Script-{commandId}";
        var markerPath = Path.Combine(directory, $"tracker-script-{commandId}.exit");
        var wrapperPath = Path.Combine(directory, $"tracker-script-{commandId}.wrapper.cmd");

        try
        {
            if (File.Exists(markerPath))
            {
                File.Delete(markerPath);
            }

            // If this process already runs with machine rights (the tray was
            // launched elevated, or the agent runs as a service), there is nothing
            // to hand off - run the script directly. Creating a scheduled task
            // would itself need the very rights we already have, so this is also
            // the only path that works for a SYSTEM-installed agent.
            if (IsElevated())
            {
                return RunDirect(scriptPath, scriptType, directory, commandId, timeoutSeconds, url, token);
            }

            // The wrapper runs the real script and records its errorlevel, because
            // a scheduled task reports only its own success, never the exit code of
            // the program it launched - so without this every elevated script would
            // look like it succeeded.
            var inner = scriptType is "powershell" or "ps1"
                ? $"powershell.exe -NoProfile -ExecutionPolicy Bypass -NonInteractive -File \"{scriptPath}\""
                : $"call \"{scriptPath}\"";

            File.WriteAllText(wrapperPath,
                "@echo off\r\n" +
                inner + "\r\n" +
                $"echo %errorlevel% > \"{markerPath}\"\r\n");

            Api.ReportProgress(url, token, commandId, 40, "Running with elevated privileges...");

            // Bounded, not timeoutSeconds. `schtasks /Create /RU SYSTEM` from a
            // standard-user agent is an access-denied that should come back in a
            // second, but it can also sit waiting - and waiting up to the script's
            // own 300 s timeout pinned the command at 40% for minutes with no
            // explanation. Twenty seconds is far longer than a create ever takes
            // on success, so anything past it is the failure we are about to
            // handle anyway.
            if (!Run("schtasks", $"/Create /TN \"{taskName}\" /TR \"cmd.exe /c \\\"{wrapperPath}\\\"\" /SC ONCE /ST 00:00 /RL HIGHEST /RU SYSTEM /F", 20))
            {
                // Creating a SYSTEM task is an administrative action, so a
                // standard-user agent cannot do it. Settle the command NOW, before
                // asking for elevation: Process.Start(runas) blocks until the
                // prompt is answered, and acking after it left the command
                // in_progress for that whole wait - the progress bar sat at 40%
                // for minutes with no heartbeat underneath it either, because the
                // elevation ran on the command loop. The request itself is
                // fire-and-forget by design (and runs off the loop for the same
                // reason): the script runs on the next poll, once the SYSTEM
                // task exists.
                if (CanPromote())
                {
                    Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
                    {
                        ["message"] = "Requested administrator approval to run scripts as SYSTEM. "
                            + "Approve the Windows prompt on the machine; the script runs automatically on the next poll.",
                    });

                    _ = Task.Run(() => PromoteToSystemService(url, token));

                    return 1;
                }

                // The honest failure: either the agent runs as a plain user and
                // cannot perform an administrative action, or it is already
                // elevated and Windows still refused the task. Say what to do
                // rather than "not enough rights", which reads like a bug in the
                // click - and do not promise a prompt that will never appear.
                Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
                {
                    ["message"] = IsElevated()
                        ? "Windows refused to create the SYSTEM task even though this agent is already elevated. "
                            + "Check the Task Scheduler service on the machine, then push the script again."
                        : "This script needs administrator rights, but the agent is running as a standard user. "
                            + "Reinstall or start the agent as an administrator (or as a service) on this machine, then push the script again.",
                });
                return 1;
            }

            if (!Run("schtasks", $"/Run /TN \"{taskName}\"", timeoutSeconds))
            {
                Run("schtasks", $"/Delete /TN \"{taskName}\" /F", 30);
                Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
                {
                    ["message"] = "The elevated task could not be started.",
                });
                return 1;
            }

            // Wait for the wrapper to write its marker: the task itself can read as
            // "Ready" before the script inside it has finished.
            var deadline = DateTime.UtcNow.AddSeconds(timeoutSeconds);
            while (!File.Exists(markerPath) && DateTime.UtcNow < deadline)
            {
                Thread.Sleep(500);
            }

            Run("schtasks", $"/Delete /TN \"{taskName}\" /F", 30);

            if (!File.Exists(markerPath))
            {
                Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
                {
                    ["message"] = "The elevated script timed out or left no result.",
                });
                return 1;
            }

            var raw = File.ReadAllText(markerPath).Trim();
            var exitCode = int.TryParse(raw, out var parsed) ? parsed : 1;

            Api.Ack(url, token, commandId, exitCode == 0 ? "done" : "failed", new Dictionary<string, string>
            {
                ["message"] = exitCode == 0
                    ? "Script completed successfully (elevated)."
                    : $"The elevated script exited with code {exitCode}.",
            });

            return exitCode;
        }
        catch (Exception ex)
        {
            Api.Ack(url, token, commandId, "failed", new Dictionary<string, string>
            {
                ["message"] = $"The elevated script failed: {ex.Message}",
            });
            return 1;
        }
        finally
        {
            try { if (File.Exists(markerPath)) File.Delete(markerPath); } catch { }
            try { if (File.Exists(wrapperPath)) File.Delete(wrapperPath); } catch { }
            try { Run("schtasks", $"/Delete /TN \"{taskName}\" /F", 15); } catch { }
        }
    }

    /// <summary>True when the process token is a member of the Administrators group.</summary>
    private static bool IsElevated()
    {
        try
        {
            using var identity = System.Security.Principal.WindowsIdentity.GetCurrent();
            var principal = new System.Security.Principal.WindowsPrincipal(identity);

            return principal.IsInRole(System.Security.Principal.WindowsBuiltInRole.Administrator);
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// Whether the running agent can act as an administrator, for the fleet to
    /// read. Public so the collector can report it with the rest of telemetry.
    /// </summary>
    public static bool IsProcessElevated() => IsElevated();

    /// <summary>Heartbeat file the SYSTEM daemon touches so a tray can see it is alive.</summary>
    private static string DaemonMarker =>
        Path.Combine(ProtectedDir, "daemon.alive");

    /// <summary>
    /// Touch the daemon marker. Called by the SYSTEM daemon each loop so a user
    /// tray (which cannot see a SYSTEM process or a cross-session mutex) can tell
    /// the daemon owns the machine and stay down.
    /// </summary>
    public static void TouchDaemonMarker()
    {
        try
        {
            Directory.CreateDirectory(ProtectedDir);
            File.WriteAllText(DaemonMarker, DateTimeOffset.UtcNow.ToString("o"));
        }
        catch
        {
            // Marker is best-effort; a failure just means a tray may not defer.
        }
    }

    /// <summary>
    /// True when a SYSTEM daemon touched its marker recently (within three
    /// heartbeats). A stale marker means the daemon is gone, so a tray may run.
    /// </summary>
    public static bool IsSystemDaemonAlive()
    {
        try
        {
            if (!File.Exists(DaemonMarker))
            {
                return false;
            }

            return DateTime.UtcNow - File.GetLastWriteTimeUtc(DaemonMarker) < TimeSpan.FromMinutes(3);
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// Whether a UAC request from this process is still worth showing: not
    /// elevated already, and an installed binary to relaunch.
    /// </summary>
    /// <remarks>
    /// Shared with the script path, which has to know whether a prompt will
    /// actually appear before it settles its command - the request itself blocks
    /// until somebody answers that prompt, so it cannot be used to find out.
    /// </remarks>
    private static bool CanPromote()
    {
        if (IsElevated())
        {
            return false;
        }

        var exe = SelfBinaryPath();

        return !string.IsNullOrWhiteSpace(exe) && File.Exists(exe);
    }

    /// <summary>
    /// Ask Windows to relaunch this binary elevated and register the boot-time
    /// SYSTEM task, so machine-level scripts stop needing a human.
    /// </summary>
    /// <remarks>
    /// Windows will not elevate a process silently: the UAC consent dialog needs
    /// someone at the machine. The launch therefore runs on a dedicated
    /// background thread and this method joins it, so it blocks its caller for
    /// as long as that human takes - first for the prompt, then until the
    /// elevated child has registered the task. No caller may run it on a command
    /// loop: a blocked loop stops the heartbeats the fleet page reads as proof
    /// of life, so both callers schedule it off-loop and report progress while
    /// it runs. A UAC the user cancels throws (Win32Exception 1223); that is a
    /// normal outcome, reported as false - "needs admin", not a crash.
    ///
    /// Only the installed exe is relaunched, and only with --install-service, so
    /// the elevated child does one bounded thing (create the task, start the
    /// daemon) and exits. It is handed this process's id as --parent-pid so its
    /// KillUserTrays spares the caller long enough to settle its own command.
    ///
    /// Public because both the script path (a run_as:system push that hit a
    /// standard-user agent) and the standalone `elevate` command reach for it.
    /// </remarks>
    public static bool PromoteToSystemService(string url, string token)
    {
        if (!CanPromote())
        {
            // Already elevated: schtasks would have worked, so the failure was
            // something else. Do not pop a pointless UAC prompt.
            return false;
        }

        var exe = SelfBinaryPath();
        if (string.IsNullOrWhiteSpace(exe) || !File.Exists(exe))
        {
            return false;
        }

        // The UAC dialog is a shell UI and Process.Start(runas) does not return
        // until somebody answers it, so the wait belongs on a thread of its own:
        // neither the WPF UI thread (the tray would freeze while somebody walks
        // over to the laptop) nor a headless daemon, which has no UI thread to
        // offer anyway, may sit inside that call.
        var succeeded = false;

        void Launch()
        {
            try
            {
                var psi = new ProcessStartInfo
                {
                    FileName = exe,
                    Arguments = $"--install-service --quiet --url \"{url}\" --token \"{token}\" --parent-pid {Environment.ProcessId}",
                    UseShellExecute = true,
                    Verb = "runas",
                };

                // Keep the tray alive through service installation so it can ACK
                // this command before the SYSTEM daemon takes over polling.
                using var process = Process.Start(psi);
                process?.WaitForExit();
                succeeded = process?.ExitCode == 0;
            }
            catch (Exception ex)
            {
                succeeded = false;

                // Win32Exception 1223 is "the user declined"; no interactive
                // session is the other common case.
                AgentLog.Write($"UAC promote attempt failed: {ex.GetType().Name}: {ex.Message}");
            }
        }

        // Off the UI thread deliberately: Process.Start with runas waits for
        // the user to answer the consent dialog.
        var worker = new Thread(Launch) { IsBackground = true };
        worker.Start();
        worker.Join();

        return succeeded;
    }

    /// <summary>Run a script in this (already elevated) process and report its exit code.</summary>
    private static int RunDirect(
        string scriptPath,
        string scriptType,
        string directory,
        long commandId,
        int timeoutSeconds,
        string url,
        string token)
    {
        var startInfo = scriptType is "powershell" or "ps1"
            ? new ProcessStartInfo
            {
                FileName = "powershell.exe",
                Arguments = $"-NoProfile -ExecutionPolicy Bypass -NonInteractive -File \"{scriptPath}\"",
                WorkingDirectory = directory,
                UseShellExecute = false,
            }
            : new ProcessStartInfo
            {
                FileName = "cmd.exe",
                Arguments = $"/c \"\"{scriptPath}\"\"",
                WorkingDirectory = directory,
                UseShellExecute = false,
            };

        Api.ReportProgress(url, token, commandId, 40, "Running script (elevated)â€¦");

        using var process = Process.Start(startInfo);

        if (process is null)
        {
            Api.Ack(url, token, commandId, "failed", new Dictionary<string, string> { ["message"] = "The script could not be launched." });

            return 1;
        }

        var exitCode = process.WaitForExit(timeoutSeconds * 1000) ? process.ExitCode : 1;

        Api.Ack(url, token, commandId, exitCode == 0 ? "done" : "failed", new Dictionary<string, string>
        {
            ["message"] = exitCode == 0 ? "Script completed successfully (elevated)." : $"The elevated script exited with code {exitCode}.",
        });

        return exitCode;
    }

    /// <summary>Run a small helper process to completion; returns true on exit code 0.</summary>
    private static bool Run(string file, string arguments, int timeoutSeconds)
    {
        try
        {
            using var process = Process.Start(new ProcessStartInfo
            {
                FileName = file,
                Arguments = arguments,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            });

            if (process is null)
            {
                return false;
            }

            process.WaitForExit(Math.Max(5, timeoutSeconds) * 1000);

            return process.HasExited && process.ExitCode == 0;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// Credential self-heal: the server answered 401, so the token we are
    /// holding is dead (it was rotated, revoked-then-restored, or issued by a
    /// server whose database was reset). Re-register the device, then persist
    /// the new token everywhere the daemon reads it from on the next start.
    /// </summary>
    /// <remarks>
    /// Persisting is the part that matters. Without it the agent would heal in
    /// memory and then silently go stale again on the next reboot, which looked
    /// exactly like "the tray is running but the web page never updates".
    /// </remarks>
    /// <returns>
    /// True when a fresh token was obtained and written; <paramref name="healed"/>
    /// tells the caller whether anything actually changed.
    /// </returns>
    static bool TryHealToken(string url, ref string token, out bool healed)
    {
        healed = false;

        var deviceId = TryReadDeviceId();
        if (string.IsNullOrWhiteSpace(deviceId))
        {
            Console.Error.WriteLine("[!] No device id on this machine; cannot re-register.");
            return false;
        }

        var issued = Api.Register(url, deviceId, Environment.MachineName);
        if (string.IsNullOrWhiteSpace(issued))
        {
            return false;
        }

        Console.WriteLine("[OK] Token was rejected - re-registered and obtained a new one.");

        token = issued;
        PersistToken(issued);
        healed = true;
        return true;
    }

    /// <summary>
    /// Mirror a freshly issued token into config.json and the HKCU registry so
    /// the next launch (autostart, manual run, or the installer) starts with the
    /// same working credential. Best-effort: a failure here only costs us the
    /// heal, never the running daemon.
    /// </summary>
    static void PersistToken(string token)
    {
        try
        {
            using (var key = Registry.CurrentUser.CreateSubKey(@"Software\Mito\AuditTracker"))
            {
                key?.SetValue("Token", token);
            }
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Could not update registry token: {ex.Message}");
        }

        try
        {
            var configPath = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER",
                "config.json");

            if (!File.Exists(configPath))
            {
                return;
            }

            // Read-modify-write as plain text: the config is a small flat object
            // whose key order is not meaningful, and this keeps the file
            // human-readable the way the installer writes it.
            //
            // A MatchEvaluator is used deliberately instead of a replacement
            // string: Regex treats "$1" in a replacement as a group reference,
            // and a token that starts with a digit turns "$1" + token into a
            // reference to a group that does not exist ("$156..."), which
            // silently deleted the "token": " prefix and left the file corrupt.
            var json = File.ReadAllText(configPath);
            var updated = System.Text.RegularExpressions.Regex.Replace(
                json,
                "(\"token\"\\s*:\\s*\")[^\"]*(\")",
                match => match.Groups[1].Value + token + match.Groups[2].Value,
                System.Text.RegularExpressions.RegexOptions.None,
                TimeSpan.FromSeconds(2));

            if (updated != json)
            {
                File.WriteAllText(configPath, updated);
            }
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Could not update config.json: {ex.Message}");
        }
    }

    /// <summary>
    /// Resident mode: registers the exe for autostart (HKCU Run), then parks on
    /// GET /api/agent/inbox waiting for a command, executing and acking whatever
    /// arrives.
    /// </summary>
    static int RunDaemon(string url, string token, string? assetId, int pollSeconds)
    {
        RegisterAutostart();
        Console.WriteLine($"[OK] Daemon started - waiting on {url} for commands.");

        // Telemetry heartbeat. The inbox only carries commands, so a device that
        // nobody ever clicks "Scan Now" on would keep the same snapshot forever
        // and Live Fleet would show it as stale. Push a fresh reading on this
        // interval so the page reflects reality without operator action.
        // MinValue means the first ping fires on the very first loop pass, not
        // 60 seconds after start: a freshly installed agent should show up on
        // the fleet page immediately rather than looking dead for a minute.
        var nextHeartbeat = DateTimeOffset.MinValue;

        // Same reasoning for the deep inventory: MinValue so a freshly installed
        // agent reports its software and security posture immediately instead of
        // the fleet page showing "never fingerprinted" for a day.
        var nextFingerprint = DateTimeOffset.MinValue;

        // Declared outside the try: the retry sleep below has to know whether the
        // request produced anything, and a local declared inside the block
        // would not be in scope in the catch that decides to retry.
        List<JsonElement>? commands = null;

            while (true)
            {
                try
                {
                // Due work is done BEFORE parking, never after waking.
                //
                // This block used to sit below the sleep, which was invisible
                // while the interval was 10 s but is wrong the moment the agent
                // parks for a minute waiting for a command: the fleet heartbeat
                // would then be up to a minute late on a page whose whole purpose
                // is saying whether a laptop is alive. A machine parked on an
                // open connection has to keep its own clock.
                if (DateTimeOffset.UtcNow >= nextHeartbeat)
                {
                    SendHeartbeat(url, token, assetId);

                    // Mark this daemon alive so a user-session tray stands down.
                    // A SYSTEM process and a user process cannot see each other's
                    // session-local mutex, so this file is the only signal that
                    // crosses the boundary. Only the daemon writes it.
                    TouchDaemonMarker();

                    // Advance regardless of success. On failure the retry waits a
                    // full interval rather than hammering an unreachable server.
                    nextHeartbeat = DateTimeOffset.UtcNow.AddSeconds(HeartbeatSeconds);
                }

                if (DateTimeOffset.UtcNow >= nextFingerprint)
                {
                    SendFingerprint(url, token);

                    nextFingerprint = DateTimeOffset.UtcNow.AddSeconds(FingerprintSeconds);
                }

                // `token` is a local copy so a healed credential replaces it for
                // every later poll in this process.
                //
                // waitSeconds parks the request instead of returning empty and
                // sleeping. Idle traffic drops from six requests a minute to
                // none, while a click in the browser still lands within
                // milliseconds because the server returns the moment a command
                // is queued rather than at the end of a fixed interval.
                commands = Api.GetInbox(url, token, LongPollSeconds, out var unauthorized);
                var healed = false;

                if (unauthorized && !TryHealToken(url, ref token, out healed))
                {
                    // The server rejected us and re-registration did not work
                    // either. Back off a little so we do not hammer it while it
                    // is down, but keep trying: this loop is the only thing that
                    // brings the machine back on the fleet page.
                    Console.Error.WriteLine("[!] Token rejected and re-registration failed; retrying.");
                    Thread.Sleep(TimeSpan.FromSeconds(Math.Max(pollSeconds, 30)));
                    continue;
                }

                if (healed)
                {
                    // The Run key still carries the dead token, so a reboot
                    // would resurrect the broken credential.
                    RegisterAutostart();
                }

                if (commands is not null)
                {
                    foreach (var cmd in commands)
                    {
                        var id = cmd.GetProperty("id").GetInt64();
                        var command = cmd.GetProperty("command").GetString() ?? "";

                        if (command == "scan")
                        {
                            int rc = RunOnce(url, token, assetId, id);
                            Api.Ack(url, token, id, rc == 0 ? "done" : "failed");
                            Console.WriteLine($"[OK] Command #{id} scan -> {(rc == 0 ? "done" : "failed")}");

                            // A scan just wrote a fresh full snapshot, so postpone
                            // the heartbeat rather than collecting the same
                            // hardware twice within seconds.
                            nextHeartbeat = DateTimeOffset.UtcNow.AddSeconds(HeartbeatSeconds);
                        }
                        else if (command == "update")
                        {
                            Console.WriteLine($"[*] Command #{id} update received. Downloading and swapping...");

                            // Reported before the work, not after. The swap kills
                            // this process, so anything written afterwards never
                            // leaves the machine - the fleet page would show the
                            // download as still sitting at the start of a rollout
                            // and then find the device gone.
                            Api.ReportProgress(url, token, id, 5, "Downloading the new version...");

                            // The server sends the download URL in the payload; honour it
                            // instead of always rebuilding the path locally.
                            var downloadUrl = cmd.TryGetProperty("payload", out var p) &&
                                p.ValueKind == JsonValueKind.Object &&
                                p.TryGetProperty("url", out var u)
                                ? u.GetString()
                                : null;

                            // Acked according to what actually happened.
                            //
                            // This used to ack "done" before attempting anything,
                            // which made a failed update indistinguishable from a
                            // successful one on the fleet page: a laptop that could
                            // not reach the download URL showed its update as
                            // complete, stayed on the old build, and nothing said
                            // otherwise. The only honest moment to settle this row
                            // is after the call returns.
                            if (AutoUpdater.PerformUpdate(url, token, downloadUrl, resumeAsDaemon: IsElevated()))
                            {
                                Api.Ack(url, token, id, "done", new Dictionary<string, string>
                                {
                                    ["message"] = "New version downloaded; restarting into it.",
                                });

                                // The batch runner swaps the binary and starts the
                                // new one; this process has to be gone before it can.
                                Environment.Exit(0);
                            }

                            Api.Ack(url, token, id, "failed", new Dictionary<string, string>
                            {
                                ["message"] = "The new version could not be downloaded. The agent is still on " +
                                    (InstallCheck.ReadVersion(InstallCheck.InstalledExe)?.ToString() ?? "the installed build") + ".",
                            });
                        }
                        else if (command == "uninstall")
                        {
                            // Ack FIRST. Uninstalling takes the process (and the
                            // token) down with it, so an unacked command would
                            // stay 'pending' on the server forever and the fleet
                            // page would keep offering "Uninstall" on a machine
                            // that no longer has an agent.
                            Console.WriteLine($"[*] Command #{id} uninstall received. Acknowledging...");
                            // Report that removal has actually begun, before the ack
                            // settles the row. Once acked the command is history and
                            // further progress is ignored, so this is the only point
                            // at which the page can stop saying "queued" and start
                            // saying "removing". The steps after it are not
                            // reportable at all: the agent is deleting itself.
                            Api.ReportProgress(url, token, id, 5, "Removing the agent...");

                            Api.Ack(url, token, id, "done", new Dictionary<string, string>
                            {
                                ["message"] = "Agent removed itself after receiving the uninstall command.",
                            });

                            // Leave the poll loop before touching the disk, so the
                            // sleep below cannot resurrect anything.
                            UninstallRequested = true;
                            return 0;
                        }
                        else if (command == "script")
                        {
                            Console.WriteLine($"[*] Command #{id} script received. Executing script...");
                            Api.ReportProgress(url, token, id, 10, "Preparing script...");
                            var scriptExitCode = RunScriptCommand(url, token, id, cmd);
                            Console.WriteLine($"[OK] Command #{id} script -> {(scriptExitCode == 0 ? "done" : "failed")}");
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

            // Only reached when the inbox request failed or returned without a
            // command. On the happy path the loop parks inside GetInbox instead
            // and this sleep never runs - the wait is the server holding the
            // request, not this thread burning a timer.
            if (commands is null || commands.Count == 0)
            {
                Thread.Sleep(TimeSpan.FromSeconds(pollSeconds));
            }
        }
    }

    /// <summary>
    /// Collects the deep inventory and pushes it to /api/agent/fingerprint.
    /// </summary>
    /// <remarks>
    /// Runs off the poll loop's own thread but never throws: a machine with a
    /// locked-down registry, a missing WMI class or a half-dead Office install
    /// must still keep reporting telemetry. The collector itself swallows
    /// per-reader failures for the same reason.
    /// </remarks>
    static bool SendFingerprint(string url, string token, long? commandId = null)
    {
        try
        {
            // The registry walk is the single longest step of a scan - tens of
            // seconds on a loaded machine - and it used to sit silent after one
            // "collecting deep inventory" note. Reporting either side of it keeps
            // the progress bar moving through the wait instead of parking at 65%.
            ReportScanProgress(url, token, commandId, 72, "Reading installed softwareâ€¦");

            var payload = FingerprintCollector.Collect();

            ReportScanProgress(url, token, commandId, 90, "Uploading inventoryâ€¦");

            // Reuse the same device id the daemon registers with. The collector
            // deliberately does not derive one itself: reading a different
            // source (the ComputerSystemProduct GUID) produced an id that
            // matched no registered device, so the report never linked to an
            // asset.
            var deviceId = TryReadDeviceId();

            if (!string.IsNullOrWhiteSpace(deviceId))
            {
                payload["device_id"] = deviceId;
            }

            var sent = Api.Fingerprint(url, token, payload);

            if (sent)
            {
                Console.WriteLine(
                    $"[OK] Deep inventory sent ({payload.GetValueOrDefault("software_count", "0")} programs).");
            }

            return sent;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Fingerprint error: {ex.Message}");

            return false;
        }
    }

    /// <summary>
    /// Collects a fresh hardware reading and pushes it to /api/agent/heartbeat.
    /// </summary>
    /// <remarks>
    /// Reuses the same collector as a full audit, because the readings are the
    /// same ones; what differs is the destination endpoint, which updates the
    /// Live Fleet snapshot without writing an asset_audits row. A failure here
    /// is only logged: liveness reporting must never take down the daemon or
    /// block command handling.
    /// </remarks>
    static void SendHeartbeat(string url, string token, string? assetId)
    {
        try
        {
            var payload = HardwareCollector.Collect("service");

            var deviceId = TryReadDeviceId();
            if (!string.IsNullOrWhiteSpace(deviceId))
            {
                payload["device_id"] = deviceId;
            }

            if (Api.Heartbeat(url, token, payload, out var needsFullScan))
            {
                Console.WriteLine("[OK] Telemetry heartbeat sent.");

                // 202: no snapshot exists on the server for this device, so no
                // heartbeat will ever make it visible on Live Fleet. Run the full
                // scan the server is asking for, in place. The daemon never
                // returns to the installer, and an unactioned 202 is exactly what
                // left a freshly enrolled machine invisible while its log claimed
                // every heartbeat was sent.
                if (needsFullScan)
                {
                    Console.WriteLine("[*] No fleet snapshot on the server yet; running the requested full scan.");
                    RunOnce(url, token, assetId);
                }
            }
            else
            {
                Console.Error.WriteLine("[!] Telemetry heartbeat rejected or unreachable.");
            }
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Heartbeat error: {ex.Message}");
        }
    }

    internal static void PrintResult(JsonElement r) =>
        ConsoleSafe(color =>
        {
            var result = r.TryGetProperty("result", out var rv) ? rv.GetString() ?? "UNKNOWN" : "UNKNOWN";
            var asset = r.TryGetProperty("asset", out var av) && av.ValueKind != JsonValueKind.Null ? av.GetString() : "N/A";

            if (color) Console.ForegroundColor = result switch
            {
                "MATCH" => ConsoleColor.Green,
                "MISMATCH" => ConsoleColor.Yellow,
                _ => ConsoleColor.Magenta
            };
            Console.WriteLine($"\n[RESULT] {result}  (asset: {asset})");

            // Object or array. The server serialises an empty PHP array as `[]`, so a
            // device with nothing to report (NEW DEVICE / MATCH) answers with a JSON
            // *array* where a populated mismatch set is an *object*. Calling
            // EnumerateObject() unconditionally threw on the empty case, which made
            // RunOnce return 1 and acked the scan as "failed" - the very first scan
            // of every new device, and the reason a freshly promoted asset looked
            // like a broken agent.
            if (r.TryGetProperty("mismatches", out var mm) &&
                ((mm.ValueKind == JsonValueKind.Object && mm.EnumerateObject().Any()) ||
                 (mm.ValueKind == JsonValueKind.Array && mm.GetArrayLength() > 0)))
            {
                if (color) Console.ForegroundColor = ConsoleColor.Yellow;
                Console.WriteLine("[!] Mismatches:");

                if (mm.ValueKind == JsonValueKind.Object)
                {
                    foreach (var m in mm.EnumerateObject())
                    {
                        var val = m.Value;
                        var reg = val.TryGetProperty("registered", out var rv2) ? rv2.GetString() : "?";
                        var act = val.TryGetProperty("actual", out var av2) ? av2.GetString() : "?";
                        Console.WriteLine($"    {m.Name}: registered \"{reg}\" vs actual \"{act}\"");
                    }
                }
                else
                {
                    foreach (var m in mm.EnumerateArray())
                    {
                        Console.WriteLine($"    {m}");
                    }
                }
            }

            if (r.TryGetProperty("message", out var msg))
            {
                if (color) Console.ForegroundColor = ConsoleColor.Cyan;
                Console.WriteLine(msg.GetString());
            }

            if (color) Console.ResetColor();
        });

    /// <summary>
    /// Runs <paramref name="write"/> with a flag saying whether the console can
    /// take colours at all.
    /// </summary>
    /// <remarks>
    /// Touching <see cref="Console.ForegroundColor"/> under a redirected or
    /// absent stdout throws IOException - the autostart case - so the flag is
    /// probed once and every colour call is guarded by it. Printing itself never
    /// depends on the colours, so redirected output still gets every line.
    /// </remarks>
    internal static void ConsoleSafe(Action<bool> write)
    {
        bool color;
        try
        {
            _ = Console.ForegroundColor;
            color = true;
        }
        catch (IOException)
        {
            color = false;
        }

        write(color);
    }

    /// <summary>
    /// Name of the SYSTEM scheduled task that runs the resident daemon.
    ///
    /// A scheduled task rather than a Windows Service on purpose: this is a WPF
    /// WinExe, and converting it to a real ServiceBase host would mean pulling
    /// the daemon out of the WPF startup path. The task reaches the same end -
    /// the agent runs as SYSTEM from boot with no logged-in user - with a
    /// fraction of the surface area.
    /// </summary>
    private const string SystemTaskName = "MitoAuditTrackerService";

    /// <summary>
    /// The SYSTEM task's name, for code that re-triggers it (the update swap)
    /// without being able to reach the private constant.
    /// </summary>
    public static string SystemTaskNameForRestart => SystemTaskName;

    /// <summary>
    /// Register the daemon to run as SYSTEM at boot, then drop the per-user
    /// autostart entry so the two cannot both run and fight over the inbox.
    /// </summary>
    /// <remarks>
    /// Must be called elevated. `schtasks /RU SYSTEM` cannot be created by a
    /// standard user, and the failure mode is the one this task exists to fix,
    /// so it returns non-zero and prints why rather than pretending.
    /// </remarks>
    static int InstallSystemTask(string url, string token, string? assetId, int pollSeconds, int? parentProcessId = null)
    {
        if (!IsElevated())
        {
            Console.Error.WriteLine("[X] --install-service needs administrator rights (it registers a SYSTEM task).");
            return 1;
        }

        url = url.TrimEnd('/');
        var exe = SelfBinaryPath();

        if (string.IsNullOrWhiteSpace(exe) || !File.Exists(exe))
        {
            Console.Error.WriteLine("[X] Could not resolve the agent executable path.");
            return 1;
        }

        var daemonArgs = $"--daemon --quiet --url \"{url}\" --token \"{token}\" --poll {pollSeconds}";
        if (!string.IsNullOrWhiteSpace(assetId))
        {
            daemonArgs += $" --asset-id \"{assetId}\"";
        }

        // schtasks stores the whole program-plus-args as one command line, so the
        // exe path is quoted and the args follow - the same shape the Run key
        // used, just run as SYSTEM instead of the logged-in user.
        var taskCommand = $"\\\"{exe}\\\" {daemonArgs}";

        // /SC ONSTART /RU SYSTEM /RL HIGHEST: at every boot, as SYSTEM, highest
        // run level. /F overwrites an existing task, so a reinstall repairs
        // rather than errors.
        var create = $"/Create /TN \"{SystemTaskName}\" /TR \"{taskCommand}\" /SC ONSTART /RU SYSTEM /RL HIGHEST /F";

        if (!Run("schtasks", create, 60))
        {
            Console.Error.WriteLine("[X] Could not register the SYSTEM task. Run the installer as an administrator.");
            return 1;
        }
        Console.WriteLine("[OK] Registered SYSTEM task (runs at boot as SYSTEM).");

        // Per user request: keep the tray running (visible in system tray) even
        // after elevating to SYSTEM. The tray remains the primary reporter
        // (agent_mode=tray, counted online). The SYSTEM daemon runs alongside
        // for run_as:system script capability, but the tray owns the inbox poll
        // and heartbeat so fleet status stays "online" with tray icon visible.
        // RemoveAllUserAutostarts() and KillUserTrays() are intentionally skipped.

        // Start the SYSTEM daemon now so run_as:system scripts work immediately.
        Run("schtasks", $"/Run /TN \"{SystemTaskName}\"", 30);

        return 0;
    }

    /// <summary>
    /// Remove the per-user autostart value from every real user profile, so no
    /// tray starts at anyone's logon once the SYSTEM task owns the agent.
    /// </summary>
    static void RemoveAllUserAutostarts()
    {
        foreach (var sid in UserProfileSids())
        {
            try
            {
                using var baseKey = Registry.Users.OpenSubKey($@"{sid}\{RunKeyPath}", true);
                if (baseKey?.GetValue(RunValueName) is not null)
                {
                    baseKey.DeleteValue(RunValueName, false);
                    Console.WriteLine($"[OK] Removed autostart for {sid}.");
                }
            }
            catch
            {
                // A profile we cannot open is not fatal - the visible ones are.
            }
        }

        // And this process's own hive, in case it runs as a user somewhere.
        try
        {
            using var runKey = Registry.CurrentUser.OpenSubKey(RunKeyPath, true);
            runKey?.DeleteValue(RunValueName, false);
        }
        catch
        {
        }
    }

    /// <summary>
    /// Every loaded user profile's SID under HKEY_USERS, excluding the system
    /// accounts. Those are the hives that can hold a real user's Run key.
    /// </summary>
    private static IEnumerable<string> UserProfileSids()
    {
        List<string> sids = [];

        try
        {
            using var users = Registry.Users;
            foreach (var name in users.GetSubKeyNames())
            {
                // S-1-5-18 (SYSTEM), S-1-5-19 (LocalService), S-1-5-20
                // (NetworkService) and the .DEFAULT/class hives are not user
                // profiles; a real interactive user is an S-1-5-21-* SID.
                if (name.StartsWith("S-1-5-21-", StringComparison.OrdinalIgnoreCase))
                {
                    sids.Add(name);
                }
            }
        }
        catch
        {
            // HKEY_USERS unreadable - nothing to enumerate.
        }

        return sids;
    }

    /// <summary>
    /// Stop any tray-mode agent still running in a user session. The SYSTEM
    /// daemon is now the one agent; a surviving tray would double-poll.
    /// </summary>
    static void KillUserTrays(string systemExe, int? preserveProcessId = null)
    {
        var self = Environment.ProcessId;

        foreach (var proc in Process.GetProcessesByName("TrackerAgent"))
        {
            if (proc.Id == self || proc.Id == preserveProcessId)
            {
                continue;
            }

            try
            {
                // Only kill a process that is NOT this SYSTEM daemon: a tray runs
                // without --daemon, the daemon with it. The command line is the
                // only reliable tell from another process.
                var cmdline = ReadCommandLine(proc.Id);
                if (cmdline.Contains("--daemon", StringComparison.OrdinalIgnoreCase))
                {
                    continue;
                }

                proc.Kill();
                proc.WaitForExit(5000);
                Console.WriteLine($"[OK] Stopped a user-session tray (pid {proc.Id}).");
            }
            catch
            {
                // Access denied or already gone.
            }
            finally
            {
                proc.Dispose();
            }
        }
    }

    /// <summary>Best-effort command line of a process, via WMI.</summary>
    private static string ReadCommandLine(int pid)
    {
        try
        {
            using var searcher = new System.Management.ManagementObjectSearcher(
                $"SELECT CommandLine FROM Win32_Process WHERE ProcessId = {pid}");

            foreach (var obj in searcher.Get())
            {
                return obj["CommandLine"]?.ToString() ?? string.Empty;
            }
        }
        catch
        {
            // WMI unavailable.
        }

        return string.Empty;
    }

    /// <summary>Delete the SYSTEM task, ignoring "not found" so uninstall is idempotent.</summary>
    static int RemoveSystemTask()
    {
        var removed = Run("schtasks", $"/Delete /TN \"{SystemTaskName}\" /F", 30);

        // The guard task goes with it. Leaving a watchdog behind that restores the
        // agent after an uninstall would be the worst possible bug: the operator
        // removes the machine and it walks back in a minute later.
        Run("schtasks", $"/Delete /TN \"{GuardTaskName}\" /F", 30);

        RemoveProtectedCopy();

        Console.WriteLine($"[OK] SYSTEM task removed.");

        return 0;
    }

    /// <summary>
    /// A second, independent SYSTEM task whose only job is to put the agent back
    /// if it disappears.
    /// </summary>
    /// <remarks>
    /// Not a Windows service, and not the same task as the daemon, on purpose: a
    /// watchdog that shares a process with the thing it watches cannot notice that
    /// thing was killed. This runs from a protected copy in ProgramData, so even
    /// deleting the whole %LOCALAPPDATA%\TRACKER folder leaves it intact.
    /// </remarks>
    private const string GuardTaskName = "MitoAuditTrackerGuard";

    /// <summary>Where the tamper-proof copy of the agent lives (SYSTEM-only write).</summary>
    static string ProtectedDir =>
        Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "MITO");

    static string ProtectedExe => Path.Combine(ProtectedDir, "TrackerAgent.exe");

    /// <summary>
    /// A sibling of the install folder that, when present, tells the guard this
    /// removal is authorised and it must tear everything down instead of
    /// restoring.
    /// </summary>
    /// <remarks>
    /// This is what stops the guard from turning into malware. The guard runs as
    /// SYSTEM and the uninstall runs as the user, so the user cannot delete the
    /// guard task; without a handshake, every uninstall would be undone a minute
    /// later and the machine could never be removed. The flag is the handshake: a
    /// user-writable file in the same folder the install lives under, which the
    /// guard reads before deciding to restore.
    /// </remarks>
    static string UninstallFlag(string installDir) => installDir.TrimEnd('\\', '/') + ".uninstall";

    /// <summary>
    /// Install the self-heal guard: copy the running binary to PROGRAMDATA,
    /// register a SYSTEM task that re-checks it every minute, and start it.
    /// </summary>
    /// <remarks>
    /// Must be called elevated (both the copy and the task need admin). The
    /// protected copy is what makes the guard meaningful: a per-user install in
    /// %LOCALAPPDATA% can always be deleted by the user, so protection has to live
    /// somewhere the user cannot write.
    /// </remarks>
    static int InstallGuard(int pollSeconds)
    {
        if (!IsElevated())
        {
            Console.Error.WriteLine("[X] Guard install needs administrator rights.");
            return 1;
        }

        var exe = SelfBinaryPath();
        if (string.IsNullOrWhiteSpace(exe) || !File.Exists(exe))
        {
            Console.Error.WriteLine("[X] Could not resolve the agent executable path.");
            return 1;
        }

        try
        {
            // The install directory must be baked into the task, not derived at
            // run time. The guard runs as SYSTEM, whose %LOCALAPPDATA% is the
            // system profile - so a guard that recomputed the path would restore
            // the agent into the wrong place and leave the user's folder empty.
            // The source of truth is where the agent actually lives right now.
            var installDir = Path.GetDirectoryName(exe);
            if (string.IsNullOrWhiteSpace(installDir))
            {
                Console.Error.WriteLine("[X] Could not resolve the install directory.");
                return 1;
            }

            Directory.CreateDirectory(ProtectedDir);
            File.Copy(exe, ProtectedExe, overwrite: true);
            Console.WriteLine($"[OK] Protected copy stored at {ProtectedExe}.");

            // The guard task runs the PROTECTED copy with --guard, not the
            // %LOCALAPPDATA% one: if the install folder is gone there is nothing
            // there to run, which is exactly the case this handles.
            var taskCommand = $"\\\"{ProtectedExe}\\\" --guard --target-dir \\\"{installDir}\\\" --poll {pollSeconds}";

            // /SC MINUTE /MO 1: every minute. A user who deletes the folder gets
            // it back inside a minute - fast enough to be obvious, slow enough not
            // to hammer the disk.
            var create = $"/Create /TN \"{GuardTaskName}\" /TR \"{taskCommand}\" /SC MINUTE /MO 1 /RU SYSTEM /RL HIGHEST /F";

            if (!Run("schtasks", create, 60))
            {
                Console.Error.WriteLine("[X] Could not register the guard task.");
                return 1;
            }

            Console.WriteLine("[OK] Guard task registered (checks every minute).");
            Run("schtasks", $"/Run /TN \"{GuardTaskName}\"", 30);

            return 0;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[X] Guard install failed: {ex.Message}");
            return 1;
        }
    }

    /// <summary>
    /// The guard itself: if the agent is missing from its install folder, put it
    /// back and re-arm autostart, then exit. Runs from the protected copy as
    /// SYSTEM once a minute.
    /// </summary>
    /// <remarks>
    /// The install folder is passed in (--target-dir) rather than recomputed:
    /// this runs as SYSTEM, whose %LOCALAPPDATA% is the system profile, so the
    /// user's real path has to be baked into the task at install time.
    /// </remarks>
    static int RunGuard(string? targetDir)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(targetDir))
            {
                Console.Error.WriteLine("[!] Guard has no target directory; nothing to check.");
                return 1;
            }

            // An authorised removal, marked before the user-side uninstall ran.
            // Do the elevated parts the user could not: drop the tasks and the
            // protected copy. Restoring here would undo a deliberate uninstall.
            if (File.Exists(UninstallFlag(targetDir)))
            {
                Run("schtasks", $"/Delete /TN \"{SystemTaskName}\" /F", 30);
                Run("schtasks", $"/Delete /TN \"{GuardTaskName}\" /F", 30);
                RemoveProtectedCopy();
                TryDeleteQuietly(UninstallFlag(targetDir));

                Console.WriteLine("[OK] Uninstall authorised; guard and system tasks removed.");
                return 0;
            }

            var installedExe = Path.Combine(targetDir, "TrackerAgent.exe");

            // Healthy: nothing to do. The common case, and it must be cheap - it
            // runs every minute on every laptop.
            if (GuardSeesHealthyAgent(targetDir))
            {
                return 0;
            }

            if (!File.Exists(ProtectedExe))
            {
                Console.Error.WriteLine("[!] Agent is missing and no protected copy exists to restore from.");
                return 1;
            }

            Directory.CreateDirectory(targetDir);
            File.Copy(ProtectedExe, installedExe, overwrite: true);
            Console.WriteLine($"[OK] Restored agent to {installedExe}.");

            // Re-arm the machine-wide autostart. The user's HKCU Run is not
            // writable from SYSTEM, so the restore uses the HKLM Run key - it
            // launches for every user and survives the folder being deleted with
            // the per-user entry.
            RestoreAutostartBestEffort(installedExe);

            // Start it now rather than waiting for a login, so the machine is
            // reporting again within the same minute it was tampered with.
            try
            {
                Process.Start(new ProcessStartInfo
                {
                    FileName = installedExe,
                    UseShellExecute = true,
                });
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[!] Restored but could not start: {ex.Message}");
            }

            return 0;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[X] Guard failed: {ex.Message}");
            return 1;
        }
    }

    /// <summary>
    /// True when the agent is present and plausibly intact at the install folder.
    /// Split out so the "do no harm" decision is one testable line: the guard must
    /// restore only when the agent is actually gone, never on a machine that is
    /// already fine.
    /// </summary>
    internal static bool GuardSeesHealthyAgent(string targetDir)
    {
        try
        {
            var installedExe = Path.Combine(targetDir, "TrackerAgent.exe");

            return File.Exists(installedExe) && new FileInfo(installedExe).Length > 1024 * 1024;
        }
        catch
        {
            return false;
        }
    }

    private static void RestoreAutostartBestEffort(string installedExe)
    {
        try
        {
            using var key = Registry.LocalMachine.CreateSubKey(RunKeyPath);
            key?.SetValue(RunValueName, $"\"{installedExe}\"");
        }
        catch
        {
            // Not elevated to write HKLM; the protected copy is still restored,
            // which is the part that matters most.
        }
    }

    static void RemoveProtectedCopy()
    {
        try
        {
            if (Directory.Exists(ProtectedDir))
            {
                Directory.Delete(ProtectedDir, true);
            }
        }
        catch
        {
            // Locked or already gone; the uninstall carries on regardless.
        }
    }

    static void TryDeleteQuietly(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
            // Best effort.
        }
    }

    static void RegisterAutostart()
    {
        try
        {
            var exe = Environment.ProcessPath
                ?? Path.Combine(AppContext.BaseDirectory, "TrackerAgent.exe");

            // A SYSTEM-run daemon must NOT write a per-user autostart entry. It
            // would only be writing the SYSTEM profile's Run key, and the whole
            // point of the SYSTEM task is that the daemon starts from the task, not
            // from a logon. Writing it here is the bug that let a user-session tray
            // and the SYSTEM daemon both run, both poll the one token, and both
            // report is_elevated - which is why the flag flipped between true and
            // false from poll to poll.
            if (IsElevated())
            {
                return;
            }

            using var key = Registry.CurrentUser.CreateSubKey(RunKeyPath);
            key?.SetValue(RunValueName, $"\"{exe}\"");
            Console.WriteLine("[OK] Registered for autostart (HKCU Run, tray).");
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
    /// <remarks>
    /// Reached from two places, which is why <see cref="SelfRemove"/> exists:
    /// the CLI daemon calls it after the poll loop returns, and the tray
    /// <c>CommandLoop</c> calls it when the server sends an uninstall command.
    /// One implementation, because the tray path had no teardown at all and
    /// simply acked the command as done while every file stayed put.
    /// </remarks>
    public static void SelfRemove() => Uninstall();

    private static int PrintStatus()
    {
        var installDir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "TRACKER");
        var configPath = Path.Combine(installDir, "config.json");

        try
        {
            if (!File.Exists(configPath))
            {
                Console.WriteLine("Status: Not installed");
                return 1;
            }

            var json = File.ReadAllText(configPath);
            using (var doc = System.Text.Json.JsonDocument.Parse(json))
            {
                var root = doc.RootElement;
                var deviceId = root.GetProperty("device_id").GetString() ?? "unknown";
                var serverUrl = root.GetProperty("server_url").GetString() ?? "unknown";
                var installedAt = root.GetProperty("installed_at").GetString() ?? "unknown";

                Console.WriteLine($"Status: Installed");
                Console.WriteLine($"Device ID: {deviceId}");
                Console.WriteLine($"Server: {serverUrl}");
                Console.WriteLine($"Installed: {installedAt}");
                Console.WriteLine($"Install Path: {installDir}");
                return 0;
            }
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[X] Error reading status: {ex.Message}");
            return 1;
        }
    }

    static void Uninstall()
    {
        try
        {
            var installDir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER");

            // 0. Authorise the removal for the guard FIRST, before anything else
            //    is touched. The guard runs as SYSTEM and cannot be deleted by this
            //    user-level process, so if it is not told to stand down it restores
            //    the agent a minute later and the uninstall quietly undoes itself.
            //    The flag is the only channel the user side controls.
            try
            {
                File.WriteAllText(UninstallFlag(installDir), DateTimeOffset.UtcNow.ToString("o"));
                Console.WriteLine("[OK] Uninstall authorised for the guard.");
            }
            catch (Exception ex)
            {
                Console.Error.WriteLine($"[!] Could not write the uninstall flag: {ex.Message}");
            }

            // 0b. The SYSTEM tasks, if installed elevated. Deleting them needs
            //     elevation too; when this runs unelevated the guard picks the
            //     removals up from the flag above.
            Run("schtasks", $"/Delete /TN \"{SystemTaskName}\" /F", 30);
            Run("schtasks", $"/Delete /TN \"{GuardTaskName}\" /F", 30);
            RemoveProtectedCopy();

            // 1. Autostart first - nothing may restart the agent after this.
            using (var runKey = Registry.CurrentUser.OpenSubKey(RunKeyPath, true))
            {
                if (runKey?.GetValue(RunValueName) is not null)
                {
                    runKey.DeleteValue(RunValueName, false);
                    Console.WriteLine("[OK] Autostart entry removed.");
                }
            }

            // The guard's restore may have written the machine-wide key; clear it
            // too so nothing relaunches a machine that was deliberately removed.
            try
            {
                using var machineRun = Registry.LocalMachine.OpenSubKey(RunKeyPath, true);
                if (machineRun?.GetValue(RunValueName) is not null)
                {
                    machineRun.DeleteValue(RunValueName, false);
                    Console.WriteLine("[OK] Machine autostart entry removed.");
                }
            }
            catch
            {
                // Not elevated to write HKLM; the guard task removal covers this.
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
