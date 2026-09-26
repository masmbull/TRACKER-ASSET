using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net.Http;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Threading;
using Microsoft.Win32;
using WpfTextBox = System.Windows.Controls.TextBox;
using WpfStackPanel = System.Windows.Controls.StackPanel;
using WpfOrientation = System.Windows.Controls.Orientation;
using WpfMessageBox = System.Windows.MessageBox;
using WpfMessageBoxButton = System.Windows.MessageBoxButton;
using WpfMessageBoxImage = System.Windows.MessageBoxImage;
using WpfBrushes = System.Windows.Media.Brushes;

namespace TrackerAgent;

public partial class InstallerWindow : Window
{
    private readonly DispatcherTimer _timer = new();
    private int _progress;
    private string _stepMessage = "";
    private string _installDir = "";
    private string _serverUrl = "";
    private string _deviceId = "";

    public InstallerWindow()
    {
        InitializeComponent();
        ResultPanel.Visibility = Visibility.Collapsed;
        CloseButton.Visibility = Visibility.Collapsed;
        
        // Check for server URL passed via command line
        var args = Environment.GetCommandLineArgs();
        for (int i = 0; i < args.Length - 1; i++)
        {
            if (args[i].Equals("--server-url", StringComparison.OrdinalIgnoreCase) ||
                args[i].Equals("-s", StringComparison.OrdinalIgnoreCase))
            {
                _serverUrl = args[i + 1];
                break;
            }
        }

        _ = AutoDetectAndInstallAsync();
    }

    private async Task AutoDetectAndInstallAsync()
    {
        if (!string.IsNullOrWhiteSpace(_serverUrl))
        {
            await TryInstallAsync(_serverUrl);
            return;
        }

        // Auto-detect common server URLs
        var candidateUrls = new[]
        {
            "http://127.0.0.1:8000",
            "http://127.0.0.1:8003",
            "http://127.0.0.1:8004",
            "http://localhost:8000",
            "http://localhost:8003",
            "http://localhost:8004",
        };

        foreach (var url in candidateUrls)
        {
            UpdateStatus(10, $"Trying {url}...");
            if (await TestServerAsync(url))
            {
                _serverUrl = url;
                await TryInstallAsync(url);
                return;
            }
        }

        // If auto-detect fails, show manual entry (fallback)
        ShowManualUrlEntry();
    }

    private async Task<bool> TestServerAsync(string url)
    {
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
            var resp = await http.GetAsync($"{url}/api/health");
            return resp.IsSuccessStatusCode;
        }
        catch
        {
            return false;
        }
    }

    private async Task TryInstallAsync(string url)
    {
        UpdateStatus(20, $"Connecting to {url}...");

        var success = await Task.Run(() => RunInstall(url));

        StatusPanel.Visibility = Visibility.Collapsed;
        ResultPanel.Visibility = Visibility.Visible;
        CloseButton.Visibility = Visibility.Visible;
        InstallButton.Visibility = Visibility.Collapsed;

        if (success)
        {
            ResultIcon.Text = "OK";
            ResultIcon.Foreground = WpfBrushes.LightGreen;
            ResultText.Text = "Installation Complete!";
            ResultDetail.Text = $"Agent installed to:\n{_installDir}\n\nThe agent is now running and registered for autostart.";
        }
        else
        {
            ResultIcon.Text = "X";
            ResultIcon.Foreground = WpfBrushes.OrangeRed;
            ResultText.Text = "Installation Failed";
            ResultDetail.Text = "Could not register with server. Please ensure the server is online and try again.";
        }
    }

    private void ShowManualUrlEntry()
    {
        // Add a manual URL entry TextBox dynamically
        var urlBox = new WpfTextBox
        {
            Name = "UrlBox",
            Background = WpfBrushes.White,
            Foreground = WpfBrushes.Black,
            BorderBrush = WpfBrushes.Gray,
            Padding = new Thickness(8, 6, 8, 6),
            FontSize = 13,
            Margin = new Thickness(0, 0, 0, 10),
            Text = "http://127.0.0.1:8000"
        };

        var label = new TextBlock
        {
            Text = "Auto-detect failed. Enter server URL manually:",
            Foreground = WpfBrushes.LightGray,
            FontSize = 12,
            Margin = new Thickness(0, 0, 0, 4)
        };

        var panel = new WpfStackPanel { Orientation = WpfOrientation.Vertical };
        panel.Children.Add(label);
        panel.Children.Add(urlBox);

        // Replace status panel with manual entry
        StatusPanel.Children.Clear();
        StatusPanel.Children.Add(panel);
        ProgressBar.IsIndeterminate = false;
        ProgressBar.Value = 0;
        PercentText.Text = "";
        StatusText.Text = "Enter server URL above and click Install";
        InstallButton.IsEnabled = true;

        // Store reference for later use
        StatusPanel.Tag = urlBox;
    }

    private async void InstallButton_Click(object sender, RoutedEventArgs e)
    {
        InstallButton.IsEnabled = false;

        string url;
        if (StatusPanel.Tag is WpfTextBox manualBox)
        {
            url = manualBox.Text.Trim();
        }
        else
        {
            url = _serverUrl;
        }

        if (string.IsNullOrWhiteSpace(url))
        {
            WpfMessageBox.Show("Please enter a server URL", "Error", WpfMessageBoxButton.OK, WpfMessageBoxImage.Error);
            InstallButton.IsEnabled = true;
            return;
        }

        await TryInstallAsync(url);
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private bool RunInstall(string serverUrl)
    {
        try
        {
            UpdateStatus(5, "Initializing Mito Audit Tracker...");

            UpdateStatus(15, "Creating installation directory...");
            _installDir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER");
            Directory.CreateDirectory(_installDir);

            UpdateStatus(30, "Copying agent files...");
            var sourceExe = Environment.ProcessPath;
            var destExe = Path.Combine(_installDir, "TrackerAgent.exe");
            if (sourceExe != null && File.Exists(sourceExe))
            {
                File.Copy(sourceExe, destExe, overwrite: true);
            }

            UpdateStatus(50, "Generating device identity...");
            _deviceId = Convert.ToBase64String(GenerateDeviceId());
            var token = _deviceId;

            UpdateStatus(70, "Registering device with server...");
            token = RegisterDevice(serverUrl, _deviceId);

            UpdateStatus(85, "Configuring autostart and settings...");
            RegisterAutostart(destExe, serverUrl, token);

            UpdateStatus(100, "Done!");
            Thread.Sleep(400);
            return true;
        }
        catch (Exception ex)
        {
            System.Diagnostics.Debug.WriteLine($"Install error: {ex.Message}");
            return false;
        }
    }

    private string RegisterDevice(string serverUrl, string token)
    {
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
            http.DefaultRequestHeaders.Authorization = new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", token);

            var payload = new
            {
                device_id = _deviceId,
                name = Environment.MachineName,
                platform = Environment.OSVersion.VersionString
            };

            var json = JsonSerializer.Serialize(payload);
            var content = new StringContent(json, System.Text.Encoding.UTF8, "application/json");

            var resp = http.PostAsync($"{serverUrl}/api/agent/register", content).GetAwaiter().GetResult();

            if (resp.IsSuccessStatusCode)
            {
                var responseJson = resp.Content.ReadAsStringAsync().GetAwaiter().GetResult();
                var doc = JsonDocument.Parse(responseJson);
                if (doc.RootElement.TryGetProperty("token", out var newToken))
                {
                    token = newToken.GetString() ?? token;
                }
            }

            SaveConfig(serverUrl, token);
            return token;
        }
        catch
        {
            // Save config even if registration fails - agent will retry on startup
            SaveConfig(serverUrl, token);
            return token;
        }
    }

    private static byte[] GenerateDeviceId()
    {
        var id = new byte[32];
        Random.Shared.NextBytes(id);
        return id;
    }

    private void SaveConfig(string serverUrl, string token)
    {
        var configPath = Path.Combine(_installDir, "config.json");
        File.WriteAllText(configPath, JsonSerializer.Serialize(new
        {
            device_id = _deviceId,
            token = token,
            server_url = serverUrl,
            installed_at = DateTime.Now.ToString("o")
        }, new JsonSerializerOptions { WriteIndented = true }));

        SaveToRegistry(serverUrl, token);
    }

    private void SaveToRegistry(string serverUrl, string token)
    {
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey(@"Software\Mito\AuditTracker", true)
                     ?? Registry.CurrentUser.CreateSubKey(@"Software\Mito\AuditTracker");
            key?.SetValue("DeviceId", _deviceId);
            key?.SetValue("Token", token);
            key?.SetValue("ServerUrl", serverUrl);
            key?.SetValue("InstalledAt", DateTime.Now.ToString("o"));
        }
        catch { }
    }

    private void RegisterAutostart(string exePath, string serverUrl, string token)
    {
        try
        {
            var runKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
            using var key = Registry.CurrentUser.CreateSubKey(runKey);
            // Autostart with --daemon flag
            key?.SetValue("MitoAuditTracker", $"\"{exePath}\" --daemon --url \"{serverUrl}\" --token \"{token}\" --poll 10");
        }
        catch { }
    }

    private void UpdateStatus(int percent, string message)
    {
        _progress = percent;
        _stepMessage = message;

        Dispatcher.BeginInvoke(() =>
        {
            ProgressBar.Value = _progress;
            PercentText.Text = $"{_progress}%";
            StatusText.Text = _stepMessage;
        });

        Thread.Sleep(100);
    }
}
