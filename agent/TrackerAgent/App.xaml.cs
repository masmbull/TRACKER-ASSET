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

        if (File.Exists(_configPath))
        {
            var config = JsonSerializer.Deserialize<AgentConfig>(File.ReadAllText(_configPath));
            if (config != null && !string.IsNullOrWhiteSpace(config.Token))
            {
                StartTray(config);
                return;
            }
        }

        MainWindow = new InstallerWindow();
        MainWindow.Show();
    }

    private static bool IsCliFlag(string arg) => arg.ToLowerInvariant() switch
    {
        "--url" or "-u" or "--token" or "-t" or "--asset-id" or "-a" or
        "--quiet" or "-q" or "--daemon" or "-d" or "--poll" or "--uninstall" or "--update" => true,
        _ => false,
    };

    private void StartTray(AgentConfig config)
    {
        _trayIcon = new NotifyIcon
        {
            Icon = SystemIcons.Information,
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
                        else
                        {
                            Api.Ack(config.ServerUrl, config.Token, id, "done");
                        }
                    }
                }
            }
            catch { }

            await Task.Delay(TimeSpan.FromSeconds(15));
        }
    }

    private void RunScan(AgentConfig config)
    {
        try
        {
            var payload = HardwareCollector.Collect();
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
