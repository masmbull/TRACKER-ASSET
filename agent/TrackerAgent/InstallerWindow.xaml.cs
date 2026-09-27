using System.Diagnostics;
using System.IO;
using System.Management;
using System.Net.Http;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Animation;
using System.Windows.Threading;
using Microsoft.Win32;

namespace TrackerAgent;

/// <summary>
/// Zero-configuration installer (PRD §11). Double-clicking the agent opens this
/// window: it locates the TRACKER-ASSET server (the address is baked into the
/// download by <c>AgentTokenController::configTrailer</c>), registers the
/// device, enables autostart and starts reporting — the user only watches an
/// animated progress bar, no server URL or token to type.
/// </summary>
public partial class InstallerWindow : Window
{
    private readonly DispatcherTimer _progressTimer = new();
    private readonly DispatcherTimer _autoCloseTimer = new();

    private int _targetProgress;
    private int _displayedProgress;
    private string _installDir = "";
    private string _serverUrl = "";
    private string _deviceId = "";
    private string _lastError = "";

    private readonly InstallerMode _mode;
    private readonly string _installedExe;
    private readonly Version? _installedVersion;
    private readonly Version? _newVersion;

    /// <summary>Fresh install (agent not present on this machine).</summary>
    public InstallerWindow() : this(InstallerMode.Install, null, null)
    {
    }

    /// <summary>
    /// State-aware constructor (agent/README.md). App.OnStartup compares the
    /// clicked build with the installed one and picks the mode: full install,
    /// in-place update (swap exe, keep config), or the
    /// "Aplikasi Sudah Pernah Diinstall" notice.
    /// </summary>
    public InstallerWindow(InstallerMode mode, Version? installedVersion, Version? newVersion)
    {
        InitializeComponent();

        ResultPanel.Visibility = Visibility.Collapsed;
        RetryButton.Visibility = Visibility.Collapsed;
        CloseButton.Visibility = Visibility.Collapsed;

        _mode = mode;
        _installedVersion = installedVersion;
        _newVersion = newVersion;
        _installedExe = InstallCheck.InstalledExe;
        _installDir = InstallCheck.InstallDir;

        switch (mode)
        {
            case InstallerMode.AlreadyInstalled:
                ShowAlreadyInstalled();
                break;

            case InstallerMode.Update:
                StartProgressAnimation();
                BeginShimmer();
                _ = AutoUpdateAsync();
                break;

            default:
                StartProgressAnimation();
                BeginShimmer();
                _ = AutoInstallAsync();
                break;
        }
    }

    /// <summary>
    /// Eases the bar towards the latest reported percentage on the UI thread,
    /// so the animation stays smooth while the install work itself runs on a
    /// background thread.
    /// </summary>
    private void StartProgressAnimation()
    {
        _progressTimer.Interval = TimeSpan.FromMilliseconds(16);
        _progressTimer.Tick += (_, _) =>
        {
            var remaining = _targetProgress - _displayedProgress;

            _displayedProgress = remaining <= 1
                ? _targetProgress
                : _displayedProgress + Math.Max(1, (int)Math.Ceiling(remaining * 0.18));

            ProgressBar.Value = _displayedProgress;
            PercentText.Text = $"{_displayedProgress}%";
        };
        _progressTimer.Start();
    }

    private void BeginShimmer() =>
        ((Storyboard)FindResource("ShimmerStoryboard")).Begin(this, true);

    private void EndShimmer() =>
        ((Storyboard)FindResource("ShimmerStoryboard")).Stop(this);

    /// <summary>Thread-safe progress report (called from the worker thread).</summary>
    private void Report(int percent, string message)
    {
        Dispatcher.BeginInvoke(() =>
        {
            _targetProgress = Math.Clamp(percent, 0, 100);
            StatusText.Text = message;
        });
    }

    private async Task AutoInstallAsync()
    {
        Report(2, "Looking for the TRACKER-ASSET server...");

        var serverUrl = await Task.Run(DiscoverServerUrl);

        if (serverUrl is null)
        {
            ShowFailure("No TRACKER-ASSET server answered. Start the server (or download this agent from it) and press Retry.");
            return;
        }

        _serverUrl = serverUrl;
        Report(12, $"Server found: {serverUrl}");

        var installed = await Task.Run(() => RunInstall(serverUrl));

        if (installed)
        {
            ShowSuccess();
        }
        else
        {
            ShowFailure(_lastError.Length > 0 ? _lastError : "The installer could not finish.");
        }
    }

    /// <summary>
    /// Server discovery, in order of trust: environment override → command line
    /// (1-click web installer) → address baked into this binary by the download
    /// route → previously installed address → local development ports.
    /// </summary>
    private static string? DiscoverServerUrl()
    {
        foreach (var candidate in CandidateServerUrls())
        {
            if (TestServer(candidate))
            {
                return candidate;
            }
        }

        return null;
    }

    private static IEnumerable<string> CandidateServerUrls()
    {
        var urls = new List<string>();

        void Add(string? url)
        {
            if (string.IsNullOrWhiteSpace(url))
            {
                return;
            }

            var clean = url.Trim().TrimEnd('/');

            if (clean.Length > 0 && !urls.Contains(clean, StringComparer.OrdinalIgnoreCase))
            {
                urls.Add(clean);
            }
        }

        Add(Environment.GetEnvironmentVariable("TRACKER_API_URL"));
        Add(CommandLineServerUrl());

        foreach (var baked in AgentConfigTrailer.TryRead()?.ServerUrls ?? new List<string>())
        {
            Add(baked);
        }

        Add(ReadRegistryString("ServerUrl"));

        foreach (var port in new[] { 8000, 8003, 8004 })
        {
            Add($"http://127.0.0.1:{port}");
            Add($"http://localhost:{port}");
        }

        return urls;
    }

    private static string? CommandLineServerUrl()
    {
        var args = Environment.GetCommandLineArgs();

        for (var i = 0; i < args.Length - 1; i++)
        {
            if (args[i].Equals("--server-url", StringComparison.OrdinalIgnoreCase) ||
                args[i].Equals("-s", StringComparison.OrdinalIgnoreCase))
            {
                return args[i + 1];
            }
        }

        return null;
    }

    private static bool TestServer(string url)
    {
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
            using var response = http.GetAsync($"{url}/api/health").GetAwaiter().GetResult();

            return response.IsSuccessStatusCode;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// The whole install in one pass: files, device identity, registration,
    /// autostart, resident background agent and a first hardware scan so the
    /// device appears in Master Assets and Live Fleet right away.
    /// </summary>
    private bool RunInstall(string serverUrl)
    {
        try
        {
            Report(18, "Creating the installation folder...");
            _installDir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER");
            Directory.CreateDirectory(_installDir);

            Report(32, "Copying agent files...");
            var sourceExe = Environment.ProcessPath;
            var destExe = Path.Combine(_installDir, "TrackerAgent.exe");

            if (sourceExe is not null && File.Exists(sourceExe) &&
                !string.Equals(sourceExe, destExe, StringComparison.OrdinalIgnoreCase))
            {
                // A resident agent (leftover daemon, e.g. after config.json was
                // removed to test a fresh install) locks its own exe and the
                // copy would fail with a sharing violation. Stop it first, then
                // copy with the bounded retry used by the update path.
                StopInstalledAgent();
                CopyWithRetry(sourceExe, destExe);
            }

            Report(46, "Generating the device identity...");
            var existingId = ReadRegistryString("DeviceId");
            _deviceId = string.IsNullOrWhiteSpace(existingId)
                ? Convert.ToBase64String(GenerateDeviceId())
                : existingId;

            Report(62, "Registering the device with the server...");
            var token = RegisterDevice(serverUrl, ReadRegistryString("Token") ?? string.Empty);

            Report(76, "Configuring autostart...");
            RegisterAutostart(destExe, serverUrl, token);

            Report(86, "Starting the background agent...");
            StartDetached(destExe, $"--daemon --url \"{serverUrl}\" --token \"{token}\" --poll 10");

            Report(94, "Running the first hardware scan...");
            StartDetached(destExe, $"--url \"{serverUrl}\" --token \"{token}\"");

            Report(100, "Installation complete");
            Thread.Sleep(250);
            return true;
        }
        catch (Exception ex)
        {
            _lastError = ex.Message;
            Debug.WriteLine($"Install error: {ex}");
            return false;
        }
    }

    /// <summary>
    /// Registers (or re-registers) this device and persists the settings. The
    /// registration endpoint is public and idempotent per device_id, so running
    /// the installer twice never creates a second token.
    /// </summary>
    private string RegisterDevice(string serverUrl, string fallbackToken)
    {
        var token = fallbackToken;

        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };

            var payload = JsonSerializer.Serialize(new
            {
                device_id = _deviceId,
                name = Environment.MachineName,
                platform = Environment.OSVersion.VersionString,
            });

            using var content = new StringContent(payload, System.Text.Encoding.UTF8, "application/json");
            using var response = http.PostAsync($"{serverUrl}/api/agent/register", content)
                .GetAwaiter()
                .GetResult();

            if (response.IsSuccessStatusCode)
            {
                var body = response.Content.ReadAsStringAsync().GetAwaiter().GetResult();
                using var doc = JsonDocument.Parse(body);

                if (doc.RootElement.TryGetProperty("token", out var issued) &&
                    issued.ValueKind == JsonValueKind.String)
                {
                    token = issued.GetString() ?? token;
                }
            }
        }
        catch (Exception ex)
        {
            // Server unreachable: keep the previous token and let the resident
            // agent retry on its next poll instead of failing the install.
            Debug.WriteLine($"Registration failed: {ex.Message}");
        }

        SaveConfig(serverUrl, token);
        return token;
    }

    private void SaveConfig(string serverUrl, string token)
    {
        try
        {
            File.WriteAllText(
                Path.Combine(_installDir, "config.json"),
                JsonSerializer.Serialize(new
                {
                    device_id = _deviceId,
                    token,
                    server_url = serverUrl,
                    installed_at = DateTime.Now.ToString("o"),
                }, new JsonSerializerOptions { WriteIndented = true }));
        }
        catch { }

        try
        {
            using var key = Registry.CurrentUser.CreateSubKey(@"Software\Mito\AuditTracker");
            key?.SetValue("DeviceId", _deviceId);
            key?.SetValue("Token", token);
            key?.SetValue("ServerUrl", serverUrl);
            key?.SetValue("InstalledAt", DateTime.Now.ToString("o"));
        }
        catch { }
    }

    private static void RegisterAutostart(string exePath, string serverUrl, string token)
    {
        try
        {
            using var key = Registry.CurrentUser.CreateSubKey(
                @"Software\Microsoft\Windows\CurrentVersion\Run");

            key?.SetValue("MitoAuditTracker",
                $"\"{exePath}\" --daemon --url \"{serverUrl}\" --token \"{token}\" --poll 10");
        }
        catch { }
    }

    /// <summary>Starts an agent process without showing a console window.</summary>
    private static void StartDetached(string exePath, string arguments)
    {
        try
        {
            Process.Start(new ProcessStartInfo
            {
                FileName = exePath,
                Arguments = arguments,
                UseShellExecute = true,
                WindowStyle = ProcessWindowStyle.Hidden,
                CreateNoWindow = true,
            });
        }
        catch { }
    }

    private static string? ReadRegistryString(string name)
    {
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey(@"Software\Mito\AuditTracker");

            return key?.GetValue(name) as string;
        }
        catch
        {
            return null;
        }
    }

    private static byte[] GenerateDeviceId()
    {
        var id = new byte[32];
        Random.Shared.NextBytes(id);
        return id;
    }

    /// <summary>
    /// In-place update chosen by the double-click state check: stop the agent
    /// running from the installed path, swap in the clicked build (config and
    /// device identity stay untouched), then restart what was running before.
    /// </summary>
    private async Task AutoUpdateAsync()
    {
        Report(2, $"Update {_installedVersion} to {_newVersion}: preparing...");

        var updated = await Task.Run(RunUpdate);

        if (updated)
        {
            ShowUpdateSuccess();
        }
        else
        {
            ShowFailure(_lastError.Length > 0 ? _lastError : "The update could not finish.");
        }
    }

    private bool RunUpdate()
    {
        try
        {
            var sourceExe = Environment.ProcessPath;

            if (sourceExe is null || !File.Exists(sourceExe))
            {
                throw new FileNotFoundException("The new agent executable could not be located.");
            }

            if (InstallCheck.IsSameFile(sourceExe, _installedExe))
            {
                throw new InvalidOperationException(
                    "This file is the installed application itself - no other build was clicked.");
            }

            Report(25, "Stopping the running background agent...");
            var restart = StopInstalledAgent();

            Report(55, $"Copying version {_newVersion}...");
            CopyWithRetry(sourceExe, _installedExe);

            Report(80, "Restarting the background agent...");
            RestartAgent(restart);

            Report(100, "Update complete");
            Thread.Sleep(250);
            return true;
        }
        catch (Exception ex)
        {
            _lastError = ex.Message;
            Debug.WriteLine($"Update error: {ex}");
            return false;
        }
    }

    /// <summary>
    /// Kills every agent process running from the installed path (resident
    /// daemon and/or an open tray window) while remembering their command
    /// lines, so the update can bring them back exactly as they were.
    /// Returns null when the process list could not be read - the caller then
    /// falls back to a config-based daemon restart.
    /// </summary>
    private static List<string>? StopInstalledAgent()
    {
        try
        {
            var commandLines = new List<string>();

            using var searcher = new ManagementObjectSearcher(
                "SELECT CommandLine, ExecutablePath, ProcessId FROM Win32_Process WHERE Name = 'TrackerAgent.exe'");

            foreach (var entry in searcher.Get())
            {
                using var process = (ManagementObject)entry;

                if (!InstallCheck.IsSameFile(
                        process["ExecutablePath"] as string, InstallCheck.InstalledExe))
                {
                    continue;
                }

                if (process["CommandLine"] is string commandLine &&
                    !string.IsNullOrWhiteSpace(commandLine))
                {
                    commandLines.Add(commandLine);
                }

                if (process["ProcessId"] is uint pid && pid != 0 &&
                    pid != Environment.ProcessId)
                {
                    try
                    {
                        using var target = Process.GetProcessById((int)pid);
                        target.Kill(entireProcessTree: true);
                        target.WaitForExit(3000);
                    }
                    catch { }
                }
            }

            return commandLines;
        }
        catch
        {
            // WMI unavailable: still kill by path via the process API, but the
            // caller cannot replay the exact command lines anymore.
            foreach (var process in Process.GetProcessesByName("TrackerAgent"))
            {
                try
                {
                    if (process.Id == Environment.ProcessId)
                    {
                        continue;
                    }

                    if (!InstallCheck.IsSameFile(
                            process.MainModule?.FileName, InstallCheck.InstalledExe))
                    {
                        continue;
                    }

                    process.Kill(entireProcessTree: true);
                    process.WaitForExit(3000);
                }
                catch { }
                finally { process.Dispose(); }
            }

            return null;
        }
    }

    /// <summary>
    /// Restarts what <see cref="StopInstalledAgent"/> stopped: replay the
    /// captured command lines, or - when the process list was unreadable -
    /// bring the daemon back from config.json so the device keeps reporting.
    /// </summary>
    private static void RestartAgent(List<string>? captured)
    {
        if (captured != null)
        {
            foreach (var commandLine in captured)
            {
                StartDetached(InstallCheck.InstalledExe, ExtractArguments(commandLine));
            }

            return;
        }

        try
        {
            if (!File.Exists(InstallCheck.InstalledConfig))
            {
                return;
            }

            using var doc = JsonDocument.Parse(File.ReadAllText(InstallCheck.InstalledConfig));
            var token = doc.RootElement.TryGetProperty("token", out var t) ? t.GetString() : null;
            var serverUrl = doc.RootElement.TryGetProperty("server_url", out var s) ? s.GetString() : null;

            if (string.IsNullOrWhiteSpace(token) || string.IsNullOrWhiteSpace(serverUrl))
            {
                return;
            }

            StartDetached(InstallCheck.InstalledExe,
                $"--daemon --url \"{serverUrl}\" --token \"{token}\" --poll 10");
        }
        catch { }
    }

    /// <summary>Strips the leading executable from a captured command line.</summary>
    private static string ExtractArguments(string commandLine)
    {
        var line = commandLine.Trim();

        if (line.StartsWith("\""))
        {
            var end = line.IndexOf('"', 1);
            return end >= 0 ? line[(end + 1)..].TrimStart() : "";
        }

        var space = line.IndexOf(' ');
        return space >= 0 ? line[(space + 1)..].TrimStart() : "";
    }

    /// <summary>
    /// File.Copy with retries: the just-killed process may need a moment to
    /// release the installed executable.
    /// </summary>
    private static void CopyWithRetry(string source, string destination)
    {
        IOException? last = null;

        for (var attempt = 0; attempt < 10; attempt++)
        {
            try
            {
                File.Copy(source, destination, overwrite: true);
                return;
            }
            catch (IOException ex)
            {
                last = ex;
                Thread.Sleep(300);
            }
        }

        throw last!;
    }

    /// <summary>Clicked build == installed build: show the notice, do nothing.</summary>
    private void ShowAlreadyInstalled()
    {
        StatusPanel.Visibility = Visibility.Collapsed;
        ResultPanel.Visibility = Visibility.Visible;
        RetryButton.Visibility = Visibility.Collapsed;
        CloseButton.Visibility = Visibility.Visible;

        SetResult(
            GlyphOk,
            "Aplikasi Sudah Pernah Diinstall",
            $"Versi {_installedVersion} sudah terpasang di mesin ini.\n\n" +
            $"{_installedExe}\n\n" +
            "Tidak ada yang perlu dilakukan. Jalankan installer versi lebih baru untuk memperbarui.",
            SuccessBrush);
    }

    /// <summary>Swap finished: report the version jump, then auto-close.</summary>
    private void ShowUpdateSuccess()
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            EndShimmer();

            StatusPanel.Visibility = Visibility.Collapsed;
            ResultPanel.Visibility = Visibility.Visible;

            SetResult(
                GlyphOk,
                "Pembaruan Selesai",
                $"Versi {_installedVersion} → {_newVersion}\n\n" +
                $"Dipasang ke {_installDir}\n\n" +
                "Agent latar sudah dijalankan ulang — perangkat tetap melapor.",
                SuccessBrush);

            _autoCloseTimer.Interval = TimeSpan.FromSeconds(2.5);
            _autoCloseTimer.Tick += (_, _) =>
            {
                _autoCloseTimer.Stop();
                Close();
            };
            _autoCloseTimer.Start();
        });
    }

    private void ShowSuccess()
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            EndShimmer();

            StatusPanel.Visibility = Visibility.Collapsed;
            ResultPanel.Visibility = Visibility.Visible;
            SetResult(
                GlyphOk,
                "Installation complete",
                $"Agent installed to {_installDir}\n\n" +
                $"Connected to {_serverUrl}\n\n" +
                "The device is reporting now — it appears in Master Assets and Live Fleet.",
                SuccessBrush);

            // Nothing to click on success: close the window after a moment.
            _autoCloseTimer.Interval = TimeSpan.FromSeconds(2.5);
            _autoCloseTimer.Tick += (_, _) =>
            {
                _autoCloseTimer.Stop();
                Close();
            };
            _autoCloseTimer.Start();
        });
    }

    private void ShowFailure(string message)
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            EndShimmer();

            StatusPanel.Visibility = Visibility.Collapsed;
            ResultPanel.Visibility = Visibility.Visible;
            RetryButton.Visibility = Visibility.Visible;
            CloseButton.Visibility = Visibility.Visible;

            SetResult(
                GlyphError,
                _mode == InstallerMode.Update ? "Update failed" : "Installation failed",
                message,
                ErrorBrush);
        });
    }

    /// <summary>Segoe MDL2 glyphs used by the result badge.</summary>
    private const string GlyphOk = "\uE73E";   // Accept (check)
    private const string GlyphError = "\uE783"; // Error (cross in circle)

    private static readonly System.Windows.Media.Brush SuccessBrush =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0x4A, 0xDE, 0x80));
    private static readonly System.Windows.Media.Brush ErrorBrush =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0xF8, 0x71, 0x71));

    /// <summary>
    /// Fills the result card in one place. The badge is filled with a
    /// low-alpha tint of the accent (via <see cref="SetBadgeTint"/>) so the
    /// ring, the wash behind it and the glyph always agree on colour.
    /// </summary>
    private void SetResult(string glyph, string title, string detail, System.Windows.Media.Brush accent)
    {
        ResultIcon.Text = glyph;
        ResultIcon.Foreground = accent;
        SetBadgeTint(accent);
        ResultText.Text = title;
        ResultDetail.Text = detail;
    }

    /// <summary>
    /// Tints the badge from the accent colour: a low-alpha fill behind the glyph
    /// and a brighter ring around it. Built as brushes rather than by setting
    /// <c>Opacity</c> on the badge - opacity on a parent also fades its children,
    /// which would wash the glyph out almost completely.
    /// </summary>
    private void SetBadgeTint(System.Windows.Media.Brush accent)
    {
        var rgb = accent is SolidColorBrush solid
            ? solid.Color
            : System.Windows.Media.Colors.White;

        ResultIconBadge.Background = new SolidColorBrush(
            System.Windows.Media.Color.FromArgb((byte)0x26, rgb.R, rgb.G, rgb.B));
        ResultIconBadge.BorderBrush = new SolidColorBrush(
            System.Windows.Media.Color.FromArgb((byte)0x66, rgb.R, rgb.G, rgb.B));
    }

    private async void RetryButton_Click(object sender, RoutedEventArgs e)
    {
        RetryButton.Visibility = Visibility.Collapsed;
        CloseButton.Visibility = Visibility.Collapsed;
        ResultPanel.Visibility = Visibility.Collapsed;
        StatusPanel.Visibility = Visibility.Visible;

        _targetProgress = 0;
        _displayedProgress = 0;
        ProgressBar.Value = 0;
        PercentText.Text = "0%";

        _progressTimer.Start();
        BeginShimmer();

        if (_mode == InstallerMode.Update)
        {
            await AutoUpdateAsync();
        }
        else
        {
            await AutoInstallAsync();
        }
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
