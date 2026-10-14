using System.Diagnostics;
using System.IO;
using System.Management;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Interop;
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

    /// <summary>
    /// Token obtained during this install, kept so a later failure can be reported.
    /// </summary>
    /// <remarks>
    /// Empty until registration succeeds, which is exactly the window where it is
    /// useless: before registration there is no identity to report against, and
    /// after it there always is. The catch block checks it rather than assuming a
    /// token, so a failure during device discovery is not reported against a
    /// device that was never created.
    /// </remarks>
    private string _installTokenForReporting = string.Empty;

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
                SubtitleText.Text = "Already installed";
                ShowAlreadyInstalled();
                EnsureInstalledTrayIsRunning();
                break;

            case InstallerMode.Update:
                SubtitleText.Text = "Updating agent...";
                StartProgressAnimation();
                BeginShimmer();
                _ = AutoUpdateAsync();
                break;

            case InstallerMode.CheckForUpdates:
                SubtitleText.Text = "Checking for updates...";
                StartProgressAnimation();
                BeginShimmer();
                _ = CheckForUpdatesAsync();
                break;

            default:
                SubtitleText.Text = "Installing...";
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

            // Width taken from the track itself, not a hard-coded 400: the bar used
            // to stop short of the end because the constant no longer matched the
            // track it was drawn into, so the install looked stuck at ~85% even
            // when it had finished.
            var track = ProgressTrack.ActualWidth;
            ProgressFill.Width = Math.Max(0, Math.Round(track * _displayedProgress / 100.0));
            PercentText.Text = $"{_displayedProgress}%";
        };
        _progressTimer.Start();
    }

    private void BeginShimmer() { }
    private void EndShimmer() { }

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
        Report(2, "Looking for server...");

        var serverDiscoveryTask = Task.Run(DiscoverServerUrl);
        var timeoutTask = Task.Delay(TimeSpan.FromSeconds(30)); // 30 second overall timeout

        var completedTask = await Task.WhenAny(serverDiscoveryTask, timeoutTask);

        if (completedTask == timeoutTask)
        {
            ShowFailure("Server discovery timed out. Check network connection or provide server URL manually.");
            return;
        }

        var serverUrl = await serverDiscoveryTask;

        if (serverUrl is null)
        {
            ShowFailure("No TRACKER-ASSET server found. Ensure server is running or download from it.");
            return;
        }

        _serverUrl = serverUrl;
        Report(12, $"Server found");

        var installed = await Task.Run(() => RunInstall(serverUrl));

        if (installed)
        {
            ShowSuccess();
        }
        else
        {
            ShowFailure(_lastError.Length > 0 ? _lastError : "Installation failed.");
        }
    }

    private async Task CheckForUpdatesAsync()
    {
        Report(5, "Checking server...");

        var serverDiscoveryTask = Task.Run(DiscoverServerUrl);
        var timeoutTask = Task.Delay(TimeSpan.FromSeconds(30));

        var completedTask = await Task.WhenAny(serverDiscoveryTask, timeoutTask);
        if (completedTask == timeoutTask)
        {
            ShowNoUpdateAvailable("The update check timed out while contacting the TRACKER-ASSET server.");
            return;
        }

        var serverUrl = await serverDiscoveryTask;
        if (string.IsNullOrWhiteSpace(serverUrl))
        {
            ShowNoUpdateAvailable("No TRACKER-ASSET server was reachable, so an update check could not be completed.");
            return;
        }

        _serverUrl = serverUrl;
        Report(35, "Comparing installed version with the latest build...");

        var token = ReadTokenFromConfig();
        var hasUpdate = AutoUpdater.CheckForUpdate(serverUrl, token ?? string.Empty, out var message);

        if (hasUpdate)
        {
            Report(100, "Update available");
            ShowUpdateAvailable(message);
            return;
        }

        Report(100, "No update available");
        ShowNoUpdateAvailable(message);
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
    /// autostart, a visible tray agent and a queued first hardware scan so the
    /// device appears in Live Fleet right away.
    /// </summary>
    /// <remarks>
    /// The scan does not create an asset. A laptop whose serial is not on the
    /// register waits on the fleet page as an unregistered device, and an
    /// administrator adds it from there - that is what the promotion form is
    /// for. This comment used to claim the device appeared in Master Assets,
    /// which stopped being true when auto-creation was removed.
    /// </remarks>
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
            var token = RegisterDevice(serverUrl);

            // Kept so the catch block can report a failure that happened after
            // registration. Without it a crash at the autostart step would leave
            // the fleet page showing an install frozen at its last percentage
            // rather than the actual error.
            _installTokenForReporting = token;

            // From here on there is a token, so the fleet page can watch this
            // machine being built instead of watching nothing. Before this point
            // the steps are a folder and a file copy - fast, and unreportable
            // because no identity exists yet to report against.
            Api.ReportInstallProgress(serverUrl, token, "install", "in_progress", 62,
                "Registering the device with the server...");

            Report(76, "Configuring the agent to run at boot...");
            InstallBootAutostart(serverUrl, token, destExe);
            Api.ReportInstallProgress(serverUrl, token, "install", "in_progress", 76,
                "Configuring the agent to run at boot...");

            Report(86, "Starting the tray agent...");
            Api.ReportInstallProgress(serverUrl, token, "install", "in_progress", 86,
                "Starting the tray agent...");

            Report(94, "Finishing agent setup...");
            Api.ReportInstallProgress(serverUrl, token, "install", "in_progress", 94,
                "Finishing agent setup...");

            Report(100, "Installation complete");
            Api.ReportInstallProgress(serverUrl, token, "install", "done", 100,
                "Installation complete",
                new Dictionary<string, string>
                {
                    ["message"] = "Agent installed; the first hardware scan is queued.",
                });
            Thread.Sleep(250);
            return true;
        }
        catch (Exception ex)
        {
            // Better error messages for common failure modes
            string friendlyMessage = ex switch
            {
                System.IO.IOException => "File operation failed. Check disk space and permissions.",
                System.Net.Http.HttpRequestException => "Cannot reach server. Check network connection.",
                System.Reflection.TargetInvocationException => "Installation script error. Contact support.",
                System.UnauthorizedAccessException => "Permission denied. Run as Administrator.",
                _ => ex.Message
            };

            _lastError = friendlyMessage;
            Debug.WriteLine($"Install error: {ex}");

            // Reported with whatever token was obtained, if any. A failed install
            // that leaves no trace on the server is the worst outcome: the machine
            // is broken and the fleet page shows nothing about it.
            if (!string.IsNullOrWhiteSpace(_installTokenForReporting))
            {
                Api.ReportInstallProgress(serverUrl, _installTokenForReporting, "install", "failed", 0,
                    $"Installation failed: {friendlyMessage}",
                    new Dictionary<string, string> { ["message"] = ex.GetType().Name, ["detail"] = ex.Message });
            }

            return false;
        }
    }

    /// <summary>
    /// Registers (or re-registers) this device and persists the settings. The
    /// registration endpoint is public and idempotent per device_id, so running
    /// the installer twice never creates a second token.
    /// </summary>
    private string RegisterDevice(string serverUrl)
    {
        var token = string.Empty;

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
                    token = issued.GetString() ?? string.Empty;
                }
            }
            else
            {
                var message = response.Content.ReadAsStringAsync().GetAwaiter().GetResult();
                throw new InvalidOperationException(
                    $"Device registration was rejected (HTTP {(int)response.StatusCode}): {message}");
            }

            if (string.IsNullOrWhiteSpace(token))
            {
                throw new InvalidOperationException("The server did not issue an agent token.");
            }
        }
        catch (Exception ex)
        {
            // Do not report success with an empty or rejected credential: that
            // leaves a locally installed agent which can never appear in Fleet.
            Debug.WriteLine($"Registration failed: {ex.Message}");
            _lastError = ex.Message;
            throw;
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

    /// <summary>
    /// Arrange for the daemon to run at boot, preferring the SYSTEM task so a
    /// pushed `run_as: system` script can write HKLM.
    /// </summary>
    /// <remarks>
    /// The installer usually runs as the logged-in user, who is a standard user
    /// on these machines - so it cannot create the SYSTEM task itself. It asks
    /// for one UAC elevation (the launched copy re-runs `--install-service` with
    /// the registered token), and only falls back to the per-user autostart when
    /// that is declined or unavailable. The fallback still gives a working fleet
    /// agent; it just cannot run machine-level scripts, which is exactly the
    /// limitation the SYSTEM task removes.
    /// </remarks>
    private void InstallBootAutostart(string serverUrl, string token, string exePath)
    {
        var elevated = TryInstallSystemTask(serverUrl, token, exePath);

        if (elevated)
        {
            return;
        }

        // Not elevated: keep the agent running and reporting. The SYSTEM task can
        // be added later by reinstalling elevated, without touching the agent.
        RegisterAutostart(exePath);
    }

    /// <summary>
    /// Launch the installed exe with `--install-service` under a UAC prompt and
    /// wait for it. Returns true only when the task was actually registered.
    /// </summary>
    private static bool TryInstallSystemTask(string serverUrl, string token, string exePath)
    {
        try
        {
            var args = $"--install-service --url \"{serverUrl}\" --token \"{token}\"";

            var psi = new ProcessStartInfo
            {
                FileName = exePath,
                Arguments = args,
                // ShellExecute is what triggers the UAC prompt; without it the
                // child inherits our (non-elevated) token and schtasks fails.
                UseShellExecute = true,
                Verb = "runas",
                WindowStyle = ProcessWindowStyle.Hidden,
            };

            using var process = Process.Start(psi);

            if (process is null)
            {
                return false;
            }

            // Bounded wait: a UAC prompt the user never answers must not freeze
            // the install. The task is registered in well under this when granted.
            process.WaitForExit(60_000);

            return process.HasExited && process.ExitCode == 0;
        }
        catch
        {
            // Declined UAC (Win32Exception 1223) or no elevation available: fall
            // back to the per-user autostart. Not an install failure.
            return false;
        }
    }

    private static void RegisterAutostart(string exePath)
    {
        try
        {
            using var key = Registry.CurrentUser.CreateSubKey(
                @"Software\Microsoft\Windows\CurrentVersion\Run");

            key?.SetValue("MitoAuditTracker", InstallCheck.BuildTrayAutostartCommand(exePath));
        }
        catch { }
    }

    /// <summary>Starts an agent process detached from this one.</summary>
    /// <param name="exePath">Full path to TrackerAgent.exe.</param>
    /// <param name="arguments">CLI arguments, or empty string for tray/GUI mode.</param>
    private static void StartDetached(string exePath, string arguments)
    {
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName         = exePath,
                UseShellExecute  = false,
                CreateNoWindow   = false,
            };

            if (!string.IsNullOrWhiteSpace(arguments))
                psi.Arguments = arguments;

            Process.Start(psi);
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
        Report(2, $"Preparing update...");

        var updated = await Task.Run(RunUpdate);

        if (updated)
        {
            ShowUpdateSuccess();
        }
        else
        {
            ShowFailure(_lastError.Length > 0 ? _lastError : "Update failed.");
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
            StopInstalledAgent();

            Report(55, $"Copying version {_newVersion}...");
            CopyWithRetry(sourceExe, _installedExe);

            Report(80, "Restarting the tray agent...");
            RestartAgent();

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
    /// Stops every agent process running from the installed path before the
    /// binary is replaced.
    /// </summary>
    private static void StopInstalledAgent()
    {
        try
        {
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

        }
        catch
        {
            // WMI unavailable: still kill by path via the process API.
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

        }
    }

    /// <summary>
    /// Starts without CLI flags so the installed config launches the tray and
    /// resumes fleet reporting.
    /// </summary>
    private static void RestartAgent()
    {
        RegisterAutostart(InstallCheck.InstalledExe);
        StartDetached(InstallCheck.InstalledExe, string.Empty);
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

    /// <summary>Clicked build == installed build: ensure the tray agent is running.</summary>
    private void ShowAlreadyInstalled()
    {
        StatusPanel.Visibility = Visibility.Collapsed;
        ResultPanel.Visibility = Visibility.Visible;
        RetryButton.Visibility = Visibility.Collapsed;
        CloseButton.Visibility = Visibility.Visible;

        SetResult(
            GlyphInfo,
            "Already Installed",
            $"Version {_installedVersion} is installed on this machine.\n\n" +
            $"{_installedExe}\n\n" +
            "The tray agent has been started or was already running. To update, download and run a newer version.",
            InfoBrush);
    }

    private void EnsureInstalledTrayIsRunning()
    {
        RegisterAutostart(_installedExe);

        var trayIsRunning = false;

        try
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT CommandLine, ExecutablePath, ProcessId FROM Win32_Process WHERE Name = 'TrackerAgent.exe'");

            foreach (var entry in searcher.Get())
            {
                using var process = (ManagementObject)entry;

                if (!InstallCheck.IsSameFile(process["ExecutablePath"] as string, _installedExe))
                {
                    continue;
                }

                var commandLine = process["CommandLine"] as string ?? string.Empty;

                if (commandLine.Contains("--daemon", StringComparison.OrdinalIgnoreCase))
                {
                    if (process["ProcessId"] is uint processId && processId != 0)
                    {
                        try
                        {
                            using var daemon = Process.GetProcessById((int)processId);
                            daemon.Kill(entireProcessTree: true);
                            daemon.WaitForExit(3000);
                        }
                        catch { }
                    }

                    continue;
                }

                trayIsRunning = true;
            }
        }
        catch (Exception ex)
        {
            Debug.WriteLine($"Could not inspect running agent processes: {ex.Message}");
        }

        if (!trayIsRunning)
        {
            StartDetached(_installedExe, string.Empty);
        }
    }

    /// <summary>Swap finished: report the version jump, then auto-close.</summary>
    private void ShowUpdateSuccess()
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();

            SubtitleText.Text = "Update complete";
            StatusPanel.Visibility = Visibility.Collapsed;
            ResultPanel.Visibility = Visibility.Visible;

            SetResult(
                GlyphOk,
                "Update Complete",
                $"Agent updated successfully.\n\nDevice keeps reporting.",
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

            SubtitleText.Text = "Installation complete";
            StatusPanel.Visibility = Visibility.Collapsed;
            ResultPanel.Visibility = Visibility.Visible;
            SetResult(
                GlyphOk,
                "Installation Complete",
                $"Agent installed to {_installDir}\n\nConnected to {_serverUrl}\n\nDevice is now visible on Live Fleet.",
                SuccessBrush);

            _autoCloseTimer.Interval = TimeSpan.FromSeconds(2);
            _autoCloseTimer.Tick += (_, _) =>
            {
                _autoCloseTimer.Stop();
                Debug.WriteLine("[INSTALL] OnClosed: launching tray agent");
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

            SubtitleText.Text = _mode == InstallerMode.Update ? "Update failed" : "Installation failed";
            StatusPanel.Visibility = Visibility.Collapsed;
            ResultPanel.Visibility = Visibility.Visible;
            RetryButton.Visibility = Visibility.Visible;
            CloseButton.Visibility = Visibility.Visible;

            SetResult(
                GlyphError,
                _mode == InstallerMode.Update ? "Update Failed" : "Installation Failed",
                message,
                ErrorBrush);
        });
    }

    private void ShowUpdateAvailable(string message)
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            SubtitleText.Text = "Update available";
            StatusPanel.Visibility = Visibility.Collapsed;
            ResultPanel.Visibility = Visibility.Visible;
            RetryButton.Visibility = Visibility.Collapsed;
            CloseButton.Visibility = Visibility.Visible;

            SetResult(GlyphInfo, "Update Available", message, InfoBrush);
        });
    }

    private void ShowNoUpdateAvailable(string message)
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            SubtitleText.Text = "Update check complete";
            StatusPanel.Visibility = Visibility.Collapsed;
            ResultPanel.Visibility = Visibility.Visible;
            RetryButton.Visibility = Visibility.Collapsed;
            CloseButton.Visibility = Visibility.Visible;

            SetResult(GlyphOk, "No Update Available", message, SuccessBrush);
        });
    }

    private static string? ReadTokenFromConfig()
    {
        try
        {
            var configPath = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER",
                "config.json");

            if (!File.Exists(configPath))
            {
                return null;
            }

            using var document = JsonDocument.Parse(File.ReadAllText(configPath));
            if (document.RootElement.TryGetProperty("token", out var token) &&
                token.ValueKind == JsonValueKind.String)
            {
                var value = token.GetString();
                return string.IsNullOrWhiteSpace(value) ? null : value;
            }
        }
        catch
        {
            // Best effort only; a missing token should not block the update popup.
        }

        return null;
    }

    /// <summary>Segoe MDL2 glyphs used by the result badge.</summary>
    private const string GlyphOk = "\uE73E";    // Accept (check mark)
    private const string GlyphError = "\uE783"; // Error (circle cross)
    private const string GlyphInfo = "\uE946";  // Info

    private static readonly System.Windows.Media.Brush SuccessBrush =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0x22, 0xC5, 0x5E)); // green-500
    private static readonly System.Windows.Media.Brush ErrorBrush =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44)); // red-500
    private static readonly System.Windows.Media.Brush InfoBrush =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0x4F, 0x8E, 0xF7)); // blue

    /// <summary>
    /// Fills the result card in one place.
    /// </summary>
    private void SetResult(string glyph, string title, string detail, System.Windows.Media.Brush accent)
    {
        ResultIcon.Text = glyph;
        ResultIcon.Foreground = accent;
        ResultText.Text = title;
        ResultDetail.Text = detail;
    }

    private async void RetryButton_Click(object sender, RoutedEventArgs e)
    {
        RetryButton.Visibility = Visibility.Collapsed;
        CloseButton.Visibility = Visibility.Collapsed;
        ResultPanel.Visibility = Visibility.Collapsed;
        StatusPanel.Visibility = Visibility.Visible;

        _targetProgress = 0;
        _displayedProgress = 0;
        ProgressFill.Width = 0;
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

    private void WindowCloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private void TitleBar_MouseLeftButtonDown(object sender, System.Windows.Input.MouseButtonEventArgs e)
    {
        if (e.ButtonState == System.Windows.Input.MouseButtonState.Pressed)
            DragMove();
    }

    // ── Dark title bar (Windows 11) ──────────────────────────────────────────
    // Tells DWM to render the caption buttons and title bar in dark mode so
    // the white chrome doesn't clash with the #0F1117 window background.
    [DllImport("dwmapi.dll")]
    private static extern int DwmSetWindowAttribute(IntPtr hwnd, int attr, ref int value, int size);

    protected override void OnSourceInitialized(EventArgs e)
    {
        base.OnSourceInitialized(e);
        var hwnd  = new WindowInteropHelper(this).Handle;
        var value = 1; // DWMWA_USE_IMMERSIVE_DARK_MODE
        DwmSetWindowAttribute(hwnd, 20, ref value, sizeof(int));
    }

    /// <summary>
    /// After a successful install, launch the installed exe in tray mode once
    /// this installer window is fully closed and its file lock released.
    /// </summary>
    protected override void OnClosed(EventArgs e)
    {
        base.OnClosed(e);

        if (_mode == InstallerMode.Install && _installDir.Length > 0 &&
            !string.IsNullOrWhiteSpace(_installTokenForReporting))
        {
            var installedExe = System.IO.Path.Combine(_installDir, "TrackerAgent.exe");
            if (System.IO.File.Exists(installedExe))
            {
                StartDetached(installedExe, string.Empty);
            }
        }

        if (_mode == InstallerMode.AlreadyInstalled && !InstallCheck.IsSameFile(
                Environment.ProcessPath, _installedExe) &&
            File.Exists(_installedExe))
        {
            StartDetached(_installedExe, string.Empty);
        }
    }
}
