using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Forms;
using Microsoft.Win32;

namespace TrackerAgent;

public partial class App : System.Windows.Application
{
    private NotifyIcon _trayIcon = null!;
    private string _installDir = "";
    private string _configPath = "";

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

        var installed = ReadInstalledConfig();

        if (installed != null && !string.IsNullOrWhiteSpace(installed.Token))
        {
            // Double-click state check (agent/README.md): when the clicked exe
            // is a *different* copy — the installer someone just downloaded —
            // and this machine already runs the agent, compare versions:
            //   • same version      → notice "Aplikasi Sudah Pernah Diinstall"
            //   • different version → in-place update (swap exe, keep config)
            // Clicking the installed executable itself still opens the tray.
            var clickedVersion = InstallCheck.ReadVersion(Environment.ProcessPath);
            var installedVersion = InstallCheck.ReadVersion(InstallCheck.InstalledExe);

            if (!InstallCheck.IsSameFile(Environment.ProcessPath, InstallCheck.InstalledExe) &&
                clickedVersion != null && installedVersion != null)
            {
                MainWindow = new InstallerWindow(
                    clickedVersion == installedVersion
                        ? InstallerMode.AlreadyInstalled
                        : InstallerMode.Update,
                    installedVersion,
                    clickedVersion);
                MainWindow.Show();
                return;
            }

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
            or "--self-test-icon" => true,
        _ => false,
    };

    private void StartTray(AgentConfig config)
    {
        // The MITO mark, not SystemIcons.Information: a deployed agent should be
        // recognisable in the notification area at a glance.
        _trayIcon = new NotifyIcon
        {
            Icon = MitoIcon.Create() ?? SystemIcons.Information,
            Text = "Mito Audit Tracker",
            Visible = true,
            ContextMenuStrip = new ContextMenuStrip()
        };

        _trayIcon.ContextMenuStrip.Items.Add("Scan Now", null, (_, _) => RunScan(config));
        _trayIcon.ContextMenuStrip.Items.Add("Check for Updates", null, (_, _) => RunUpdate(config));
        _trayIcon.ContextMenuStrip.Items.Add("Exit", null, (_, _) => Shutdown());

        _ = Task.Run(() => CommandLoop(config));
    }

    private async Task CommandLoop(AgentConfig config)
    {
        // The tray app and the --daemon both keep the fleet page alive, and both
        // must do the same work. This loop used to poll the inbox and nothing
        // else, so a device left in tray mode — which is exactly what happens
        // after an auto-update, since the restarted process is relaunched
        // without the original command-line arguments — stopped refreshing its
        // telemetry entirely. The fleet page then showed stale battery and disk
        // readings and eventually reported the laptop offline even though it was
        // alive and polling. Intervals match AgentRunner so the two paths are
        // indistinguishable from the server's point of view.
        var nextHeartbeat = DateTimeOffset.MinValue;
        var nextFingerprint = DateTimeOffset.MinValue;

        while (true)
        {
            try
            {
                var commands = Api.GetInbox(config.ServerUrl, config.Token);
                if (commands != null)
                {
                    foreach (var cmd in commands)
                    {
                        var id = cmd.GetProperty("id").GetInt64();
                        var command = cmd.GetProperty("command").GetString() ?? "";

                        if (command == "scan")
                        {
                            RunScan(config);
                            Api.Ack(config.ServerUrl, config.Token, id, "done");
                        }
                        else if (command == "update")
                        {
                            Api.Ack(config.ServerUrl, config.Token, id, "done");
                            _trayIcon.ShowBalloonTip(3000, "Mito Audit Tracker", "Applying auto-update...", ToolTipIcon.Info);
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
                        else
                        {
                            Api.Ack(config.ServerUrl, config.Token, id, "done");
                        }
                    }
                }
            }
            catch { }

            await Task.Delay(TimeSpan.FromSeconds(15));

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

            if (!Api.Heartbeat(config.ServerUrl, config.Token, payload))
            {
                // Deliberately silent. A transient failure is not worth a tray
                // balloon every 60 seconds; the server marks the device offline
                // on its own if it really has stopped reporting.
                Debug.WriteLine("Heartbeat rejected or unreachable.");
            }
        }
        catch (Exception ex)
        {
            Debug.WriteLine($"Heartbeat error: {ex.Message}");
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
            Debug.WriteLine($"Fingerprint error: {ex.Message}");
        }
    }

    private void RunScan(AgentConfig config)
    {
        try
        {
            var payload = HardwareCollector.Collect();
            // Live Fleet (PRD §11) ties telemetry to the device identity that the
            // installer stored, so a device shows up even before its token is
            // bound to that device_id.
            payload["device_id"] = config.DeviceId;

            var result = Api.Post(config.ServerUrl, config.Token, payload);
            _trayIcon.ShowBalloonTip(3000, "Mito Audit Tracker",
                result is not null ? "Scan completed successfully." : "Scan failed.",
                ToolTipIcon.Info);
        }
        catch
        {
            _trayIcon.ShowBalloonTip(3000, "Mito Audit Tracker", "Scan failed.", ToolTipIcon.Error);
        }
    }

    private void RunUpdate(AgentConfig config)
    {
        try
        {
            _trayIcon.ShowBalloonTip(3000, "Mito Audit Tracker", "Checking for updates...", ToolTipIcon.Info);
            bool updated = AutoUpdater.PerformUpdate(config.ServerUrl, config.Token);
            if (updated)
            {
                Environment.Exit(0);
            }
            else
            {
                _trayIcon.ShowBalloonTip(3000, "Mito Audit Tracker", "Update check finished or already up-to-date.", ToolTipIcon.Info);
            }
        }
        catch
        {
            _trayIcon.ShowBalloonTip(3000, "Mito Audit Tracker", "Update failed.", ToolTipIcon.Error);
        }
    }

    protected override void OnExit(ExitEventArgs e)
    {
        _trayIcon?.Dispose();
        base.OnExit(e);
    }

    private record AgentConfig(string DeviceId, string Token, string ServerUrl, string InstalledAt);
}
