using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Text.Json;

namespace TrackerAgent.Tests;

/// <summary>
/// Offline checks for the two defects a wiped server exposed: a daemon that
/// ignored the credential already on the machine, and a scan that threw on an
/// empty mismatch set before it could ack.
/// </summary>
/// <remarks>
/// Deliberately framework-free (see the csproj) - assert-and-exit, exit code 0
/// for green. Every case below is one that was actually broken in the field,
/// not a restatement of the implementation.
/// </remarks>
internal static class Program
{
    private static int _failures;

    private static int Main()
    {
        TokenFromConfigCases();
        TrayAutostartCases();
        ScanResultCases();
        MemoryLayoutCases();
        InventoryPayloadCases();
        FullScanCases();
        ServiceTaskCases();

        Console.WriteLine(_failures == 0
            ? "\n[OK] All agent checks passed."
            : $"\n[X] {_failures} agent check(s) failed.");

        return _failures == 0 ? 0 : 1;
    }

    /// <summary>
    /// Bug 5: the CLI daemon used to read a token from --token / the environment
    /// only, so an autostart replay (or any launch that did not repeat the
    /// original argument) exited with "No token provided." while a usable token
    /// sat in config.json - exactly the state after a server database reset.
    /// </summary>
    private static void TokenFromConfigCases()
    {
        Console.WriteLine("[*] config.json token lookup");

        using var dir = new TempDir();

        var installerConfig = Path.Combine(dir.Path, "installer.json");
        File.WriteAllText(installerConfig, """
            {
              "device_id": "MITO-0001",
              "token": "tat_abc123",
              "server_url": "http://127.0.0.1:8000",
              "installed_at": "2026-10-09T20:40:01.0000000+07:00"
            }
            """);

        Check(
            "reads the token the installer wrote",
            AgentRunner.ReadTokenFromConfig(installerConfig) == "tat_abc123");

        // The heal path rewrites the file in place with a regex; a token that
        // begins with a digit used to turn "$1" into a group reference and
        // corrupt the file ("$156...").
        var digitToken = Path.Combine(dir.Path, "digit.json");
        File.WriteAllText(digitToken, """{ "token": "9911tat", "device_id": "d" }""");

        Check(
            "reads a token that starts with a digit",
            AgentRunner.ReadTokenFromConfig(digitToken) == "9911tat");

        Check(
            "missing file yields null instead of throwing",
            AgentRunner.ReadTokenFromConfig(Path.Combine(dir.Path, "absent.json")) is null);

        var noToken = Path.Combine(dir.Path, "notoken.json");
        File.WriteAllText(noToken, """{ "device_id": "MITO-0001" }""");

        Check(
            "config without a token yields null",
            AgentRunner.ReadTokenFromConfig(noToken) is null);

        var nonString = Path.Combine(dir.Path, "weird.json");
        File.WriteAllText(nonString, """{ "token": 12345 }""");

        Check(
            "non-string token yields null",
            AgentRunner.ReadTokenFromConfig(nonString) is null);

        var corrupt = Path.Combine(dir.Path, "corrupt.json");
        File.WriteAllText(corrupt, "{ token: tat_unquoted ");

        Check(
            "malformed json yields null instead of throwing",
            AgentRunner.ReadTokenFromConfig(corrupt) is null);
    }

    /// <summary>
    /// Bug 6: the server serialises an empty PHP array as `[]` and a populated
    /// mismatch set as an object. PrintResult called EnumerateObject() on both,
    /// so the very first scan of every new device (NEW DEVICE / MATCH, no
    /// mismatches) threw, RunOnce returned 1, and the daemon acked the scan as
    /// "failed".
    /// </summary>
    private static void TrayAutostartCases()
    {
        Console.WriteLine("[*] tray autostart command");

        const string exePath = @"C:\Users\Example\AppData\Local\TRACKER\TrackerAgent.exe";
        var command = InstallCheck.BuildTrayAutostartCommand(exePath);

        Check(
            "autostart launches the installed tray without daemon flags or credentials",
            command == $"\"{exePath}\"" && !command.Contains("--daemon", StringComparison.OrdinalIgnoreCase));
    }

    private static void ScanResultCases()
    {
        Console.WriteLine("[*] scan-result printing");

        // Exactly what the server answers for a device nobody has on file.
        var newDevice = Prints("""
            { "result": "NEW DEVICE", "asset": null, "mismatches": [],
              "message": "Device seen for the first time." }
            """);
        Check("empty mismatch array does not throw", newDevice.Ok);
        Check(
            "the result line reaches the console",
            newDevice.Text.Contains("[RESULT] NEW DEVICE", StringComparison.Ordinal));

        Check(
            "MATCH with an empty mismatch array does not throw",
            Prints("""{ "result": "MATCH", "asset": "MITO-0001", "mismatches": [] }""").Ok);

        // The shape a populated mismatch set has, to prove the object branch
        // still prints.
        var mismatch = Prints("""
            { "result": "MISMATCH", "asset": "MITO-0001",
              "mismatches": { "ram": { "registered": "8 GB", "actual": "16 GB" } } }
            """);
        Check("populated mismatch object does not throw", mismatch.Ok);
        Check(
            "a populated mismatch is still reported",
            mismatch.Text.Contains("ram: registered \"8 GB\" vs actual \"16 GB\"",
                StringComparison.Ordinal));

        Check(
            "an array of mismatches does not throw",
            Prints("""{ "result": "MISMATCH", "asset": "MITO-0001", "mismatches": ["ram"] }""").Ok);

        Check(
            "a response without a mismatches key does not throw",
            Prints("""{ "result": "MATCH", "asset": "MITO-0001" }""").Ok);

        // A response that is not the shape we expect must still print a result
        // rather than throw - a throw here is what aborts the ack.
        Check(
            "a mismatches value of the wrong type does not throw",
            Prints("""{ "result": "MATCH", "asset": "MITO-0001", "mismatches": "nope" }""").Ok);
    }

    private static void MemoryLayoutCases()
    {
        Console.WriteLine("[*] physical memory layout");

        var singleModule = new HardwareCollector.MemoryModuleReading("DIMM 0", "SERIAL-1", "Physical Memory 0", 16UL * 1024 * 1024 * 1024, 3200);
        var duplicateRow = new HardwareCollector.MemoryModuleReading("DIMM 0", "SERIAL-1", "Physical Memory 0", 16UL * 1024 * 1024 * 1024, 3200);
        var movedDuplicateRow = new HardwareCollector.MemoryModuleReading("ChannelA-DIMM0", "SERIAL-1", "Physical Memory 9", 16UL * 1024 * 1024 * 1024, 3200);
        var invalidRow = new HardwareCollector.MemoryModuleReading("DIMM 1", "", "Physical Memory 1", 0, 0);

        Check(
            "one populated 16GB slot is reported as one 16GB module",
            HardwareCollector.FormatMemoryLayout([singleModule]) == "16GB @ 3200 MHz");
        Check(
            "duplicate and zero-capacity WMI rows do not inflate RAM",
            HardwareCollector.FormatMemoryLayout([singleModule, duplicateRow, movedDuplicateRow, invalidRow]) == "16GB @ 3200 MHz");

        var eightGbModule = new HardwareCollector.MemoryModuleReading("DIMM 1", "SERIAL-2", "Physical Memory 1", 8UL * 1024 * 1024 * 1024, 2666);
        Check(
            "different module capacities stay distinguishable",
            HardwareCollector.FormatMemoryLayout([singleModule, eightGbModule]) == "16GB + 8GB @ 3200 MHz");
    }

    /// <summary>
    /// The key names of the two nested lists in the fingerprint payload.
    /// </summary>
    /// <remarks>
    /// The server validates these under snake_case keys and returns only the
    /// fields named in its rules, so a camelCase spelling is not an error - it
    /// is a silent blank. Every printer arrived with driver, port and offline
    /// flag empty this way, while "name" and "status" looked fine precisely
    /// because they are single-word and spelled the same in both conventions.
    ///
    /// Asserted against the JSON rather than the options object, because the
    /// failure mode is exactly one that no exception ever surfaces.
    /// </remarks>
    private static void InventoryPayloadCases()
    {
        Console.WriteLine("[*] inventory payload key names");

        var printers = new List<FingerprintCollector.PrinterReport>
        {
            new("HP LaserJet P1102", "HP Universal Printing PCL 6", "10.20.30.50",
                "Finance", IsNetwork: true, IsDefault: true, IsOffline: false, StatusCode: 3, Status: "Idle"),
        };

        using (var doc = JsonDocument.Parse(
            JsonSerializer.Serialize(printers, FingerprintCollector.InventoryJson)))
        {
            var first = doc.RootElement[0];

            Check("printer driver is sent as driver_name", first.TryGetProperty("driver_name", out _));
            Check("printer port is sent as port_name", first.TryGetProperty("port_name", out _));
            Check("printer network flag is sent as is_network", first.TryGetProperty("is_network", out _));
            Check("printer default flag is sent as is_default", first.TryGetProperty("is_default", out _));
            Check("printer offline flag is sent as is_offline", first.TryGetProperty("is_offline", out _));
            Check("printer status code is sent as status_code", first.TryGetProperty("status_code", out _));
            Check("printer name survives the round trip",
                first.GetProperty("name").GetString() == "HP LaserJet P1102");
            Check("a true flag is a real JSON boolean, not the word true",
                first.GetProperty("is_network").ValueKind == JsonValueKind.True);
        }

        var programs = new List<SoftwareEntryForTest>
        {
            new("ACCURATE 4 Standard", "4.0.1", "CPNSi", "2024-01-15"),
        };

        using (var doc = JsonDocument.Parse(
            JsonSerializer.Serialize(programs, FingerprintCollector.InventoryJson)))
        {
            var first = doc.RootElement[0];

            Check("software install date is sent as installed_at",
                first.TryGetProperty("installed_at", out _));
            Check("software publisher is sent as publisher",
                first.TryGetProperty("publisher", out _));
        }
    }

    /// <summary>Mirrors Api.SoftwareEntry, which is private to that class.</summary>
    private sealed record SoftwareEntryForTest(string? Name, string? Version, string? Publisher, string? InstalledAt);

    private static void FullScanCases()
    {
        Console.WriteLine("[*] full scan request");

        using var server = new RecordingHttpServer();
        var rc = AgentRunner.RunFullScan(server.Url, "token-123", "asset-999");

        Check("full scan posts the audit and fingerprint endpoints", rc == 0 && server.Requests.Count == 2);
        Check("audit is sent before the deep fingerprint",
            server.Requests[0].Contains("/api/audits", StringComparison.Ordinal) &&
            server.Requests[1].Contains("/api/agent/fingerprint", StringComparison.Ordinal));

        using var fingerprintFailureServer = new RecordingHttpServer(failFingerprint: true);
        var failedRc = AgentRunner.RunFullScan(fingerprintFailureServer.Url, "token-123", "asset-999");

        Check("rejected fingerprint makes the full scan fail", failedRc != 0);
        Check("failed full scan still attempts both report endpoints", fingerprintFailureServer.Requests.Count == 2);
    }

    /// <summary>
    /// <see cref="AgentRunner.PrintResult"/> with stdout captured, so its
    /// printed lines can be asserted on without hiding the harness's own.
    /// </summary>
    private static (bool Ok, string Text) Prints(string json)
    {
        var captured = new StringWriter();
        var real = Console.Out;
        Console.SetOut(captured);

        try
        {
            using var doc = JsonDocument.Parse(json);
            AgentRunner.PrintResult(doc.RootElement);
            return (true, captured.ToString());
        }
        catch (Exception ex)
        {
            Console.SetOut(real);
            Console.Error.WriteLine($"        threw {ex.GetType().Name}: {ex.Message}");
            return (false, captured.ToString());
        }
        finally
        {
            Console.SetOut(real);
        }
    }

    private static void Check(string what, bool ok)
    {
        Console.WriteLine(ok ? $"  [OK] {what}" : $"  [X]  {what}");

        if (!ok)
        {
            _failures++;
        }
    }

    /// <summary>
    /// The SYSTEM-task flags must be recognised as CLI modes, so App.OnStartup
    /// routes them to AgentRunner.Run instead of opening the installer UI. A flag
    /// that fell through to the GUI would leave `--install-service` opening a
    /// window and registering nothing.
    ///
    /// The elevation branch itself is not exercised here on purpose: Run()
    /// silences the console as part of daemon setup, which would swallow this
    /// harness's own output. The non-admin refusal is verified by hand on a
    /// standard-user machine; what is pinned here is the wiring that decides
    /// whether the flag is a CLI mode at all.
    /// </summary>
    private static void ServiceTaskCases()
    {
        Console.WriteLine("[*] system-task install flags");

        Check("--install-service is a CLI flag", AgentRunner.IsCliFlagForTest("--install-service"));
        Check("--remove-service is a CLI flag", AgentRunner.IsCliFlagForTest("--remove-service"));
        Check("--guard is a CLI flag", AgentRunner.IsCliFlagForTest("--guard"));
        Check("--parent-pid is a CLI flag", AgentRunner.IsCliFlagForTest("--parent-pid"));

        // The guard must judge a machine healthy unless the agent is genuinely
        // gone, or it would be rewrites on every tick - onto machines that never
        // needed touching.
        var dir = Path.Combine(Path.GetTempPath(), "ta_guard_" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(dir);
        Check("guard treats an empty folder as unhealthy", !AgentRunner.GuardSeesHealthyAgent(dir));

        var full = Path.Combine(dir, "TrackerAgent.exe");
        File.WriteAllBytes(full, new byte[2 * 1024 * 1024]);
        Check("guard treats a present exe as healthy", AgentRunner.GuardSeesHealthyAgent(dir));

        File.WriteAllBytes(full, new byte[1024]);
        Check("guard treats a truncated exe as unhealthy", !AgentRunner.GuardSeesHealthyAgent(dir));

        try { Directory.Delete(dir, recursive: true); } catch { }

        // Casing must not decide it: the launcher lower-cases, and a mixed-case
        // spelling from a script should still be treated as the same mode.
        Check("--Install-Service is recognised case-insensitively", AgentRunner.IsCliFlagForTest("--Install-Service"));
    }

    private sealed class RecordingHttpServer : IDisposable
    {
        private readonly HttpListener _listener = new();
        private readonly List<string> _requests = new();
        private readonly Task _acceptLoop;
        private readonly bool _failFingerprint;

        public RecordingHttpServer(bool failFingerprint = false)
        {
            _failFingerprint = failFingerprint;
            var port = GetFreePort();
            Url = $"http://localhost:{port}/";
            _listener.Prefixes.Add(Url);
            _listener.Start();
            _acceptLoop = Task.Run(AcceptLoop);
        }

        public string Url { get; }

        public IReadOnlyList<string> Requests => _requests;

        private async Task AcceptLoop()
        {
            while (_listener.IsListening)
            {
                try
                {
                    var context = await _listener.GetContextAsync();
                    var path = context.Request.Url?.AbsolutePath ?? string.Empty;
                    _requests.Add(path);

                    context.Response.StatusCode = _failFingerprint &&
                        path.EndsWith("agent/fingerprint", StringComparison.Ordinal) ? 500 : 200;
                    context.Response.ContentType = "application/json";
                    var body = "{\"ok\":true}\n";
                    var bytes = System.Text.Encoding.UTF8.GetBytes(body);
                    context.Response.ContentLength64 = bytes.Length;
                    await context.Response.OutputStream.WriteAsync(bytes, 0, bytes.Length);
                    context.Response.OutputStream.Close();
                }
                catch (HttpListenerException)
                {
                    break;
                }
                catch (ObjectDisposedException)
                {
                    break;
                }
            }
        }

        public void Dispose()
        {
            _listener.Stop();
            _listener.Close();
            _acceptLoop.Wait(TimeSpan.FromSeconds(5));
        }

        private static int GetFreePort()
        {
            var listener = new System.Net.Sockets.TcpListener(IPAddress.Loopback, 0);
            listener.Start();
            var port = ((IPEndPoint)listener.LocalEndpoint).Port;
            listener.Stop();
            return port;
        }
    }

    /// <summary>Scratch directory that deletes itself.</summary>
    private sealed class TempDir : IDisposable
    {
        // System.IO.Path, fully qualified: this class's own `Path` member shadows it.
        public string Path { get; } = System.IO.Path.Combine(
            System.IO.Path.GetTempPath(),
            "tracker-agent-tests-" + Guid.NewGuid().ToString("N"));

        public TempDir() => Directory.CreateDirectory(Path);

        public void Dispose()
        {
            try
            {
                Directory.Delete(Path, recursive: true);
            }
            catch
            {
                // Leftovers in %TEMP% are harmless.
            }
        }
    }
}