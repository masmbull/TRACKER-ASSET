using System.Diagnostics;
using System.IO;
using System.Management;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace TrackerAgent;

public static class AgentRunner
{
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(60) };

    private const int AttachParentProcess = -1;

    [DllImport("kernel32.dll")]
    static extern bool AttachConsole(int pid);

    [DllImport("kernel32.dll")]
    static extern bool AllocConsole();

    private const string RunKeyPath = @"Software\Microsoft\Windows\CurrentVersion\Run";

    private const string RunValueName = "MitoAuditTracker";

    public static int Run(string[] args)
    {
        // WinExe has no console - attach to the launching terminal so one-shot
        // results are visible when run from PowerShell/CMD.
        if (!AttachConsole(AttachParentProcess))
        {
            AllocConsole();
        }

        string serverUrl = ResolveDefaultServerUrl();
        string token = Environment.GetEnvironmentVariable("TRACKER_AUDIT_TOKEN") ?? "";
        string? assetId = null;
        bool quiet = false;
        bool daemon = false;
        int pollSeconds = 10;
        bool uninstall = false;
        bool update = false;

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
            }
        }

        if (quiet)
        {
            Console.SetOut(TextWriter.Null);
            Console.SetError(TextWriter.Null);
        }

        if (uninstall)
        {
            Uninstall();
            return 0;
        }

        if (update)
        {
            serverUrl = serverUrl.TrimEnd('/');
            bool updated = AutoUpdater.PerformUpdate(serverUrl, token);
            return updated ? 0 : 1;
        }

        if (string.IsNullOrWhiteSpace(token))
        {
            Console.Error.WriteLine("[X] No token provided.");
            return 1;
        }

        serverUrl = serverUrl.TrimEnd('/');

        return daemon
            ? RunDaemon(serverUrl, token, assetId, pollSeconds)
            : RunOnce(serverUrl, token, assetId);
    }

    static string NextArg(string[] args, ref int i) =>
        i + 1 < args.Length ? args[++i] : throw new ArgumentException($"Missing value for {args[i - 1]}");
    /// <summary>
    /// Default server address when no --url is given: environment override →
    /// address baked into the downloaded installer (AgentConfigTrailer) →
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


    static int RunOnce(string url, string token, string? assetId = null)
    {
        try
        {
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


            var result = Api.Post(url, token, payload);

            if (result is null)
            {
                Console.Error.WriteLine("[X] Request failed.");
                return 1;
            }

            PrintResult(result.Value);
            return 0;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[X] {ex.Message}");
            return 1;
        }
    }

    /// <summary>
    /// Resident mode: registers the exe for autostart (HKCU Run), then polls
    /// GET /api/agent/inbox every N seconds, executing pending commands and acking them.
    /// </summary>
    static int RunDaemon(string url, string token, string? assetId, int pollSeconds)
    {
        RegisterAutostart(url, token, assetId, pollSeconds);
        Console.WriteLine($"[OK] Daemon started - polling {url} every {pollSeconds}s.");

        while (true)
        {
            try
            {
                var commands = Api.GetInbox(url, token);
                if (commands is not null)
                {
                    foreach (var cmd in commands)
                    {
                        var id = cmd.GetProperty("id").GetInt64();
                        var command = cmd.GetProperty("command").GetString() ?? "";

                        if (command == "scan")
                        {
                            int rc = RunOnce(url, token, assetId);
                            Api.Ack(url, token, id, rc == 0 ? "done" : "failed");
                            Console.WriteLine($"[OK] Command #{id} scan -> {(rc == 0 ? "done" : "failed")}");
                        }
                        else if (command == "update")
                        {
                            Console.WriteLine($"[*] Command #{id} update received. Acknowledging and updating...");
                            Api.Ack(url, token, id, "done");
                            if (AutoUpdater.PerformUpdate(url, token))
                            {
                                Environment.Exit(0);
                            }
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

            Thread.Sleep(TimeSpan.FromSeconds(pollSeconds));
        }
    }

    static void PrintResult(JsonElement r)
    {
        var result = r.TryGetProperty("result", out var rv) ? rv.GetString() ?? "UNKNOWN" : "UNKNOWN";
        var asset = r.TryGetProperty("asset", out var av) && av.ValueKind != JsonValueKind.Null ? av.GetString() : "N/A";

        Console.ForegroundColor = result switch
        {
            "MATCH" => ConsoleColor.Green,
            "MISMATCH" => ConsoleColor.Yellow,
            _ => ConsoleColor.Magenta
        };
        Console.WriteLine($"\n[RESULT] {result}  (asset: {asset})");

        if (r.TryGetProperty("mismatches", out var mm) && mm.EnumerateObject().Any())
        {
            Console.ForegroundColor = ConsoleColor.Yellow;
            Console.WriteLine("[!] Mismatches:");
            foreach (var m in mm.EnumerateObject())
            {
                var val = m.Value;
                var reg = val.TryGetProperty("registered", out var rv2) ? rv2.GetString() : "?";
                var act = val.TryGetProperty("actual", out var av2) ? av2.GetString() : "?";
                Console.WriteLine($"    {m.Name}: registered \"{reg}\" vs actual \"{act}\"");
            }
        }

        if (r.TryGetProperty("message", out var msg))
        {
            Console.ForegroundColor = ConsoleColor.Cyan;
            Console.WriteLine(msg.GetString());
        }
        Console.ResetColor();
    }

    static void RegisterAutostart(string url, string token, string? assetId, int pollSeconds)
    {
        try
        {
            var exe = Environment.ProcessPath
                ?? Path.Combine(AppContext.BaseDirectory, "TrackerAgent.exe");

            var args = $"--daemon --url \"{url}\" --token \"{token}\" --poll {pollSeconds}";
            if (!string.IsNullOrWhiteSpace(assetId))
            {
                args += $" --asset-id \"{assetId}\"";
            }

            using var key = Registry.CurrentUser.CreateSubKey(RunKeyPath);
            key?.SetValue(RunValueName, $"\"{exe}\" {args}");
            Console.WriteLine("[OK] Registered for autostart (HKCU Run).");
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Autostart registration failed: {ex.Message}");
        }
    }

    static void Uninstall()
    {
        try
        {
            using (var runKey = Registry.CurrentUser.OpenSubKey(RunKeyPath, true))
            {
                runKey?.DeleteValue(RunValueName, false);
            }

            var key = Registry.CurrentUser.OpenSubKey(@"Software\Mito\AuditTracker", true);
            key?.DeleteValue("DeviceId");
            key?.DeleteValue("Token");
            key?.DeleteValue("ServerUrl");
            key?.DeleteValue("InstalledAt");
            key?.Close();

            var installDir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TRACKER");
            if (Directory.Exists(installDir))
            {
                Directory.Delete(installDir, true);
            }

            Console.WriteLine("[OK] Uninstalled successfully.");
        }
        catch (Exception ex)
        {
            Console.WriteLine($"[X] {ex.Message}");
        }
    }
}
