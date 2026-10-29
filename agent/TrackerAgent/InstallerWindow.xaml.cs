using System.Diagnostics;
using System.IO;
using System.Management;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Windows;
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
    private string _registeredAssetCode = "";
    private string _resolvedExePath = "";
    private AgentRegistrationFields? _registrationFields;
    private Dictionary<string, string> _hardwareDetails = new(StringComparer.OrdinalIgnoreCase);

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

        if (mode == InstallerMode.Update)
        {
            InstallHeadingText.Text = "Memperbarui TrackerAgent";
            DescriptionText.Text = "Proses pembaruan berjalan. Agent akan kembali aktif di system tray setelah selesai.";
            StepLabel1.Text = "Memeriksa agent yang sedang berjalan";
            StepLabel2.Text = "Memeriksa versi agent terpasang";
            StepLabel3.Text = "Menghentikan agent versi lama";
            StepLabel4.Text = "Membersihkan sisa file pembaruan";
            StepLabel5.Text = "Memasang versi agent terbaru";
            StepLabel6.Text = "Menjalankan ulang agent di system tray";
        }

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
            var track = OverallProgressTrack.ActualWidth;
            OverallProgressFill.Width = Math.Max(0, Math.Round(track * _displayedProgress / 100.0));
            PercentText.Text = $"{_displayedProgress}%";
        };
        _progressTimer.Start();
    }

    private void BeginShimmer()
    {
        Dispatcher.BeginInvoke(() =>
        {
            if (ShimmerBar.Visibility != Visibility.Visible)
            {
                ShimmerBar.Visibility = Visibility.Visible;
            }

            var trackWidth = OverallProgressTrack.ActualWidth;
            if (trackWidth <= 0)
            {
                trackWidth = 480; // fallback before layout
            }

            var slide = new DoubleAnimation
            {
                From = -140,
                To = trackWidth + 40,
                Duration = TimeSpan.FromSeconds(1.4),
                RepeatBehavior = RepeatBehavior.Forever,
                EasingFunction = new CubicEase { EasingMode = EasingMode.EaseInOut }
            };

            var sb = new Storyboard();
            sb.Children.Add(slide);
            Storyboard.SetTarget(slide, ShimmerTransform);
            Storyboard.SetTargetProperty(slide, new PropertyPath(TranslateTransform.XProperty));
            _shimmerStoryboard = sb;
            sb.Begin();
        });
    }

    private void EndShimmer()
    {
        Dispatcher.BeginInvoke(() =>
        {
            _shimmerStoryboard?.Stop();
            _shimmerStoryboard = null;
            ShimmerBar.Visibility = Visibility.Collapsed;
        });
    }

    private Storyboard? _shimmerStoryboard;

    /// <summary>Thread-safe progress report (called from the worker thread).</summary>
    /// <summary>
    /// Updates the status line without moving the progress bar, for a step that
    /// reports several sub-stages (copying an embedded installer, waiting for it).
    /// </summary>
    private void SetStepDetail(string detail)
    {
        StatusText.Text = detail;
    }

    private void Report(int percent, string message)
    {
        Dispatcher.BeginInvoke(() =>
        {
            _targetProgress = Math.Clamp(percent, 0, 100);
            StatusText.Text = message;
        UpdateInstallSteps(_targetProgress);
    });
}

    /// <summary>Reflect the actual reported install phase in the visible checklist.</summary>
    private void UpdateInstallSteps(int percent, bool failed = false)
    {
        var states = new[] { StepState1, StepState2, StepState3, StepState4, StepState5, StepState6 };
        var icons = new[] { StepIcon1, StepIcon2, StepIcon3, StepIcon4, StepIcon5, StepIcon6 };

        // A phase is marked complete when the next phase starts, not when its
        // "starting..." message is reported. This keeps the checklist honest during
        // slow file copies, registration requests, and task-scheduler setup.
        var thresholds = _mode == InstallerMode.Update
            ? new[] { 10, 25, 45, 55, 85, 100 }
            : new[] { 12, 46, 62, 76, 86, 100 };
        var complete = 0;
        var activeIndex = Array.FindIndex(thresholds, threshold => percent < threshold);

        // Failed before the threshold that marks the step complete means the step
        // that was running is the one that failed. The old code only looked at
        // activeIndex, so a failure on the very step whose threshold had just been
        // crossed was drawn as "Complete" with the next step still "Berjalan".
        if (failed && activeIndex < 0)
        {
            activeIndex = states.Length - 1;
        }

        for (var i = 0; i < states.Length; i++)
        {
            if (failed && i == activeIndex)
            {
                states[i].Text = "Gagal";
                states[i].Foreground = ErrorBrush;
                icons[i].Text = "\uE783"; // Error
                icons[i].Foreground = ErrorBrush;
            }
            else if (percent >= thresholds[i])
            {
                states[i].Text = "Selesai";
                states[i].Foreground = SuccessBrush;
                icons[i].Text = "\uE73E"; // Accept
                icons[i].Foreground = SuccessBrush;
                complete++;
            }
            else if (i == activeIndex)
            {
                states[i].Text = "Berjalan";
                states[i].Foreground = RunningBrush;
                icons[i].Foreground = RunningBrush;
            }
            else
            {
                states[i].Text = "Menunggu";
                states[i].Foreground = PendingBrush;
                icons[i].Foreground = PendingBrush;
            }
        }

        ProgressCounterText.Text = $"{complete} dari {states.Length} langkah selesai";
    }


    private async Task AutoInstallAsync()
    {
        try
        {
            Report(2, "Mencari server TRACKER-ASSET...");

            var serverDiscoveryTask = Task.Run(DiscoverServerUrl);
            var timeoutTask = Task.Delay(TimeSpan.FromSeconds(30)); // 30 second overall timeout

            var completedTask = await Task.WhenAny(serverDiscoveryTask, timeoutTask);

            if (completedTask == timeoutTask)
            {
                ShowFailure(
                    "Server tidak merespons dalam 30 detik.\n\n" +
                    "Periksa koneksi jaringan, atau unduh ulang installer langsung dari server TRACKER-ASSET.");
                return;
            }

            var serverUrl = await serverDiscoveryTask;

            if (serverUrl is null)
            {
                ShowFailure(
                    "Server TRACKER-ASSET tidak ditemukan.\n\n" +
                    "Pastikan server berjalan dan bisa diakses dari jaringan ini, lalu unduh ulang installer dari server tersebut.");
                return;
            }

            _serverUrl = serverUrl;
            Report(12, "Server terhubung.");

            // Deterministic identity, not "whatever the registry happens to hold".
            //
            // A previously stored id is only trusted when it is the value this
            // machine now generates. Random ids from older installers were what
            // made a reinstall look like a new device; keeping one would preserve
            // the duplicate. Every source is written after this so the tray, the
            // SYSTEM daemon and a later repair all agree.
            _deviceId = Convert.ToBase64String(GenerateDeviceId());
            PersistDeviceIdEverywhere(_deviceId);
            Report(14, "Membaca identitas dan spesifikasi perangkat...");
            _hardwareDetails = await Task.Run(() => HardwareCollector.Collect("install"));
            _hardwareDetails.TryGetValue("serial_number", out var serialNumber);

            Report(16, "Memeriksa apakah perangkat sudah terdaftar di master aset...");
            Report(16, "Memeriksa apakah perangkat sudah terdaftar di master aset...");
            var registrationCheck = await Task.Run(() => CheckRegistrationFields(serverUrl, _deviceId, serialNumber));
            _registeredAssetCode = registrationCheck.AssetCode;
            if (!registrationCheck.SkipFields)
            {
                Report(17, "Memuat pilihan data aset...");
                var lookups = await Task.Run(() => GetRegistrationLookups(serverUrl));
                Report(17, "Menunggu pengisian 10 data aset...");
                EndShimmer();
                var registrationDialog = new AgentRegistrationWindow(lookups) { Owner = this };
                if (registrationDialog.ShowDialog() != true || registrationDialog.Fields is null)
                {
                    ShowFailure("Pendaftaran dibatalkan. Belum ada data perangkat yang dikirim ke server.");
                    return;
                }

                _registrationFields = registrationDialog.Fields;
                BeginShimmer();
                Report(18, "Data aset lengkap; melanjutkan instalasi...");
            }
            else
            {
                Report(17, "Perangkat sudah terdaftar. Form data aset dilewati.");
            }

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
        catch (Exception ex)
        {
            ShowFailure(ex.Message);
            return;
        }
    }

    private static (bool SkipFields, string AssetCode) CheckRegistrationFields(string serverUrl, string deviceId, string? serialNumber)
    {
        using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(15) };
        var payload = JsonSerializer.Serialize(new
        {
            device_id = deviceId,
            hardware_id = deviceId,
            serial_number = serialNumber,
            check_only = true,
        });
        using var content = new StringContent(payload, System.Text.Encoding.UTF8, "application/json");
        using var response = http.PostAsync($"{serverUrl}/api/agent/register", content).GetAwaiter().GetResult();
        var body = response.Content.ReadAsStringAsync().GetAwaiter().GetResult();
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"Pemeriksaan registrasi gagal (HTTP {(int)response.StatusCode}): {body}");

        using var document = JsonDocument.Parse(body);
        var root = document.RootElement;
        var skipFields = root.TryGetProperty("skip_fields", out var skip) && skip.ValueKind == JsonValueKind.True;
        var assetCode = root.TryGetProperty("asset_code", out var code) && code.ValueKind == JsonValueKind.String
            ? code.GetString() ?? string.Empty
            : string.Empty;
        return (skipFields, assetCode);
    }

    private static RegistrationLookups GetRegistrationLookups(string serverUrl)
    {
        using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(15) };
        using var response = http.GetAsync($"{serverUrl}/api/agent/register/options").GetAwaiter().GetResult();
        var body = response.Content.ReadAsStringAsync().GetAwaiter().GetResult();
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"Pilihan data aset gagal dimuat (HTTP {(int)response.StatusCode}): {body}");

        using var document = JsonDocument.Parse(body);
        var root = document.RootElement;
        return new RegistrationLookups
        {
            Companies = ReadChoices(root, "companies", "code"),
            Categories = ReadChoices(root, "categories"),
            Locations = ReadChoices(root, "locations"),
            Statuses = ReadChoices(root, "statuses"),
            Users = ReadChoices(root, "users"),
        };
    }

    private static List<RegistrationChoice> ReadChoices(JsonElement root, string collection, string codeField = "")
    {
        var result = new List<RegistrationChoice>();
        if (!root.TryGetProperty(collection, out var items) || items.ValueKind != JsonValueKind.Array)
            return result;

        foreach (var item in items.EnumerateArray())
        {
            if (!item.TryGetProperty("id", out var id) || !id.TryGetInt32(out var numericId) ||
                !item.TryGetProperty("name", out var name) || name.ValueKind != JsonValueKind.String)
                continue;

            var code = codeField.Length > 0 && item.TryGetProperty(codeField, out var codeValue)
                ? codeValue.GetString() ?? string.Empty
                : string.Empty;
            result.Add(new RegistrationChoice(numericId, name.GetString() ?? string.Empty, code));
        }

        return result;
    }

    private async Task CheckForUpdatesAsync()
    {
        Report(5, "Memeriksa koneksi server...");

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
        Report(35, "Membandingkan versi agent...");

        var token = ReadTokenFromConfig();
        var hasUpdate = AutoUpdater.CheckForUpdate(serverUrl, token ?? string.Empty, out var message);

        if (hasUpdate)
        {
            Report(100, "Pembaruan tersedia.");
            ShowUpdateAvailable(message);
            return;
        }

        Report(100, "Agent sudah menggunakan versi terbaru.");
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
            Report(18, "Menyiapkan folder instalasi...");
            _installDir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER");
            Directory.CreateDirectory(_installDir);

            Report(32, "Menyalin file TrackerAgent...");
            var sourceExe = Environment.ProcessPath;
            var destExe = Path.Combine(_installDir, "TrackerAgent.exe");

            if (sourceExe is not null && File.Exists(sourceExe) &&
                !string.Equals(sourceExe, destExe, StringComparison.OrdinalIgnoreCase))
            {
                // A resident agent (leftover daemon, e.g. after config.json was
                // removed to test a fresh install) locks its own exe and the
                // copy would fail with a sharing violation. Stop it first, then
                // copy with the bounded retry used by the update path.
                //
                // Stopping is not always possible: when the machine already runs
                // the SYSTEM scheduled task, that process owns the handle from
                // session 0 and a standard user cannot kill it. Copying over a
                // locked exe fails no matter how many times it is retried, which
                // used to surface as the useless "check disk space" message.
                //
                // The guard is stopped first: it is what re-takes the handle a
                // minute later, and without pausing it the copy below could never
                // win against a task that restarts the agent it just lost.
                StopGuardAndSystemAgent();

                // Before copying: drop sibling builds a previous attempt left,
                // including the ones a loop created. Whatever is still running is
                // skipped by the exclusive-open probe.
                var stale = InstallerService.CleanSiblingBuilds(_installDir);

                if (stale > 0)
                {
                    AgentLog.Write($"Removed {stale} stale agent build(s) before installing.");
                }

                if (!TryCopyInstalledExe(sourceExe, destExe))
                {
                    // The clean way out is a side-by-side install: a versioned
                    // binary next to the locked one, which the SYSTEM task is then
                    // pointed at. No handle needs releasing and no elevation is
                    // needed for the copy itself.
                    var versionedExe = ReserveSiblingPath(
                        _installDir,
                        InstallCheck.ReadVersion(sourceExe)?.ToString() ?? "new");

                    StopInstalledAgent();

                    if (TryCopyInstalledExe(sourceExe, versionedExe))
                    {
                        destExe = versionedExe;
                    }
                    else
                    {
                        throw new InstallBlockedException(
                            "Agent versi lama sedang berjalan sebagai service Windows dan file-nya tidak bisa diganti.\n\n" +
                            "Tutup aplikasi ini, lalu jalankan ulang dengan klik kanan > Run as administrator.");
                    }
                }

                _resolvedExePath = destExe;
            }

            // Dependencies go in before the device registers, so the first
            // heartbeat can already report the RustDesk id and the fleet page has
            // a Remote button from the very first second.
            //
            // It runs under the generic "dependencies" heading on purpose: setup is
            // an IT-managed install, and a step that names every third-party
            // product only invites the person at the laptop to wonder what else is
            // being put on it. The detail line says enough for IT to know.
            Report(44, "Memasang dependensi agent...");

            try
            {
                RustDeskInstaller.TryInstall(stage => Dispatcher.Invoke(() => SetStepDetail(stage)));

                // Nothing launches the client here on purpose. An unconfigured
                // RustDesk shows its own window on the user's desktop - its id, a
                // banner asking to install the service, and "Not ready. Please check
                // your connection" - and that is precisely what a silent install must
                // not put on screen. The server sends the rendezvous address, key and
                // password with registration, and the agent applies them before the
                // service is started, so the first time RustDesk runs it already
                // points at our server.
                RustDeskProvisioner.SilenceDesktopClient();
            }
            catch (Exception ex)
            {
                // A dependency is not worth failing the whole install over: the
                // machine still reports inventory, which is the main job.
                Debug.WriteLine($"RustDesk dependency skipped: {ex}");
            }

            Report(46, "Membuat identitas perangkat...");
            var existingId = ReadRegistryString("DeviceId");
            if (string.IsNullOrWhiteSpace(_deviceId))
            {
                _deviceId = string.IsNullOrWhiteSpace(existingId)
                    ? Convert.ToBase64String(GenerateDeviceId())
                    : existingId;
            }

            Report(62, "Mendaftarkan perangkat ke server...");
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
                "Mendaftarkan perangkat ke server...");

            if (_registrationFields is not null)
            {
                Report(68, "Menyimpan data aset ke master register...");
                RegisterNewAsset(serverUrl, token);
                Api.ReportInstallProgress(serverUrl, token, "install", "in_progress", 68,
                    "Menyimpan data aset ke master register...");
            }

            Report(76, "Mengatur agent berjalan otomatis saat login...");
            InstallBootAutostart(serverUrl, token, destExe);
            Api.ReportInstallProgress(serverUrl, token, "install", "in_progress", 76,
                "Mengatur agent berjalan otomatis saat login...");

            Report(86, "Menyiapkan agent di system tray...");
            Api.ReportInstallProgress(serverUrl, token, "install", "in_progress", 86,
                "Menyiapkan agent di system tray...");

            // Start the file this run wrote, never a path that merely happens to
            // exist. destExe is reassigned to the versioned sibling whenever the
            // canonical copy failed, and a stale value here is what re-launched an
            // old build that then re-opened this installer.
            StartDetached(_resolvedExePath.Length > 0 ? _resolvedExePath : destExe, string.Empty);

            Report(94, "Menyelesaikan konfigurasi...");
            Api.ReportInstallProgress(serverUrl, token, "install", "in_progress", 94,
                "Menyelesaikan konfigurasi...");

            // The clickable code belongs in the success panel: it is the only
            // thing the user can quote when asking IT about this machine.
            _installDir = Path.GetDirectoryName(destExe) ?? _installDir;

            // Last, and after the agent is already running: the deny rule must never
            // be the reason a running agent cannot write its own log or config.
            InstallFolderProtection.Protect(_installDir, out var protection);
            AgentLog.Write(protection);

            Report(100, "Instalasi selesai.");
            Api.ReportInstallProgress(serverUrl, token, "install", "done", 100,
                "Instalasi selesai.",
                new Dictionary<string, string>
                {
                    ["message"] = "Agent installed; the first hardware scan is queued.",
                });
            Thread.Sleep(250);
            return true;
        }
        catch (Exception ex)
        {
            _lastError = DescribeFailure(ex);
            Debug.WriteLine($"Install error: {ex}");

            // Reported with whatever token was obtained, if any. A failed install
            // that leaves no trace on the server is the worst outcome: the machine
            // is broken and the fleet page shows nothing about it.
            if (!string.IsNullOrWhiteSpace(_installTokenForReporting))
            {
                Api.ReportInstallProgress(serverUrl, _installTokenForReporting, "install", "failed", 0,
                    $"Instalasi gagal: {_lastError}",
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
                hardware_id = _deviceId,
                name = Environment.MachineName,
                platform = Environment.OSVersion.VersionString,
                agent_version = System.Reflection.Assembly.GetExecutingAssembly().GetName().Version?.ToString(3),
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

    private string? HardwareValue(string key) =>
        _hardwareDetails.TryGetValue(key, out var value) && !string.IsNullOrWhiteSpace(value) ? value.Trim() : null;

    private void RegisterNewAsset(string serverUrl, string token)
    {
        var fields = _registrationFields ?? throw new InvalidOperationException("Data aset belum diisi.");
        var payload = JsonSerializer.Serialize(new
        {
            serial_number = HardwareValue("serial_number"),
            hardware = new
            {
                brand = HardwareValue("brand"),
                model = HardwareValue("model"),
                serial_number = HardwareValue("serial_number"),
                hostname = HardwareValue("hostname") ?? Environment.MachineName,
                processor = HardwareValue("processor"),
                storage = HardwareValue("storage"),
                ram = HardwareValue("ram"),
                os_version = HardwareValue("os"),
                mac_address = HardwareValue("mac_address"),
                static_ip = HardwareValue("ip_address"),
            },
            audit_fields = new
            {
                company_id = fields.CompanyId,
                category_id = fields.CategoryId,
                location_id = fields.LocationId,
                asset_status_id = fields.StatusId,
                current_user_id = fields.CurrentUserId,
                admin_it_pic = fields.ItPicId,
                purchase_year = fields.PurchaseYear,
                vendor = fields.Vendor,
                purchase_price = fields.PurchasePrice,
            },
        });

        using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
        using var request = new HttpRequestMessage(HttpMethod.Post, $"{serverUrl}/api/agent/bootstrap-asset")
        {
            Content = new StringContent(payload, System.Text.Encoding.UTF8, "application/json"),
        };
        request.Headers.Authorization = new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", token);
        using var response = http.Send(request);
        var body = response.Content.ReadAsStringAsync().GetAwaiter().GetResult();
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"Data aset gagal disimpan (HTTP {(int)response.StatusCode}): {body}");

        using var document = JsonDocument.Parse(body);
        if (document.RootElement.TryGetProperty("asset_code", out var code) && code.ValueKind == JsonValueKind.String)
            _registeredAssetCode = code.GetString() ?? string.Empty;
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
            // Pass --parent-pid so the elevated child's KillUserTrays spares this
            // installer process (same pattern as AgentRunner's update swap at line 985).
            var args = $"--install-service --url \"{serverUrl}\" --token \"{token}\" --parent-pid {Environment.ProcessId}";

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
            if (!SpawnAllowed(exePath, InstallCheck.ReadVersion(Environment.ProcessPath)))
            {
                AgentLog.Write(
                    $"Not starting {Path.GetFileName(exePath)}: it is the same build or older than this installer.");
                return;
            }

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

    /// <summary>
    /// Whether the installer may launch the binary at <paramref name="exePath"/>.
    /// </summary>
    /// <remarks>
    /// The single guard against the install loop that this file used to cause.
    ///
    /// Setup started whatever it had just "installed", and on a machine whose
    /// canonical exe was locked by the SYSTEM task, that path could still hold the
    /// OLD build restored a moment earlier by the guard. The old build then saw a
    /// newer version on disk, opened the installer again, which started the old
    /// build again - once every fifteen seconds, indefinitely, adding a process and
    /// a versioned exe each round.
    ///
    /// The rule: never launch anything older than, or equal to, the running
    /// installer. The version that just copied itself into place is the only thing
    /// worth starting, and if that exact file cannot be identified, nothing is
    /// started. An agent that fails to auto-start is a small problem; a machine
    /// spawning installers forever is not.
    /// </remarks>
    internal static bool SpawnAllowed(string? exePath, Version? installerVersion)
    {
        if (string.IsNullOrWhiteSpace(exePath) || !File.Exists(exePath))
        {
            return false;
        }

        return SpawnAllowedForVersions(InstallCheck.ReadVersion(exePath), installerVersion);
    }

    /// <summary>
    /// The rule on its own, with the versions supplied: the target may not be
    /// older than the running installer.
    /// </summary>
    /// <remarks>
    /// Split from the file check so it is testable without building executables
    /// with embedded version resources, which is the only part of this that needs
    /// a real binary.
    ///
    /// Equal is ALLOWED. The first version of this rule required strictly newer,
    /// and that broke every fresh install: setup writes 1.7.18 to the canonical
    /// path and then starts it, so the target was equal to the installer, was
    /// refused, and the agent never came up - no tray icon, no reports, nothing on
    /// screen to explain it. What actually has to be blocked is the old build that
    /// caused the loop, and that is strictly older.
    /// </remarks>
    internal static bool SpawnAllowedForVersions(Version? target, Version? installerVersion)
    {
        // Without a known installer version there is no safe comparison to make.
        if (installerVersion is null || target is null)
        {
            return false;
        }

        return target >= installerVersion;
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

    /// <summary>
    /// Writes the device id where the tray, the SYSTEM daemon and future repairs
    /// can all find the same value.
    /// </summary>
    /// <remarks>
    /// HKLM and ProgramData need elevation; both are best effort because a
    /// non-elevated install must still work. The config.json copy is written by
    /// SaveConfig as usual, so the per-user path is covered separately.
    /// </remarks>
    private static void PersistDeviceIdEverywhere(string deviceId)
    {
        try
        {
            using var hkcu = Microsoft.Win32.Registry.CurrentUser.CreateSubKey(@"Software\Mito\AuditTracker");
            hkcu?.SetValue("DeviceId", deviceId);
        }
        catch
        {
            // No registry access: the config file still carries it.
        }

        try
        {
            using var hklm = Microsoft.Win32.Registry.LocalMachine.CreateSubKey(@"SOFTWARE\Mito\AuditTracker");
            hklm?.SetValue("DeviceId", deviceId);
        }
        catch
        {
            // Not elevated: only the per-user copy exists.
        }

        try
        {
            var common = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "MITO");

            Directory.CreateDirectory(common);

            File.WriteAllText(
                Path.Combine(common, "device.json"),
                "{\"device_id\":\"" + deviceId + "\"}");
        }
        catch
        {
            // Same: the per-user config is enough for the tray.
        }
    }

    /// <summary>
    /// A stable per-machine identity.
    /// </summary>
    /// <remarks>
    /// This used to be 32 random bytes, which is stable only for as long as the
    /// registry key and config.json survive. Any reinstall on a machine whose
    /// config was gone produced a different id, and the server - which matches a
    /// device on hardware_id - treated the same laptop as a second device. That is
    /// exactly how one machine ended up listed twice as MBUL and MBUL-1.
    ///
    /// Windows already has a value that is unique to the installation and stable
    /// across reinstalls: MachineGuid. Hashing it means the id cannot be guessed
    /// from the registry value by outside software, and it is readable by SYSTEM
    /// as well as the user - the two contexts that were registering separately.
    /// Random bytes remain the fallback only if MachineGuid is unavailable.
    /// </remarks>
    internal static string DeviceIdForTest() => Convert.ToBase64String(GenerateDeviceId());

    private static byte[] GenerateDeviceId()
    {
        try
        {
            using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(
                @"SOFTWARE\Microsoft\Cryptography");

            if (key?.GetValue("MachineGuid") is string machineGuid && machineGuid.Length > 0)
            {
                using var sha = System.Security.Cryptography.SHA256.Create();

                return sha.ComputeHash(System.Text.Encoding.UTF8.GetBytes("tracker-agent:" + machineGuid));
            }
        }
        catch
        {
            // Fall through to the random id.
        }

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
        Report(2, "Memeriksa agent dan versi yang terpasang...");

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

            Report(10, $"Versi terpasang {_installedVersion}; menyiapkan versi {_newVersion}...");

            Report(25, "Menghentikan agent versi lama...");
            StopGuardAndSystemAgent();

            Report(45, "Membersihkan sisa file pembaruan...");
            InstallerService.CleanLeftovers(Path.GetDirectoryName(_installedExe)!);

            // Siblings from earlier side-by-side installs. The agent has just been
            // stopped above, so the ones that are not running can go now instead of
            // piling up a new file on every update.
            var removed = InstallerService.CleanSiblingBuilds(Path.GetDirectoryName(_installedExe)!);

            if (removed > 0)
            {
                AgentLog.Write($"Removed {removed} stale agent build(s) before updating.");
            }

            Report(55, $"Menyalin versi {_newVersion}...");

            // Same trap as the fresh-install path: when the SYSTEM task owns the
            // installed exe, no amount of retrying releases the handle, and the
            // raw IOException reached the user verbatim. Fall back to a versioned
            // binary beside the locked one and point autostart at it.
            if (!TryCopyInstalledExe(sourceExe, _installedExe))
            {
                StopInstalledAgent();

                // A versioned name can already exist and be running: this is the
                // second update on a machine whose canonical exe is locked, and
                // the first update is what put TrackerAgent-<ver>.exe there. Reusing
                // that name fails exactly like the canonical path did, which is why
                // a repeat update still stopped at 55% with "file tidak bisa
                // diganti". Each attempt now claims its own name.
                var versionedExe = ReserveSiblingPath(_installDir, _newVersion?.ToString() ?? "new");

                if (!TryCopyInstalledExe(sourceExe, versionedExe))
                {
                    throw new InstallBlockedException(
                        "Agent versi lama sedang berjalan sebagai service Windows dan file-nya tidak bisa diganti.\n\n" +
                        "Tutup aplikasi ini, lalu jalankan ulang dengan klik kanan > Run as administrator.");
                }

                Report(75, "Agent lama terkunci oleh service; versi baru dipasang berdampingan...");
                RestartAgentFrom(versionedExe);
                ReEnableGuard(versionedExe);
                Report(100, "Pembaruan selesai.");
                Thread.Sleep(250);
                return true;
            }

            Report(85, "Menjalankan ulang agent di system tray...");
            RestartAgent();
            ReEnableGuard(_installedExe);

            Report(100, "Pembaruan selesai.");
            Thread.Sleep(250);
            return true;
        }
        catch (Exception ex)
        {
            // The update path used to surface ex.Message verbatim, so the user got
            // a raw .NET string ("The process cannot access the file ...") in an
            // Indonesian wizard. Route it through the same translation as install.
            _lastError = DescribeFailure(ex);
            Debug.WriteLine($"Update error: {ex}");
            return false;
        }
    }

    /// <summary>
    /// Stops every agent process running from the installed path before the
    /// binary is replaced.
    /// </summary>
    /// <summary>
    /// A versioned sibling path that nothing is currently holding open.
    /// </summary>
    /// <remarks>
    /// Tries <c>TrackerAgent-1.7.17.exe</c>, then <c>-2</c>, <c>-3</c>... A name is
    /// only taken when the file does not exist, so the first candidate after a cut
    /// short update is a fresh one rather than the binary the tray is running from.
    /// </remarks>
    private static string ReserveSiblingPath(string installDir, string version)
    {
        for (var suffix = 1; suffix <= 50; suffix++)
        {
            var name = suffix == 1
                ? $"TrackerAgent-{version}.exe"
                : $"TrackerAgent-{version}-{suffix}.exe";
            var candidate = Path.Combine(installDir, name);

            if (!File.Exists(candidate))
            {
                return candidate;
            }

            // Exists but nobody holds it: an old, unused sibling. Removing it is
            // safe and keeps the folder from filling with dead builds.
            try
            {
                using (var probe = new FileStream(candidate, FileMode.Open, FileAccess.ReadWrite, FileShare.None))
                {
                    probe.Close();
                }

                File.Delete(candidate);
                return candidate;
            }
            catch (UnauthorizedAccessException)
            {
                return candidate;
            }
            catch (IOException)
            {
                // Locked by a running process: try the next name.
            }
        }

        return Path.Combine(installDir, $"TrackerAgent-{version}-{Guid.NewGuid():N}.exe");
    }

    /// <summary>
    /// A versioned copy of the agent living next to the installed one
    /// (<c>TrackerAgent-1.7.15.exe</c>), which is what a locked exe leaves behind.
    /// </summary>
    /// <remarks>
    /// These are real running agents, not stale files: when the canonical exe is
    /// held open by the SYSTEM task, the installer puts the new build beside it and
    /// points autostart there. Stopping only the canonical path therefore left the
    /// previous sibling running, and the next update failed to overwrite it - the
    /// exact failure that made every update after the first locked one fail at 55%.
    /// </remarks>
    private static bool IsSiblingBuild(string? executablePath)
    {
        if (string.IsNullOrWhiteSpace(executablePath))
        {
            return false;
        }

        try
        {
            var dir = Path.GetDirectoryName(executablePath);
            var name = Path.GetFileName(executablePath);

            return dir is not null &&
                   string.Equals(dir.TrimEnd('\\'), InstallCheck.InstallDir.TrimEnd('\\'), StringComparison.OrdinalIgnoreCase) &&
                   name.StartsWith("TrackerAgent-", StringComparison.OrdinalIgnoreCase) &&
                   name.EndsWith(".exe", StringComparison.OrdinalIgnoreCase);
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// Stops the SYSTEM agent and the guard that would restart it.
    /// </summary>
    /// <remarks>
    /// Killing the process is not enough. The guard task runs every minute as
    /// SYSTEM and starts the agent again, so the copy that follows immediately
    /// finds the canonical exe locked once more and falls back to yet another
    /// versioned sibling. That is how a machine ended up with
    /// TrackerAgent-1.7.20.exe, -2.exe and a canonical file still on 1.7.17:
    /// every update ran, none of them replaced the file the guard kept holding.
    ///
    /// The task is stopped and disabled, not deleted: if the install fails
    /// half-way, the machine still has its guard once the install re-enables it.
    /// Both calls need elevation and are best effort - without it the caller
    /// falls back to the side-by-side path, which still works.
    /// </remarks>
    private static void StopGuardAndSystemAgent()
    {
        foreach (var task in new[] { "MitoAuditTrackerGuard", "MitoAuditTrackerService" })
        {
            RunHidden("schtasks.exe", $"/End /TN \"{task}\"");
            RunHidden("schtasks.exe", $"/Change /TN \"{task}\" /DISABLE");
        }

        // Give the scheduler a moment to actually stop the process it launched.
        Thread.Sleep(1500);

        StopInstalledAgent();
    }

    /// <summary>
    /// Turns the guard back on after an update that disabled it.
    /// </summary>
    /// <remarks>
    /// The update path stops the guard so it can replace a locked exe, and without
    /// this the machine would end up with the new build running once and no guard
    /// to bring it back after a crash or a reboot - silently losing protection on
    /// exactly the machines that were just fixed.
    /// </remarks>
    private static void ReEnableGuard(string exePath)
    {
        // Refresh the protected copy before the guard can fire. The guard
        // restores from ProgramData\MITO and only updates that copy when it is
        // installed, so an update that skipped this step would be undone at the
        // next guard tick - which is exactly what happened: the installed file
        // became 1.7.23, the guard put 1.7.21 back a minute later, and the fleet
        // reported the old version.
        try
        {
            if (File.Exists(exePath))
            {
                var protectedPath = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                    "MITO",
                    "TrackerAgent.exe");

                Directory.CreateDirectory(Path.GetDirectoryName(protectedPath)!);
                File.Copy(exePath, protectedPath, overwrite: true);

                AgentLog.Write($"Protected copy refreshed from {Path.GetFileName(exePath)}.");
            }
        }
        catch (Exception ex)
        {
            // Needs elevation. The guard's own version check now stops it from
            // downgrading the install if this fails.
            AgentLog.Write($"Could not refresh the protected copy: {ex.Message}");
        }

        RunHidden("schtasks.exe", "/Change /TN \"MitoAuditTrackerGuard\" /ENABLE");

        if (!InstallCheck.IsSameFile(exePath, InstallCheck.InstalledExe))
        {
            // Running from a versioned sibling: point the guard at it, or it will
            // restore the stale canonical build again.
            AgentLog.Write($"Guard should target {Path.GetFileName(exePath)}, not the canonical exe.");
        }

        try
        {
            RunHidden("schtasks.exe", "/Run /TN \"MitoAuditTrackerGuard\"");
        }
        catch
        {
            // Its own schedule will fire on the next minute regardless.
        }
    }

    /// <summary>Runs a helper tool with no window, ignoring failures.</summary>
    private static void RunHidden(string exe, string arguments)
    {
        try
        {
            using var process = Process.Start(new ProcessStartInfo
            {
                FileName = exe,
                Arguments = arguments,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            });

            process?.WaitForExit(15000);
        }
        catch
        {
            // Missing tool or no rights: the caller handles the fallback.
        }
    }

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
                    process["ExecutablePath"] as string,
                    InstallCheck.InstalledExe) &&
                    !IsSiblingBuild(process["ExecutablePath"] as string))
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
    private void RestartAgent()
    {
        // The freshly written file, not the canonical name: after a locked-file
        // install the canonical exe is still the previous build, and starting it
        // is how the old agent came back and reopened setup.
        var target = _resolvedExePath.Length > 0 ? _resolvedExePath : InstallCheck.InstalledExe;

        RegisterAutostart(target);
        StartDetached(target, string.Empty);
    }

    /// <summary>
    /// Restart pointing at a specific binary, used when the canonical path is
    /// still locked by the SYSTEM task and the new build sits beside it.
    /// </summary>
    private static void RestartAgentFrom(string exePath)
    {
        RegisterAutostart(exePath);
        StartDetached(exePath, string.Empty);
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

    /// <summary>
    /// Copy that reports failure instead of retrying a lock it can never clear.
    /// </summary>
    /// <remarks>
    /// Only a sharing violation is worth waiting for - it can be a handle that is
    /// closing. Any other IOException (disk full, path too long, security) will
    /// fail identically on every attempt, and spending three seconds per attempt
    /// on it only delays the error message the user needs.
    /// </remarks>
    private static bool TryCopyInstalledExe(string source, string destination)
    {
        for (var attempt = 0; attempt < 6; attempt++)
        {
            try
            {
                File.Copy(source, destination, overwrite: true);
                return true;
            }
            catch (IOException ex) when (IsSharingViolation(ex))
            {
                Thread.Sleep(500);
            }
            catch (UnauthorizedAccessException)
            {
                return false;
            }
        }

        return false;
    }

    /// <summary>
    /// Turns an exception into something the person running setup can act on.
    /// </summary>
    /// <remarks>
    /// Shared by the install and update paths so both speak the same language and
    /// neither leaks a raw .NET message ("The process cannot access the file ...")
    /// into an otherwise Indonesian wizard.
    /// </remarks>
    private static string DescribeFailure(Exception ex) => ex switch
    {
        InstallBlockedException => ex.Message,
        System.IO.IOException io when IsSharingViolation(io) =>
            "File agent sedang dipakai proses lain (biasanya service TrackerAgent).\n\n" +
            "Tutup aplikasi ini, lalu jalankan ulang dengan klik kanan > Run as administrator.",
        System.IO.IOException =>
            "Operasi file gagal. Periksa ruang disk dan izin folder instalasi.",
        System.Net.Http.HttpRequestException =>
            "Tidak bisa menghubungi server. Periksa koneksi jaringan.",
        System.Reflection.TargetInvocationException =>
            "Ada kesalahan pada proses instalasi. Hubungi tim IT.",
        System.UnauthorizedAccessException =>
            "Izin ditolak. Jalankan installer dengan klik kanan > Run as administrator.",
        _ => ex.Message
    };

    /// <summary>
    /// A Windows sharing violation (ERROR_SHARING_VIOLATION / ERROR_LOCK_VIOLATION),
    /// i.e. another process still has the file open.
    /// </summary>
    private static bool IsSharingViolation(IOException ex)
    {
        var code = ex.HResult & 0xFFFF;

        return code == 32 || code == 33;
    }

    /// <summary>
    /// Thrown for a block the user can resolve deliberately (usually by
    /// elevating), as opposed to an unexpected failure. Its message is shown
    /// verbatim, because it names the action that fixes it.
    /// </summary>
    private sealed class InstallBlockedException : Exception
    {
        public InstallBlockedException(string message) : base(message)
        {
        }
    }

    /// <summary>Clicked build == installed build: ensure the tray agent is running.</summary>
    private void ShowAlreadyInstalled()
    {
        EnsureInstalledTrayIsRunning();

        // The install is not going to run, so the checklist must not be shown at
        // all.
        //
        // Leaving it visible with every step on "Menunggu" and the bar at 0% is
        // what made a finished, already-installed machine look frozen mid-setup -
        // the user waits for steps that are never scheduled, because this path
        // only verifies what is already there. The checklist is for a run in
        // progress; here there is nothing in progress.
        InstallStepsPanel.Visibility = Visibility.Collapsed;
        OverallProgressTrack.Visibility = Visibility.Collapsed;
        StatsPanel.Visibility = Visibility.Collapsed;
        ProgressCounterText.Visibility = Visibility.Collapsed;

        SubtitleText.Text = "Sudah terpasang";
        InstallHeadingText.Text = "TrackerAgent sudah terpasang";
        DescriptionText.Text = "Tidak ada yang perlu dipasang. Agent sudah berjalan di komputer ini.";

        ResultPanel.Visibility = Visibility.Visible;
        RetryButton.Visibility = Visibility.Collapsed;
        CloseButton.Visibility = Visibility.Visible;

        SetResult(
            GlyphOk,
            "Sudah terpasang dan berjalan",
            $"Versi {_installedVersion} sudah terpasang di komputer ini.\n\n" +
            $"{_installedExe}\n\n" +
            "Agent berjalan di system tray. Untuk memperbarui, unduh installer versi baru dari server.",
            SuccessBrush);
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
            Debug.WriteLine("[INSTALL] No tray running, starting detached tray...");
            StartDetached(_installedExe, string.Empty);
            Debug.WriteLine("[INSTALL] StartDetached called");
        }
        else
        {
            Debug.WriteLine("[INSTALL] Tray already running from installed exe");
        }
    }

    /// <summary>Swap finished: report the version jump, then auto-close.</summary>
    private void ShowUpdateSuccess()
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            EndShimmer();

            SubtitleText.Text = "Pembaruan selesai";
            InstallHeadingText.Text = "Agent berhasil diperbarui";
            DescriptionText.Text = "Versi terbaru sudah terpasang. Agent kembali berjalan di system tray.";
            ResultPanel.Visibility = Visibility.Visible;
            CloseButton.Visibility = Visibility.Visible;

            SetResult(
                GlyphOk,
                "Pembaruan TrackerAgent berhasil",
                $"Versi {_newVersion} sudah terpasang.\n\nAgent aktif kembali di system tray.",
                SuccessBrush);
        });
    }

        private void ShowSuccess()
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            EndShimmer();

            SubtitleText.Text = "Instalasi selesai";
            InstallHeadingText.Text = "TrackerAgent sudah terpasang";
            DescriptionText.Text = "Instalasi berhasil. Agent tetap berjalan di system tray dan akan mulai melaporkan perangkat ini.";
            ResultPanel.Visibility = Visibility.Visible;
            CloseButton.Visibility = Visibility.Visible;

            // The checklist and bar are finished states too.
            //
            // The update path returns early after a side-by-side copy and never
            // reaches the 94% report, so this window stopped at "Berjalan / 55%"
            // under a success panel: the operator saw a green "berhasil dipasang"
            // next to a progress bar that had clearly not finished and a step that
            // said it was still running.
            _targetProgress = 100;
            UpdateInstallSteps(100);
            OverallProgressFill.Width = OverallProgressTrack.ActualWidth > 0
                ? OverallProgressTrack.ActualWidth
                : 400;
            PercentText.Text = "100%";
            StatusText.Text = "Instalasi selesai.";

            SetResult(
                GlyphOk,
                "TrackerAgent berhasil dipasang",
                $"Agent tersimpan di {_installDir}\n\n" +
                (_registeredAssetCode.Length > 0 ? $"Kode aset: {_registeredAssetCode}\n\n" : "") +
                $"Terhubung ke {_serverUrl}\n\nPerangkat akan mengirim laporan ke Live Fleet.",
                SuccessBrush);

        });
    }

    private void ShowFailure(string message)
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            EndShimmer();

            SubtitleText.Text = _mode == InstallerMode.Update ? "Pembaruan gagal" : "Instalasi gagal";
            InstallHeadingText.Text = "Proses belum selesai";
            DescriptionText.Text = "Ada langkah yang tidak berhasil. Periksa pesan di bawah, lalu coba lagi.";
            ResultPanel.Visibility = Visibility.Visible;
            RetryButton.Visibility = Visibility.Visible;
            CloseButton.Visibility = Visibility.Visible;

            // The bar keeps the percentage it reached - it is evidence of how far
            // the install got - but the status line names the outcome instead of
            // repeating the message that the result panel already shows in full.
            StatusText.Text = "Instalasi berhenti.";
            UpdateInstallSteps(_targetProgress, failed: true);

            SetResult(
                GlyphError,
                _mode == InstallerMode.Update ? "Pembaruan gagal" : "Instalasi gagal",
                message,
                ErrorBrush);
        });
    }

    private void ShowUpdateAvailable(string message)
    {
        Dispatcher.BeginInvoke(() =>
        {
            _progressTimer.Stop();
            EndShimmer();

            SubtitleText.Text = "Update available";
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
            EndShimmer();

            SubtitleText.Text = "Update check complete";
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
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0x04, 0x78, 0x57)); // emerald-700
    private static readonly System.Windows.Media.Brush ErrorBrush =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0xB4, 0x23, 0x18)); // red-700
    private static readonly System.Windows.Media.Brush InfoBrush =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0x1D, 0x4E, 0xD8)); // blue-700
    private static readonly System.Windows.Media.Brush PendingBrush =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0x66, 0x70, 0x85)); // gray-500
    private static readonly System.Windows.Media.Brush RunningBrush =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0x1D, 0x4E, 0xD8)); // blue-700

    /// <summary>The step glyph and badge colours, kept in step with the light theme.</summary>
    private static readonly System.Windows.Media.Color SuccessSoftColor =
        System.Windows.Media.Color.FromRgb(0xEC, 0xFD, 0xF5);
    private static readonly System.Windows.Media.Color ErrorSoftColor =
        System.Windows.Media.Color.FromRgb(0xFE, 0xF3, 0xF2);
    private static readonly System.Windows.Media.Color RunningSoftColor =
        System.Windows.Media.Color.FromRgb(0xEF, 0xF6, 0xFF);
    private static readonly System.Windows.Media.Color PendingSoftColor =
        System.Windows.Media.Color.FromRgb(0xF3, 0xF4, 0xF6);

    /// <summary>
    /// Fills the result card in one place.
    /// </summary>
    /// <remarks>
    /// The badge is a soft tint with a strong glyph rather than a saturated disc
    /// with white text: on a light surface the filled disc reads as an alert
    /// dialog pasted into the page, and at 36 px it swallowed the message.
    /// </remarks>
    private void SetResult(string glyph, string title, string detail, System.Windows.Media.Brush accent)
    {
        ResultIcon.Text = glyph;
        ResultIcon.Foreground = accent;

        var soft = ReferenceEquals(accent, ErrorBrush) ? ErrorSoftColor
            : ReferenceEquals(accent, InfoBrush) ? RunningSoftColor
            : SuccessSoftColor;

        if (ResultBadge.Fill is SolidColorBrush badge)
        {
            // Ellipse.Fill is a frozen brush from XAML; replacing it is cheaper
            // than trying to mutate a frozen Freezable.
            ResultBadge.Fill = new SolidColorBrush(soft);
        }

        ResultText.Text = title;
        ResultText.Foreground = ReferenceEquals(accent, ErrorBrush) ? ErrorBrush : neutralText;
        ResultDetail.Text = detail;
    }

    private static readonly System.Windows.Media.Brush neutralText =
        new SolidColorBrush(System.Windows.Media.Color.FromRgb(0x10, 0x18, 0x28));

    private async void RetryButton_Click(object sender, RoutedEventArgs e)
    {
        RetryButton.Visibility = Visibility.Collapsed;
        CloseButton.Visibility = Visibility.Collapsed;
        ResultPanel.Visibility = Visibility.Collapsed;

        _targetProgress = 0;
        _displayedProgress = 0;
        OverallProgressFill.Width = 0;
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


