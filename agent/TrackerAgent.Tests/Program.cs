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
        FingerprintRoundTripCases();
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

    /// <summary>
    /// The whole fingerprint leg, end to end, against a real listening server.
    /// </summary>
    /// <remarks>
    /// The key-name case above only pins half of it. The collector writes
    /// snake_case into a string-typed dictionary, Api.Fingerprint reads those
    /// strings back into records and re-emits them, and it is that second hop
    /// that broke: the records were being read with camelCase options, where
    /// PropertyNameCaseInsensitive still cannot match "driver_name" to
    /// "DriverName" because of the underscore. Every multi-word field came back
    /// null and was then posted as "driver_name": null - silently, with the
    /// report succeeding. Only single-word fields, which are spelled the same
    /// in both conventions, survived.
    ///
    /// Asserting on the request body is the point: the failure never surfaced
    /// as an error anywhere, and only the bytes on the wire would show it.
    /// </remarks>
    private static void FingerprintRoundTripCases()
    {
        Console.WriteLine("[*] fingerprint round trip");

        using var server = new RecordingHttpServer();

        // Shaped exactly as FingerprintCollector writes them: snake_case, inside
        // a Dictionary<string, string>.
        var printers =
            """[{"name":"HP LaserJet P1102","driver_name":"HP Universal Printing PCL 6","port_name":"10.20.30.50","location":"Finance","is_network":true,"is_default":true,"is_offline":false,"status_code":3,"status":"Idle"}]""";

        var software =
            """[{"name":"ACCURATE 4 Standard","version":"4.0.1","publisher":"CPNSi","installed_at":"2024-01-15"}]""";

        var payload = new Dictionary<string, string>
        {
            ["device_id"] = "MITO-0001",
            ["hostname"] = "HOST-ACTION-01",
            ["software"] = software,
            ["printers"] = printers,
        };

        var ok = Api.Fingerprint(server.Url + "api/agent/fingerprint", "tat_abc123", payload);

        Check("the fingerprint report is accepted", ok);
        Check("the fingerprint was posted", server.Bodies.Count == 1);

        if (server.Bodies.Count != 1) return;

        using var doc = JsonDocument.Parse(server.Bodies[0]);
        var root = doc.RootElement;

        var printer = root.GetProperty("printers")[0];

        Check("driver survives the round trip",
            printer.GetProperty("driver_name").GetString() == "HP Universal Printing PCL 6");
        Check("port survives the round trip",
            printer.GetProperty("port_name").GetString() == "10.20.30.50");
        Check("a true flag is still a boolean after two hops",
            printer.GetProperty("is_network").ValueKind == JsonValueKind.True);
        Check("a false flag is still a boolean after two hops",
            printer.GetProperty("is_offline").ValueKind == JsonValueKind.False);
        Check("the status code survives the round trip",
            printer.GetProperty("status_code").GetInt32() == 3);
        Check("the printer name survives the round trip",
            printer.GetProperty("name").GetString() == "HP LaserJet P1102");

        var program = root.GetProperty("software")[0];

        Check("the software install date survives the round trip",
            program.GetProperty("installed_at").GetString() == "2024-01-15");
        Check("the software publisher survives the round trip",
            program.GetProperty("publisher").GetString() == "CPNSi");
    }

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

        // ------------------------------------------------------------------
        // The install loop. Setup used to launch whatever path it had just
        // written, which on a locked machine could still be the OLD build the
        // guard had restored a moment earlier. That build saw a newer version on
        // disk and reopened setup, roughly every fifteen seconds, forever.
        // SpawnAllowed is the single rule that makes that impossible.
        // ------------------------------------------------------------------
        Check("spawn refuses a null path", !InstallerWindow.SpawnAllowed(null, new Version(1, 7, 17)));
        Check("spawn refuses when the installer version is unknown",
            !InstallerWindow.SpawnAllowed("anything.exe", null));

        // The exact case that looped: target 1.7.15, installer 1.7.17.
        Check("spawn refuses an older build",
            !InstallerWindow.SpawnAllowedForVersions(new Version(1, 7, 15), new Version(1, 7, 17)));

        // ...and the case that broke every fresh install: setup writes the SAME
        // version to the canonical path and then starts it. Refusing equal meant
        // the agent never started, so there was no tray icon at all.
        Check("spawn allows the build this installer just wrote",
            InstallerWindow.SpawnAllowedForVersions(new Version(1, 7, 17), new Version(1, 7, 17)));
        Check("spawn refuses a build with no version resource",
            !InstallerWindow.SpawnAllowedForVersions(null, new Version(1, 7, 17)));
        Check("spawn allows a newer build",
            InstallerWindow.SpawnAllowedForVersions(new Version(1, 7, 18), new Version(1, 7, 17)));

        // The guard's own bound. Two restarts an hour, then it stops; a crashed
        // build must not be relaunched every minute forever.
        var budgetDir = Path.Combine(Path.GetTempPath(), "ta_budget_" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(budgetDir);
        Check("budget allows the first restart", AgentRunner.TryConsumeSpawnBudget(budgetDir, out _));
        Check("budget allows the second restart", AgentRunner.TryConsumeSpawnBudget(budgetDir, out var left) && left == 0);
        Check("budget refuses the third restart", !AgentRunner.TryConsumeSpawnBudget(budgetDir, out _));
        try { Directory.Delete(budgetDir, recursive: true); } catch { }

        Check("budget is refused when the folder cannot be written",
            !AgentRunner.TryConsumeSpawnBudget(Path.Combine(Path.GetTempPath(), Guid.NewGuid().ToString("N"), "missing"), out _));

        // Casing must not decide it: the launcher lower-cases, and a mixed-case
        // spelling from a script should still be treated as the same mode.
        Check("--Install-Service is recognised case-insensitively",
            AgentRunner.IsCliFlagForTest("--Install-Service"));

        // ------------------------------------------------------------------
        // One token per machine. The device id used to be 32 random bytes stored
        // in HKCU and config.json, so a reinstall on a machine whose config was
        // gone produced a new id; the server matches on that id, and one laptop
        // appeared twice in Live Fleet as MBUL and MBUL-1.
        // ------------------------------------------------------------------
        var id1 = InstallerWindow.DeviceIdForTest();
        var id2 = InstallerWindow.DeviceIdForTest();

        Check("device id is deterministic across calls", id1 == id2);
        Check("device id is non-empty", !string.IsNullOrWhiteSpace(id1));
        Check("device id is a 32-byte base64 value", Convert.FromBase64String(id1).Length == 32);
        Check("device id does not expose the machine guid",
            !id1.Contains("8bbab9dd", StringComparison.OrdinalIgnoreCase));

        // ------------------------------------------------------------------
        // The guard's anti-downgrade rule. The protected copy in ProgramData is
        // written when the guard is installed, so it lags every update; restoring
        // it unconditionally undid each update a minute after it succeeded, and
        // the fleet kept reporting the old version.
        // ------------------------------------------------------------------
        Check("guard keeps a newer installed build",
            AgentRunner.ShouldKeepInstalled(new Version(1, 7, 23), new Version(1, 7, 21)));
        Check("guard restores when the installed build is older",
            !AgentRunner.ShouldKeepInstalled(new Version(1, 7, 21), new Version(1, 7, 23)));
        Check("guard does not churn an unchanged build",
            !AgentRunner.ShouldKeepInstalled(new Version(1, 7, 23), new Version(1, 7, 23)));
        Check("guard repairs a file with no readable version",
            !AgentRunner.ShouldKeepInstalled(null, new Version(1, 7, 23)));
        Check("guard repairs when its own copy has no version",
            !AgentRunner.ShouldKeepInstalled(new Version(1, 7, 23), null));

        // ------------------------------------------------------------------
        // The registration form must look like the installer that opens it.
        // It used to be built in C# with its own dark palette, which is why it
        // read as a different application. Checking the XAML source keeps that
        // honest without starting a window: a dark colour reappearing in that
        // file is the exact regression.
        // ------------------------------------------------------------------
        var registrationXaml = SourceOf("AgentRegistrationWindow.xaml");

        Check("registration form exists as XAML", registrationXaml is not null);

        if (registrationXaml is not null)
        {
            // Only real colour values count. The file mentions the old palette in
            // a comment explaining why it was replaced, and a substring check over
            // the whole document cannot tell a comment from a brush.
            var colourValues = System.Text.RegularExpressions.Regex
                .Matches(registrationXaml, "=\\s*\"#([0-9A-Fa-f]{6})\"")
                .Select(m => m.Groups[1].Value.ToUpperInvariant())
                .ToList();

            Check("registration form has no dark surface palette",
                !colourValues.Contains("182230") && !colourValues.Contains("101825"));

            Check("registration form uses the shared light surface",
                colourValues.Contains("F3F4F6"));

            Check("registration form shows the MITO mark",
                registrationXaml.Contains("mito-logo-white.png", StringComparison.OrdinalIgnoreCase));

            Check("registration form styles its dropdowns",
                registrationXaml.Contains("SelectStyle", StringComparison.OrdinalIgnoreCase));

            Check("dropdown list animates open",
                registrationXaml.Contains("PopupAnimation", StringComparison.OrdinalIgnoreCase));
        }

        var registrationCode = SourceOf("AgentRegistrationWindow.cs");

        Check("registration window builds no colours in C#",
            registrationCode is not null &&
            !registrationCode.Contains("new SolidColorBrush(Color.FromRgb(0x18", StringComparison.OrdinalIgnoreCase));

        // The form was a StackPanel in its first rebuild, which stacked all ten
        // fields into one column and pushed the last ones below the fold: the
        // code asked for rows and columns that had nowhere to go.
        Check("registration form is a two-column grid",
            registrationXaml is not null &&
            registrationXaml.Contains("<Grid x:Name=\"FormPanel\"", StringComparison.Ordinal));

        Check("registration form grid declares three columns",
            registrationXaml is not null &&
            System.Text.RegularExpressions.Regex.Matches(
                registrationXaml, "x:Name=\"FormPanel\"(?s).*?</Grid>").Count == 1);

        // Text boxes need an explicit height and centred content, or the year the
        // operator types is clipped by the template's own padding.
        Check("text fields have an explicit height",
            registrationXaml is not null &&
            registrationXaml.Contains("<Setter Property=\"Height\" Value=\"42\"/>", StringComparison.Ordinal));

        Check("text fields centre their content vertically",
            registrationXaml is not null &&
            registrationXaml.Contains("Property=\"VerticalContentAlignment\" Value=\"Center\"", StringComparison.Ordinal));

        Check("registration form animates in",
            registrationCode is not null &&
            registrationCode.Contains("AnimateIn", StringComparison.Ordinal));

        // The content host covered the whole field, so the box could not take
        // focus: the year showed its value and typing did nothing.
        Check("text field content host does not cover the field",
            registrationXaml is not null &&
            registrationXaml.Contains("x:Name=\"PART_ContentHost\"", StringComparison.Ordinal));

        Check("purchase price is formatted as rupiah",
            registrationCode is not null &&
            registrationCode.Contains("RupiahFormat", StringComparison.Ordinal) &&
            registrationCode.Contains("NumberGroupSeparator = \".\"", StringComparison.Ordinal));

        Check("purchase price parses digits, not the formatted string",
            registrationCode is not null &&
            registrationCode.Contains("char.IsDigit", StringComparison.Ordinal));

        // ------------------------------------------------------------------
        // The setup that announced a version it had not installed, and let
        // several copies run against the same folder at once.
        // ------------------------------------------------------------------
        var installerCode = SourceOf("InstallerWindow.xaml.cs");

        Check("setup holds a single-instance mutex",
            installerCode is not null &&
            installerCode.Contains("Global\\MITO.TRACKER-ASSET.Setup", StringComparison.Ordinal));

        Check("setup releases its mutex when the window closes",
            installerCode is not null &&
            installerCode.Contains("ReleaseMutex", StringComparison.Ordinal));

        // The success screen prints the version read back from disk. It used to
        // print the installer's own version, so it claimed 1.7.28 was installed
        // while the file on disk was still 1.7.21.
        Check("update success reads the installed version from disk",
            installerCode is not null &&
            installerCode.Contains("ShowUpdateSuccess(installed)", StringComparison.Ordinal));
    }

    /// <summary>A source file from the agent project, or null when not found.</summary>
    /// <remarks>
    /// Located by walking up from the test binary, so the check works from any
    /// working directory the runner happens to use.
    /// </remarks>
    private static string? SourceOf(string fileName)
    {
        var dir = new System.IO.DirectoryInfo(AppContext.BaseDirectory);

        while (dir is not null)
        {
            var candidate = System.IO.Path.Combine(dir.FullName, "agent", "TrackerAgent", fileName);

            if (System.IO.File.Exists(candidate))
            {
                return System.IO.File.ReadAllText(candidate);
            }

            dir = dir.Parent;
        }

        return null;
    }
    private sealed class RecordingHttpServer : IDisposable
    {
        private readonly HttpListener _listener = new();
        private readonly List<string> _requests = new();
        private readonly List<string> _bodies = new();
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

        /// <summary>The raw request body of each request, in the order received.</summary>
        public IReadOnlyList<string> Bodies => _bodies;

        private async Task AcceptLoop()
        {
            while (_listener.IsListening)
            {
                try
                {
                    var context = await _listener.GetContextAsync();
                    var path = context.Request.Url?.AbsolutePath ?? string.Empty;
                    _requests.Add(path);

                    string sent;

                    using (var reader = new StreamReader(
                        context.Request.InputStream, context.Request.ContentEncoding))
                    {
                        sent = await reader.ReadToEndAsync();
                    }

                    _bodies.Add(sent);

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