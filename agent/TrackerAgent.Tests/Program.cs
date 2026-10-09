using System.IO;
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
        ScanResultCases();

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