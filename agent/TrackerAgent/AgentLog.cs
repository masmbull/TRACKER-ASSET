using System.Diagnostics;
using System.IO;

namespace TrackerAgent;

/// <summary>
/// Minimal rolling log for the background daemon and the tray loop.
/// </summary>
/// <remarks>
/// Added because a silent agent is indistinguishable from a broken one. This
/// machine's autostart runs the daemon with --quiet and no terminal, so a daemon
/// that answered 401 to every poll, or that never got past startup, left no
/// evidence anywhere: the tray process was not even running, and the only trace
/// of the crash was a Windows Error Reporting entry nobody looks at. The file
/// lives next to config.json, is capped and rotated once, and never throws -
/// logging must not be able to take the agent down.
/// </remarks>
public static class AgentLog
{
    private const long MaxBytes = 256 * 1024;

    private static readonly object Gate = new();

    private static string Path => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "TRACKER",
        "agent.log");

    public static void Write(string message)
    {
        try
        {
            lock (Gate)
            {
                var path = Path;

                Directory.CreateDirectory(System.IO.Path.GetDirectoryName(path)!);

                if (File.Exists(path) && new FileInfo(path).Length > MaxBytes)
                {
                    var previous = path + ".1";

                    File.Delete(previous);
                    File.Move(path, previous);
                }

                File.AppendAllText(
                    path,
                    $"[{DateTimeOffset.Now:yyyy-MM-dd HH:mm:ss}] {message}{Environment.NewLine}");
            }
        }
        catch
        {
            // A locked-down machine still has to run the agent.
        }
    }

    /// <summary>
    /// Route Console.Out and Console.Error through this log as well as the
    /// terminal.
    /// </summary>
    /// <remarks>
    /// Called once at startup, before the mode is decided, so the daemon, one-shot
    /// and tray modes all end up in the same file. It wraps rather than replaces:
    /// a user running the agent from PowerShell still sees the output there.
    /// </remarks>
    public static void MirrorConsole()
    {
        try
        {
            Console.SetOut(new MirrorWriter(Console.Out));
            Console.SetError(new MirrorWriter(Console.Error));
        }
        catch
        {
            // No console at all: nothing to mirror, which is fine.
        }
    }

    /// <summary>Passes writes through to the real writer and copies them to the log.</summary>
    private sealed class MirrorWriter(TextWriter inner) : TextWriter
    {
        public override System.Text.Encoding Encoding => inner.Encoding;

        public override void Write(string? value)
        {
            inner.Write(value);

            if (!string.IsNullOrWhiteSpace(value))
            {
                AgentLog.Write(value);
            }
        }

        /// <summary>
        /// Console.Write(newline) and WriteLine() both arrive here; only the
        /// former is a stray break, so it is the one dropped from the log.
        /// </summary>
        public override void WriteLine()
        {
            inner.WriteLine();
        }

        public override void WriteLine(string? value)
        {
            inner.WriteLine(value);
            AgentLog.Write(value ?? string.Empty);
        }
    }
}