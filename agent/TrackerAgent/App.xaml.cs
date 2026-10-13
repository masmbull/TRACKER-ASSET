using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Forms;
using Microsoft.Win32;

namespace TrackerAgent;

public partial class App : System.Windows.Application
{
    private NotifyIcon _trayIcon = null!;
    private Mutex? _trayMutex;
    private bool _ownsTrayMutex;
    private string _installDir = "";
    private string _configPath = "";

    /// <summary>
    /// Guards the heartbeat-triggered full scan.
    /// </summary>
    /// <remarks>
    /// A scan takes minutes (the first deep inventory runs a Windows Update
    /// search), and the heartbeat keeps firing every 60 seconds. With no guard,
    /// a device that needs one scan collected one more per minute - which is
    /// exactly how this machine ended up with ten pending scans queued against
    /// one token while the tray claimed it was reporting normally.
    /// </remarks>
    private static readonly SemaphoreSlim ScanGate = new(1, 1);

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        // CLI modes documented in agent/README.md (--token, --daemon, --uninstall, --update...).
        // When any CLI flag is present we never open the installer/tray UI: run
        // AgentRunner headless (one-shot audit or resident inbox daemon) and exit
        // with its exit code. --server-url is the installer GUI flag, not a CLI mode.
        if (e.Args.Any(IsCliFlag))
        {
            int exitCode;
            try
            {
                exitCode = AgentRunner.Run(e.Args);
            }
            catch (Exception ex)
            {
                Console.Error.WriteLine($"[X] {ex.Message}");
                exitCode = 1;
            }

            Console.Out.Flush();
            Console.Error.Flush();
            Environment.Exit(exitCode);
            return;
        }

        _installDir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "TRACKER");
        _configPath = Path.Combine(_installDir, "config.json");

        // The tray runs with no console either, so it logs to the same
        // agent.log the daemon uses. Without it the only symptom of a tray that
        // never reached the server was a fleet page quietly showing the device
        // offline - nothing on the machine said why.
        AgentLog.Write($"Tray started (build {InstallCheck.ReadBuildIdentity(Environment.ProcessPath) ?? "unknown"}).");

        var installed = ReadInstalledConfig();

        if (installed != null && !string.IsNullOrWhiteSpace(installed.Token))
        {
            // Double-click state check (agent/README.md): when the clicked exe
            // is a *different* copy — the installer someone just downloaded —
            // and this machine already runs the agent, compare versions:
            //   • same version      → notice "Aplikasi Sudah Pernah Diinstall"
            //   • different version → in-place update (swap exe, keep config)
            // Clicking the installed executable itself still opens the tray.
            var clickedIdentity = InstallCheck.ReadBuildIdentity(Environment.ProcessPath);
            var installedIdentity = InstallCheck.ReadBuildIdentity(InstallCheck.InstalledExe);

            if (!InstallCheck.IsSameFile(Environment.ProcessPath, InstallCheck.InstalledExe) &&
                clickedIdentity != null && installedIdentity != null)
            {
                // Compared on the full build identity, not the version number. Two
                // binaries that both say 1.2.0 but were built from different
                // commits are different builds, and treating them as the same
                // silently blocked every bug fix published under an unchanged
                // version - the machine reported the agent was already installed
                // and the new binary never landed.
                MainWindow = new InstallerWindow(
                    InstallCheck.IsSameBuild(clickedIdentity, installedIdentity)
                        ? InstallerMode.AlreadyInstalled
                        : InstallerMode.Update,
                    InstallCheck.ReadVersion(InstallCheck.InstalledExe),
                    InstallCheck.ReadVersion(Environment.ProcessPath));
                MainWindow.Show();
                return;
            }

            // The tray loop is what keeps this machine on Live Fleet: it polls
            // the inbox and pushes heartbeats. Showing the status window
            // instead of the tray left the device silent, because nothing else
            // in this process talks to the server after startup. The window is
            // now a "Status" item on the tray menu.
            StartTray(installed);
            return;
        }

        MainWindow = new InstallerWindow();
        MainWindow.Show();
    }

    /// <summary>
    /// config.json stores snake_case keys (device_id, server_url, ...). Without
    /// this naming policy the PascalCase AgentConfig record silently binds to
    /// null for every field, so double-click detection treats an installed,
    /// running agent as a fresh machine and re-enters the Install flow.
    /// </summary>
    private static readonly JsonSerializerOptions ConfigJsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
    };

    /// <summary>
    /// Reads the persisted agent settings, treating a missing or corrupt file
    /// as "not installed" so the installer can repair the machine.
    /// </summary>
    private AgentConfig? ReadInstalledConfig()
    {
        try
        {
            return File.Exists(_configPath)
                ? JsonSerializer.Deserialize<AgentConfig>(File.ReadAllText(_configPath), ConfigJsonOptions)
                : null;
        }
        catch
        {
            return null;
        }
    }

    private static bool IsCliFlag(string arg) => arg.ToLowerInvariant() switch
    {
        "--url" or "-u" or "--token" or "-t" or "--asset-id" or "-a" or
        "--quiet" or "-q" or "--daemon" or "-d" or "--poll" or "--uninstall" or "--update"
            or "--status" or "--self-test-icon" => true,
        _ => false,
    };

    private void StartTray(AgentConfig config)
    {
        try
        {
            _trayMutex = new Mutex(true, @"Local\MITO.TRACKER-ASSET.Tray", out _ownsTrayMutex);

            if (!_ownsTrayMutex)
            {
                _trayMutex.Dispose();
                _trayMutex = null;
                Shutdown();
                return;
            }
        }
        catch (Exception ex)
        {
            AgentLog.Write($"Could not enforce single tray instance: {ex.Message}");
        }

        RegisterTrayAutostart();

        // Tray-only app: closing the Status window (or never opening one) must not
        // end the process. Shutdown happens from the Exit menu and from the
        // uninstall command, both of which call Shutdown() on purpose.
        ShutdownMode = ShutdownMode.OnExplicitShutdown;

        // The MITO mark, not SystemIcons.Information: a deployed agent should be
        // recognisable in the notification area at a glance.
        _trayIcon = new NotifyIcon
        {
            Icon = MitoIcon.Create() ?? SystemIcons.Information,
            // Tooltip only - no balloon. It appears when somebody hovers the icon,
            // which is a request for information rather than an interruption.
            //
            // Windows caps NotifyIcon.Text at 63 characters and throws past it, so
            // this stays short and says where results went now the balloons are gone.
            Text = "Mito Audit Tracker - running. See the web.",
            Visible = true,
            ContextMenuStrip = new ContextMenuStrip()
        };

        _trayIcon.ContextMenuStrip.Items.Add("Status", null, (_, _) =>
            Dispatcher.Invoke(() =>
            {
                var window = new StatusWindow(
                    config.ServerUrl, config.InstalledAt);
                window.Show();
                window.Activate();
            }));
        _trayIcon.ContextMenuStrip.Items.Add("Scan Now", null, (_, _) => RunScan(config));
        _trayIcon.ContextMenuStrip.Items.Add("Check for Updates", null, (_, _) => RunUpdate(config));

        _ = Task.Run(() => CommandLoop(config));
    }

    private static void RegisterTrayAutostart()
    {
        try
        {
            using var key = Registry.CurrentUser.CreateSubKey(
                @"Software\Microsoft\Windows\CurrentVersion\Run");
            key?.SetValue("MitoAuditTracker",
                InstallCheck.BuildTrayAutostartCommand(InstallCheck.InstalledExe));
        }
        catch (Exception ex)
        {
            AgentLog.Write($"Could not register tray autostart: {ex.Message}");
        }
    }

    private async Task CommandLoop(AgentConfig config)
    {
        var nextHeartbeat = DateTimeOffset.MinValue;
        var nextFingerprint = DateTimeOffset.MinValue;

        // config is a local record copy, not the readonly field: a rejected
        // token is healed into it below and every later poll and ack in this
        // process uses the new credential. The tray used to poll with
        // config.Token forever and had no heal path at all, so a rotated or
        // re-registered token left the device silent on Live Fleet until the
        // whole application was restarted.
        while (true)
        {
            try
            {
                var commands = Api.GetInbox(config.ServerUrl, config.Token, AgentRunner.LongPollSeconds, out var unauthorized);

                if (unauthorized)
                {
                    // Same self-heal as the CLI daemon: ask for a fresh token
                    // rather than staying 401 on a machine nobody can reach.
                    var issues = RegisterDevice(config.ServerUrl, config.Token);
                    var issued = issues.TryGetValue("token", out var value) ? value : null;

                    if (!string.IsNullOrWhiteSpace(issued))
                    {
                        config = config with { Token = issued };

                        // Report against the address this device actually
                        // enrolled on: the installer writes server_url into
                        // config.json and the registry, and a machine moved to a
                        // different server would go back to the old one on the
                        // next launch otherwise.
                        if (issues.TryGetValue("server_url", out var reported) &&
                            !string.IsNullOrWhiteSpace(reported))
                        {
                            config = config with { ServerUrl = reported.TrimEnd('/') };
                        }

                        SaveConfig(config.ServerUrl, config.Token);
                        AgentLog.Write("Token was rejected - re-registered and obtained a new one.");
                    }
                }

                if (commands != null)
                {
                    foreach (var cmd in commands)
                    {
                        var id = cmd.GetProperty("id").GetInt64();
                        var command = cmd.GetProperty("command").GetString() ?? "";

                        if (command == "scan")
                        {
                            // A browser-triggered scan belongs to the web page, not the
                            // local tray window. Run the actual full inventory in the
                            // background and settle the command only after the agent has
                            // posted both the audit and the fingerprint payload.
                            var result = RunScan(config);
                            nextHeartbeat = DateTimeOffset.UtcNow.AddSeconds(HeartbeatSeconds);
                            nextFingerprint = DateTimeOffset.UtcNow.AddSeconds(FingerprintSeconds);
                            Api.Ack(config.ServerUrl, config.Token, id,
                                result == 0 ? "done" : "failed",
                                new Dictionary<string, string>
                                {
                                    ["message"] = result == 0
                                        ? "Audit and deep inventory completed."
                                        : "The full scan failed. Check the agent log and retry.",
                                });
                        }
                        else if (command == "update")
                        {
                            Api.Ack(config.ServerUrl, config.Token, id, "done");
                            if (AutoUpdater.PerformUpdate(config.ServerUrl, config.Token))
                            {
                                Environment.Exit(0);
                            }
                        }
                        else if (command == "uninstall")
                        {
                            // This branch used to be missing entirely, so an
                            // uninstall landed in the generic `else` below and was
                            // merely acked "done": the server showed the device as
                            // removed while every file stayed on the machine.
                            //
                            // Ack FIRST (the token is burned server-side by this
                            // marker, and the loop cannot poll again afterwards),
                            // then hand the actual teardown to AgentRunner, which
                            // owns the delete-self-and-exit sequence.
                            Api.Ack(config.ServerUrl, config.Token, id, "done",
                                new Dictionary<string, string>
                                {
                                    ["message"] = "Agent removed itself after receiving the uninstall command.",
                                });

                            _trayIcon.Visible = false;
                            _trayIcon.Dispose();

                            // The tray loop runs on a background task with no
                            // console, so give the operator a moment to see the
                            // notice before this process disappears for good.
                            await Task.Delay(1500);

                            AgentRunner.SelfRemove();
                            return;
                        }
                        else if (command == "script")
                        {
                            AgentLog.Write($"Script command #{id} received. Executing...");
                            Api.ReportProgress(config.ServerUrl, config.Token, id, 10, "Preparing script...");
                            var scriptExitCode = AgentRunner.RunScriptCommand(config.ServerUrl, config.Token, id, cmd);
                            AgentLog.Write($"Script command #{id} finished with exit code {scriptExitCode}.");
                        }
                        else
                        {
                            Api.Ack(config.ServerUrl, config.Token, id, "done");
                        }
                    }
                }
            }
            catch (Exception pollEx)
            {
                AgentLog.Write($"CommandLoop poll error: {pollEx.Message}");
            }

            // The server answered the heartbeat with 202 "run a full scan": this
            // device has no telemetry row, so Live Fleet has nothing to show for
            // it. Do the scan the server asked for instead of pinging again a
            // minute later and getting the same answer.
            if (PendingHeartbeat)
            {
                PendingHeartbeat = false;

                // Never two scans at once. A scan takes minutes and the
                // heartbeat fires every 60 seconds, so without the gate a device
                // that needs one scan collected one more per minute - how this
                // machine ended up with ten of them queued against its one token.
                if (ScanGate.Wait(0))
                {
                    try
                    {
                        // Settle the scan the server opened when this device
                        // registered, before doing the work: the row is the page's
                        // "queued" badge and it would otherwise stay lit forever.
                        var result = RunScan(config);
                        nextHeartbeat = DateTimeOffset.UtcNow.AddSeconds(HeartbeatSeconds);
                        nextFingerprint = DateTimeOffset.UtcNow.AddSeconds(FingerprintSeconds);
                        Api.AckPendingHeartbeat(
                            config.ServerUrl, config.Token, result == 0 ? "done" : "failed");
                        nextHeartbeat = DateTimeOffset.UtcNow.AddSeconds(HeartbeatSeconds);
                    }
                    finally
                    {
                        ScanGate.Release();
                    }
                }
            }

            // Long-poll above already parks for up to 20s; this is the gap after a
            // command came back early.
            await Task.Delay(TimeSpan.FromSeconds(5));

            // MinValue on both, so a tray app that has just been updated reports
            // immediately instead of looking dead for a full interval.
            if (DateTimeOffset.UtcNow >= nextHeartbeat)
            {
                RunHeartbeat(config);
                nextHeartbeat = DateTimeOffset.UtcNow.AddSeconds(HeartbeatSeconds);
            }

            if (DateTimeOffset.UtcNow >= nextFingerprint)
            {
                RunFingerprint(config);
                nextFingerprint = DateTimeOffset.UtcNow.AddSeconds(FingerprintSeconds);
            }
        }
    }

    /** Matches AgentRunner so both runtime modes report on the same cadence. */
    private const int HeartbeatSeconds = 60;

    private const int FingerprintSeconds = 24 * 60 * 60;

    /// <summary>
    /// Push a telemetry reading, reusing the daemon's collector.
    /// </summary>
    /// <remarks>
    /// AgentRunner's methods are internal to that class, so the tray path keeps
    /// its own thin copy rather than reaching into private members. Both catch
    /// everything: a machine with a locked-down registry must still keep its
    /// inbox poll and its tray menu alive.
    /// </remarks>
    private void RunHeartbeat(AgentConfig config)
    {
        try
        {
            var payload = HardwareCollector.Collect();
            payload["device_id"] = config.DeviceId;

            Api.Heartbeat(config.ServerUrl, config.Token, payload, out var needsFullScan);

            // 202: the server has no snapshot of this device at all, so another
            // heartbeat would get the same answer. The poll loop runs the scan
            // the server asked for instead.
            PendingHeartbeat |= needsFullScan;
        }
        catch (Exception ex)
            {
                AgentLog.Write($"Heartbeat error: {ex.Message}");
            }
    }

    private void RunFingerprint(AgentConfig config)
    {
        try
        {
            var payload = FingerprintCollector.Collect();
            payload["device_id"] = config.DeviceId;

            Api.Fingerprint(config.ServerUrl, config.Token, payload);
        }
        catch (Exception ex)
        {
            AgentLog.Write($"Fingerprint error: {ex.Message}");
        }
    }

    /// <summary>
    /// Set by the heartbeat when the server answers 202, i.e. this device has no
    /// telemetry snapshot yet and a full scan is the only thing that creates one.
    /// The loop acts on it below rather than collecting hardware twice.
    /// </summary>
    private bool PendingHeartbeat;

    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(30) };

    /// <summary>
    /// Ask the server for a fresh credential and the address to report to.
    /// </summary>
    /// <remarks>
    /// Added for the tray alone. The CLI daemon heals 401s through
    /// AgentRunner.TryHealToken, but the tray had no heal path at all: a token
    /// that was neither empty nor valid - revoked on the Tokens page and then
    /// restored, or minted by a server whose database was reset - left the
    /// device permanently offline. The reply is read with the same snake_case
    /// keys the installer uses, so nothing downstream changes.
    /// </remarks>
    private static Dictionary<string, string> RegisterDevice(string serverUrl, string token)
    {
        var issues = new Dictionary<string, string>();

        try
        {
            var payload = new Dictionary<string, string>();

            // Read from config.json rather than the in-memory field: the
            // registration endpoint matches on the hardware id this machine
            // generated, so a restarted tray must send the same one or it mints
            // a second device row for the same laptop.
            var deviceId = ReadConfigValue("device_id");

            if (!string.IsNullOrWhiteSpace(deviceId))
            {
                payload["device_id"] = deviceId!;
                payload["hardware_id"] = deviceId!;
            }

            var identity = InstallCheck.ReadBuildIdentity(Environment.ProcessPath);

            if (!string.IsNullOrWhiteSpace(identity))
            {
                payload["agent_version"] = identity;
            }

            payload["name"] = Environment.MachineName;
            payload["platform"] = Environment.OSVersion.ToString();

            using var request = new HttpRequestMessage(
                HttpMethod.Post, $"{serverUrl.TrimEnd('/')}/api/agent/register")
            {
                Content = new StringContent(
                    JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json")
            };

            if (!string.IsNullOrWhiteSpace(token))
            {
                request.Headers.TryAddWithoutValidation("Authorization", $"Bearer {token}");
            }

            using var response = Http.Send(request);

            if (!response.IsSuccessStatusCode)
            {
                AgentLog.Write($"[!] Re-register rejected: HTTP {(int)response.StatusCode}");
                return issues;
            }

            using var body = JsonDocument.Parse(
                response.Content.ReadAsStringAsync().GetAwaiter().GetResult());

            foreach (var key in new[] { "token", "device_id" })
            {
                if (body.RootElement.TryGetProperty(key, out var value) &&
                    value.ValueKind == JsonValueKind.String)
                {
                    issues[key] = value.GetString() ?? string.Empty;
                }
            }

            issues["server_url"] = serverUrl.TrimEnd('/');
        }
        catch (Exception ex)
        {
            AgentLog.Write($"Re-register error: {ex.Message}");
        }

        return issues;
    }

    /// <summary>
    /// Mirror a healed token into config.json and the HKCU registry, so the next
    /// launch starts with the same working credential. Best-effort: a failure
    /// here only costs the heal, never the running tray.
    /// </summary>
    private static void SaveConfig(string serverUrl, string token)
    {
        try
        {
            var dir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER");

            Directory.CreateDirectory(dir);

            File.WriteAllText(
                Path.Combine(dir, "config.json"),
                JsonSerializer.Serialize(
                    new Dictionary<string, string>
                    {
                        ["device_id"] = ReadConfigValue("device_id") ?? string.Empty,
                        ["token"] = token,
                        ["server_url"] = serverUrl,
                        ["installed_at"] = ReadConfigValue("installed_at")
                            ?? DateTimeOffset.Now.ToString("o"),
                    },
                    new JsonSerializerOptions { WriteIndented = true }));

            using var key = Microsoft.Win32.Registry.CurrentUser.CreateSubKey(@"Software\Mito\AuditTracker");
            key?.SetValue("ServerUrl", serverUrl);
            key?.SetValue("Token", token);
        }
        catch (Exception ex)
        {
            AgentLog.Write($"Could not persist the healed credential: {ex.Message}");
        }
    }

    /// <summary>One string field out of config.json, or null when it is missing.</summary>
    private static string? ReadConfigValue(string name)
    {
        try
        {
            var path = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER", "config.json");

            if (!File.Exists(path))
            {
                return null;
            }

            using var doc = JsonDocument.Parse(File.ReadAllText(path));

            return doc.RootElement.TryGetProperty(name, out var value)
                ? value.GetString()
                : null;
        }
        catch
        {
            return null;
        }
    }

    /// <summary>
    /// Show the scan window. This is where the server's "Scan Now" lands.
    /// </summary>
    /// <remarks>
    /// The command loop runs on a background thread, so the window is created on
    /// the dispatcher. Show() rather than ShowDialog(): the loop has to keep
    /// polling while the scan runs.
    /// </remarks>
    private void OpenScanWindow(AgentConfig config)
    {
        try
        {
            _ = Dispatcher.BeginInvoke(new Action(() =>
            {
                var scanWindow = new ScanWindow(config.ServerUrl, config.Token, config.DeviceId);
                scanWindow.Show();  // Non-blocking
                scanWindow.Activate();  // Bring to front
            }));
        }
        catch (Exception ex)
        {
            AgentLog.Write($"OpenScanWindow error: {ex.Message}");
        }
    }

    /// <summary>
    /// Run a scan, reporting the outcome on the asset page rather than here.
    /// </summary>
    /// <remarks>
    /// No balloon on success or failure. A background inventory agent should not
    /// interrupt the person using the machine; the audit lands on the asset page
    /// and on Live Fleet, which is where somebody actually looks for it. A
    /// balloon saying "Scan completed successfully." told the user nothing they
    /// did not already have, and one saying "Scan failed." popped up with no
    /// indication of what failed or where to look.
    /// </remarks>
    private int RunScan(AgentConfig config)
    {
        try
        {
            return AgentRunner.RunFullScan(config.ServerUrl, config.Token, config.DeviceId);
        }
        catch
        {
            // Swallowed on purpose. The server marks the device offline when it
            // stops reporting, which is the visible signal.
            return 1;
        }
    }

    /// <summary>
    /// Apply a queued update, again without saying so on this machine.
    /// </summary>
    private void RunUpdate(AgentConfig config)
    {
        try
        {
            if (AutoUpdater.PerformUpdate(config.ServerUrl, config.Token))
            {
                // Replaced the running binary; this process is now the old one.
                Environment.Exit(0);
            }
        }
        catch
        {
            // Nothing useful to show a user who did not ask for detail.
        }
    }

    protected override void OnExit(ExitEventArgs e)
    {
        _trayIcon?.Dispose();

        if (_ownsTrayMutex)
        {
            try
            {
                _trayMutex?.ReleaseMutex();
            }
            catch (ApplicationException)
            {
            }
        }

        _trayMutex?.Dispose();
        base.OnExit(e);
    }

    private record AgentConfig(string DeviceId, string Token, string ServerUrl, string InstalledAt);
}
