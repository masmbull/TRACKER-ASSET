using System.Diagnostics;
using System.IO;
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

    public InstallerWindow()
    {
        InitializeComponent();

        ResultPanel.Visibility = Visibility.Collapsed;
        RetryButton.Visibility = Visibility.Collapsed;
        CloseButton.Visibility = Visibility.Collapsed;

        StartProgressAnimation();
        BeginShimmer();

        _ = AutoInstallAsync();
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
                File.Copy(sourceExe, destExe, overwrite: true);
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

    private void ShowSuccess()
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            EndShimmer();

            StatusPanel.Visibility = Visibility.Collapsed;
            ResultPanel.Visibility = Visibility.Visible;
            ResultIcon.Text = "OK";
            ResultIcon.Foreground = System.Windows.Media.Brushes.LightGreen;
            ResultText.Text = "Installation complete";
            ResultDetail.Text =
                $"Agent installed to {_installDir}\n" +
                $"Connected to {_serverUrl}\n\n" +
                "The device is reporting now — it appears in Master Assets and Live Fleet.";

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

            ResultIcon.Text = "X";
            ResultIcon.Foreground = System.Windows.Media.Brushes.OrangeRed;
            ResultText.Text = "Installation failed";
            ResultDetail.Text = message;
        });
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

        await AutoInstallAsync();
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
