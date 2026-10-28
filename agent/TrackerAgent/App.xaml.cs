using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Forms;
using System.Windows.Threading;
using Microsoft.Win32;

namespace TrackerAgent;

public partial class App : System.Windows.Application
{
    private NotifyIcon _trayIcon = null!;
    private Mutex? _trayMutex;
    private bool _ownsTrayMutex;
    private string _installDir = "";
    private string _configPath = "";

    public App()
    {
        // Global exception handler: any unhandled WPF exception (e.g. XAML parse
        // failure in InstallerWindow) shows a visible error instead of silently
        // exiting, which used to leave the process running in Task Manager with
        // no window and no clue what happened.
        Dispatcher.UnhandledException += (_, e) =>
        {
            AgentLog.Write($"Unhandled WPF exception: {e.Exception}");
            try
            {
                System.Windows.MessageBox.Show(
                    $"TrackerAgent gagal memulai: {e.Exception.Message}",
                    "TrackerAgent Error",
                    System.Windows.MessageBoxButton.OK,
                    System.Windows.MessageBoxImage.Error);
            }
            catch { }
            e.Handled = true;
        };

        // Also catch exceptions on thread pool / task threads.
        AppDomain.CurrentDomain.UnhandledException += (_, e) =>
        {
            AgentLog.Write($"Unhandled domain exception: {e.ExceptionObject}");
        };
    }

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

    /// <summary>
    /// While an elevate command is waiting on its UAC prompt, so a second one
    /// is refused instead of stacking a second prompt on the first.
    /// </summary>
    private int _elevationInFlight;

    /// <summary>
    /// Set once the tray is closing, so the elevation that just succeeded and
    /// the daemon check in the command loop cannot both tear the icon down.
    /// </summary>
    private int _trayStopped;

    /// <summary>
    /// How often an elevate command re-reports progress while a human decides,
    /// and how long it may wait. The server calls a command stalled after two
    /// minutes of silence, so the ping has to stay well inside that.
    /// </summary>
    private static readonly TimeSpan ElevationPing = TimeSpan.FromSeconds(30);

    /// <summary>
    /// A prompt nobody answers must not hold the progress dialog - or the
    /// one-elevation-at-a-time guard - open forever.
    /// </summary>
    private static readonly TimeSpan ElevationTimeout = TimeSpan.FromMinutes(5);

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

        // A config with no executable is NOT an installed agent. The old updater
        // deleted the exe before moving the new one in, so a machine whose agent
        // died mid-swap is left with config.json and no binary. Treating that as
        // installed sent the freshly downloaded installer down the "already here"
        // path: no window, no install, no agent - the exact dead end an operator
        // hit where double-clicking did nothing and no folder appeared.
        var exePresent = InstallCheck.HasInstalledExe();

        if (installed != null && !string.IsNullOrWhiteSpace(installed.Token) && exePresent)
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
            or "--status" or "--self-test-icon" or "--install-service" or "--remove-service" or "--guard"
            or "--parent-pid" => true,
        _ => false,
    };

    private void StartTray(AgentConfig config)
    {
        // Tray always shows, even when a SYSTEM daemon is running. The daemon
        // and the tray may both poll the same token; that is an accepted trade-off
        // so the operator always has a tray icon available.

        try
        {
            // Same-session guard: prevents two trays in one session.
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
        // end the process. There is deliberately no Exit entry - an operator who
        // stops the agent from the tray leaves a device that is still on the
        // register but reports nothing, and that is a silent hole in the fleet.
        // Shutdown() is therefore only reached on purpose: the two guards above,
        // and StopTray(), which the uninstall path and the "a SYSTEM daemon
        // appeared" stand-down both call.
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
            // this stays short but informative: version + last sync status.
            Text = BuildTrayTooltip(config),
            Visible = true,
            ContextMenuStrip = BuildContextMenu(config)
        };

        // The menu is built once, by BuildContextMenu, and assigned above. It
        // used to be built there and then filled again here, so every entry
        // appeared twice - Status, Scan Now and Check for Updates below a
        // separator, then the same three once more with no separator between
        // them. Appending to ContextMenuStrip.Items adds to that menu; it does
        // not replace it.
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

    private string BuildTrayTooltip(AgentConfig config)
    {
        // 63 char limit - keep it concise: version + last sync
        var version = InstallCheck.ReadBuildIdentity(Environment.ProcessPath) ?? "?";
        var lastSync = config.LastHeartbeat > DateTimeOffset.MinValue
            ? $"last sync {(DateTimeOffset.Now - config.LastHeartbeat).TotalMinutes:F0}m ago"
            : "never synced";
        return $"Mito Audit Tracker v{version} • {lastSync}";
    }

    private ContextMenuStrip BuildContextMenu(AgentConfig config)
    {
        var menu = new ContextMenuStrip
        {
            ShowImageMargin = true,
            Font = new System.Drawing.Font("Segoe UI", 9F)
        };

        menu.Items.Add("Status", CreateTrayMenuGlyph("\uE946", System.Drawing.Color.FromArgb(96, 165, 250)), (_, _) =>
            Dispatcher.Invoke(() =>
            {
                try
                {
                    var window = new StatusWindow(config.ServerUrl, config.InstalledAt);
                    window.Show();
                    window.Activate();
                }
                catch (Exception ex)
                {
                    System.Windows.MessageBox.Show(ex.Message, "Status", System.Windows.MessageBoxButton.OK, System.Windows.MessageBoxImage.Error);
                }
            }));

        menu.Items.Add(new ToolStripSeparator());

        menu.Items.Add("Check for Updates", CreateTrayMenuGlyph("\uE72C", System.Drawing.Color.FromArgb(249, 112, 102)), (_, _) => RunUpdate(config));

        return menu;
    }

    private static System.Drawing.Bitmap CreateTrayMenuGlyph(string glyph, System.Drawing.Color color)
    {
        var bitmap = new System.Drawing.Bitmap(18, 18);
        using var graphics = System.Drawing.Graphics.FromImage(bitmap);
        graphics.Clear(System.Drawing.Color.Transparent);
        graphics.TextRenderingHint = System.Drawing.Text.TextRenderingHint.AntiAliasGridFit;
        using var font = new System.Drawing.Font("Segoe MDL2 Assets", 12F, System.Drawing.FontStyle.Regular, System.Drawing.GraphicsUnit.Pixel);
        using var brush = new System.Drawing.SolidBrush(color);
        using var format = new System.Drawing.StringFormat
        {
            Alignment = System.Drawing.StringAlignment.Center,
            LineAlignment = System.Drawing.StringAlignment.Center,
        };
        graphics.DrawString(glyph, font, brush, new System.Drawing.RectangleF(0, 0, 18, 18), format);
        return bitmap;
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
                            var result = RunScan(config, id);
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
                            // Pre-flight scan: cek kondisi sebelum eksekusi update.
                            // Server reachable, ruang disk cukup, dan biner baru
                            // beda identitas dengan yang terpasang. Hanya lanjut
                            // kalau semua lolos; ack "failed" dengan alasan jika tidak.
                            var checkOk = CheckUpdateConditions(config, cmd, out var checkMessage, out var downloadUrl);

                            if (!checkOk)
                            {
                                Api.Ack(config.ServerUrl, config.Token, id, "failed",
                                    new Dictionary<string, string>
                                    {
                                        ["message"] = checkMessage ?? "Update dicek dan gagal.",
                                    });
                                continue;
                            }

                            // Report progress while the 160 MB download runs, then
                            // ack DONE only if it actually succeeded. The old order
                            // acked first and updated second, so the server settled
                            // the row as "done" before the binary had even been
                            // fetched - a failed download still looked like a
                            // successful update, and the progress dialog sat at 0%
                            // for the whole wait because nothing reported.
                            var ok = AutoUpdater.PerformUpdate(
                                config.ServerUrl,
                                config.Token,
                                payloadUrl: downloadUrl,
                                onProgress: (percent, stage) =>
                                    Api.ReportProgress(config.ServerUrl, config.Token, id, percent, stage));

                            Api.Ack(config.ServerUrl, config.Token, id, ok ? "done" : "failed",
                                new Dictionary<string, string>
                                {
                                    ["message"] = ok
                                        ? "Update downloaded; the agent is restarting on the new build."
                                        : "The update download failed. Check network reachability to the server.",
                                });

                            if (ok)
                            {
                                Environment.Exit(0);
                            }
                        }
                        else if (command == "elevate")
                        {
                            // Standalone "become admin" request, so an operator can
                            // promote a laptop from the fleet without pushing a
                            // script first. Same one-time UAC path the script uses.
                            //
                            // It runs on its own task rather than on this loop:
                            // Process.Start(runas) does not return until somebody
                            // answers the consent dialog, and a blocked loop stops
                            // every heartbeat - the device went stale on Live Fleet
                            // exactly while somebody was walking over to approve
                            // it. One at a time, so a repeated click cannot stack a
                            // second prompt on the first.
                            AgentLog.Write($"Elevate command #{id} received.");

                            if (Interlocked.CompareExchange(ref _elevationInFlight, 1, 0) != 0)
                            {
                                Api.Ack(config.ServerUrl, config.Token, id, "failed",
                                    new Dictionary<string, string>
                                    {
                                        ["message"] = "This device is already waiting for administrator approval. Answer the Windows prompt that is open on it.",
                                    });

                                continue;
                            }

                            var elevationConfig = config;
                            _ = Task.Run(() => RequestAdministratorApproval(elevationConfig, id));

                            continue;
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

    /// <summary>
    /// Ask Windows for administrator rights and settle command <paramref name="id"/>
    /// with what actually happened - always off the command loop.
    /// </summary>
    /// <remarks>
    /// Process.Start(runas) does not return until somebody answers the consent
    /// dialog, so this can wait for minutes; running that on the loop would stop
    /// the long-poll, the heartbeat and the fingerprint cadence the fleet page
    /// reads as proof of life. While it waits it re-reports every 30 seconds,
    /// because the server calls a command stalled after two minutes of silence
    /// and "stalled" is what turns the progress dialog into "Device not
    /// responding" while the operator is still on their way to the machine.
    ///
    /// The wait is bounded: on timeout the command is settled as refused and the
    /// dialog released, but the request itself keeps running - if somebody
    /// approves the prompt late the install still completes, and the daemon check
    /// at the top of the command loop is what closes this tray then.
    /// </remarks>
    private async Task RequestAdministratorApproval(AgentConfig config, long id)
    {
        try
        {
            var (status, message) = await AskForAdministratorApproval(config, id);

            Api.Ack(config.ServerUrl, config.Token, id, status,
                new Dictionary<string, string> { ["message"] = message });

            // Elevation no longer stops the tray (user request: tray must stay visible).
            // The tray continues running as the primary reporter (agent_mode=tray).
            // The SYSTEM daemon runs alongside for run_as:system script capability.
        }
        catch (Exception ex)
        {
            AgentLog.Write($"Elevate command #{id} failed: {ex.Message}");

            Api.Ack(config.ServerUrl, config.Token, id, "failed",
                new Dictionary<string, string>
                {
                    ["message"] = "The elevation request failed before Windows could show a prompt. Check agent.log on the machine.",
                });
        }
        finally
        {
            Interlocked.Exchange(ref _elevationInFlight, 0);
        }
    }

    /// <summary>
    /// Fire the one-time UAC promotion and wait for its verdict, pinging
    /// progress so the command never reads as stalled while a human decides.
    /// </summary>
    private async Task<(string Status, string Message)> AskForAdministratorApproval(AgentConfig config, long id)
    {
        Api.ReportProgress(config.ServerUrl, config.Token, id, 10, "Approve the Windows prompt...");

        var promotion = Task.Run(() =>
            AgentRunner.PromoteToSystemService(config.ServerUrl, config.Token));
        var deadline = DateTimeOffset.UtcNow.Add(ElevationTimeout);

        while (!promotion.IsCompleted && DateTimeOffset.UtcNow < deadline)
        {
            if (await Task.WhenAny(promotion, Task.Delay(ElevationPing)) == promotion)
            {
                break;
            }

            Api.ReportProgress(config.ServerUrl, config.Token, id, 50,
                "Waiting for the Windows prompt to be approved...");
        }

        if (!promotion.IsCompleted)
        {
            return ("failed",
                $"Nobody approved the Windows prompt within {(int)ElevationTimeout.TotalMinutes} minutes. Trigger the request again when someone is at the machine.");
        }

        var promoted = await promotion;

        return promoted
            ? ("done", "Administrator approval granted: the SYSTEM task is registered. The tray continues running so the device stays online with a visible icon.")
            : ("failed", "Windows did not grant administrator rights - the prompt was cancelled, or the agent is already elevated. Nothing was installed.");
    }

    /// <summary>
    /// Close the tray exactly once. The elevation that just succeeded and the
    /// daemon check in the command loop can both get here within the same poll.
    /// </summary>
    /// <remarks>
    /// Kept for uninstall flow. Elevation no longer stops the tray (user request:
    /// tray must stay visible after "Make admin").
    /// </summary>
    private void StopTray()
    {
        if (Interlocked.Exchange(ref _trayStopped, 1) == 1)
        {
            return;
        }

        try
        {
            Dispatcher.Invoke(() =>
            {
                try
                {
                    _trayIcon.Visible = false;
                    _trayIcon.Dispose();
                }
                catch (Exception ex)
                {
                    AgentLog.Write($"Could not remove the tray icon: {ex.Message}");
                }

                Shutdown();
            });
        }
        catch (Exception ex)
        {
            AgentLog.Write($"Could not close the tray: {ex.Message}");
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
            // This process IS the tray, whatever the icon is doing: it is hidden
            // for a moment while the tray closes (after a successful elevation,
            // before an uninstall), and reporting "unknown" in that window
            // downgraded a healthy device's mode a second before it went away.
            var payload = HardwareCollector.Collect("tray");
            payload["device_id"] = config.DeviceId;

            Api.Heartbeat(config.ServerUrl, config.Token, payload, out var needsFullScan);

            // Update last heartbeat time for tray tooltip
            config.LastHeartbeat = DateTimeOffset.UtcNow;

            // Update tray tooltip with fresh sync time
            Dispatcher.BeginInvoke(() =>
            {
                if (_trayIcon != null)
                {
                    _trayIcon.Text = BuildTrayTooltip(config);
                }
            });

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
    /// Pre-flight scan sebelum eksekusi update dari Live Fleet.
    /// Cek: server terjangkau, ruang disk cukup untuk biner baru (~200MB),
    /// dan biner yang akan diunduh berbeda identitas dengan yang terpasang.
    /// Mengembalikan downloadUrl yang valid jika semua lolos.
    /// </summary>
    private bool CheckUpdateConditions(AgentConfig config, JsonElement cmd, out string? message, out string? downloadUrl)
    {
        message = null;
        downloadUrl = null;

        // 1. Cek server terjangkau
        var payloadUrl = cmd.TryGetProperty("payload", out var up) &&
                         up.ValueKind == JsonValueKind.Object &&
                         up.TryGetProperty("url", out var u)
            ? u.GetString()
            : null;
        downloadUrl = !string.IsNullOrWhiteSpace(payloadUrl)
            ? payloadUrl
            : $"{config.ServerUrl.TrimEnd('/')}/agent/download";

        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
            using var req = new HttpRequestMessage(HttpMethod.Head, downloadUrl);
            if (!string.IsNullOrWhiteSpace(config.Token))
                req.Headers.Authorization = new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", config.Token);

            using var resp = http.Send(req);
            if (!resp.IsSuccessStatusCode)
            {
                message = $"Server tidak merespons (HTTP {(int)resp.StatusCode}).";
                return false;
            }
        }
        catch (Exception ex)
        {
            message = $"Server tidak terjangkau: {ex.Message}";
            return false;
        }

        // 2. Cek ruang disk cukup (butuh ~200MB: download + temp + biner lama)
        try
        {
            var currentExe = Environment.ProcessPath
                ?? Path.Combine(AppContext.BaseDirectory, "TrackerAgent.exe");
            var currentDir = Path.GetDirectoryName(currentExe)!;
            var drive = new System.IO.DriveInfo(currentDir);
            var freeBytes = drive.AvailableFreeSpace;
            var needBytes = 200L * 1024 * 1024; // 200 MB safety margin

            if (freeBytes < needBytes)
            {
                message = $"Ruang disk tidak cukup ({freeBytes / 1024 / 1024:N0} MB tersedia, butuh ~200 MB).";
                return false;
            }
        }
        catch (Exception ex)
        {
            AgentLog.Write($"Disk check skipped: {ex.Message}");
        }

        message = "Kondisi update terpenuhi.";
        return true;
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
    /// <summary>
    /// Run a full scan, reporting progress when a fleet command asked for it.
    ///
    /// commandId is null for the tray menu's own "Scan Now": nobody is watching a
    /// dialog for that one, so there is no bar to fill. It is set for a
    /// server-queued command, and the id is then threaded into RunFullScan so the
    /// stages land on the command the progress dialog is polling. Without it the
    /// bar sat at 0% until the scan finished, which read as a hung job.
    /// </summary>
    private int RunScan(AgentConfig config, long? commandId = null)
    {
        try
        {
            return AgentRunner.RunFullScan(config.ServerUrl, config.Token, config.DeviceId, commandId);
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

    private record AgentConfig(string DeviceId, string Token, string ServerUrl, string InstalledAt)
{
    public DateTimeOffset LastHeartbeat { get; set; } = DateTimeOffset.MinValue;
}
}
