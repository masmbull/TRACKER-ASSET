using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Text;

namespace TrackerAgent;

/// <summary>
/// Silent, unattended RustDesk provisioning.
/// </summary>
/// <remarks>
/// Two things make unattended access work without a human at the laptop:
/// the client must point at the self-hosted server, and it must have a permanent
/// password. Both are written straight into RustDesk's config, never passed on a
/// command line: a command line is visible in the process list to any user on
/// the machine, and a password there is a password handed to whoever is logged
/// in. The password arrives from the server per device and is rotated after use,
/// so a leaked value is short-lived by construction.
///
/// Where the config lives differs by install mode:
/// - service install (the normal case) keeps it under the service profile,
/// - a per-user install keeps it in %APPDATA%.
/// Both are written when writable so the tray and the service agree.
/// </remarks>
internal static class RustDeskProvisioner
{
    internal const string DefaultRendezvous = "REDACTED_SERVER_IP";

    /// <summary>Config roots that belong to a RustDesk install, most specific first.</summary>
    private static IEnumerable<string> ConfigRoots()
    {
        var windows = Environment.GetFolderPath(Environment.SpecialFolder.Windows);
        var appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);

        if (!string.IsNullOrWhiteSpace(windows))
        {
            // The SYSTEM profile: this is the identity the service runs as, so it
            // is the config that decides which id remote clients dial.
            yield return Path.Combine(windows, "System32", "config", "systemprofile", "AppData", "Roaming", "RustDesk", "config");
            yield return Path.Combine(windows, "ServiceProfiles", "LocalService", "AppData", "Roaming", "RustDesk", "config");
        }

        if (!string.IsNullOrWhiteSpace(appData))
        {
            yield return Path.Combine(appData, "RustDesk", "config");
        }
    }

    internal static string? FindExecutable()
    {
        foreach (var programFiles in new[]
                 {
                     Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles),
                     Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86),
                 })
        {
            if (string.IsNullOrWhiteSpace(programFiles))
            {
                continue;
            }

            var exe = Path.Combine(programFiles, "RustDesk", "RustDesk.exe");

            if (File.Exists(exe))
            {
                return exe;
            }
        }

        return null;
    }

    /// <summary>
    /// Applies server, key and permanent password to every writable config root.
    /// </summary>
    /// <returns>The number of config files written.</returns>
    internal static int Apply(string? server, string? publicKey, string? password)
    {
        if (string.IsNullOrWhiteSpace(password))
        {
            throw new ArgumentException("RustDesk password must not be empty.", nameof(password));
        }

        var written = 0;

        foreach (var root in ConfigRoots())
        {
            try
            {
                Directory.CreateDirectory(root);
                var path = Path.Combine(root, "RustDesk.toml");

                WriteConfig(path, server, publicKey, password);
                written++;
            }
            catch (Exception ex)
            {
                AgentLog.Write($"RustDesk config at {root} not writable: {ex.Message}");
            }
        }

        if (written == 0)
        {
            throw new IOException("No writable RustDesk configuration folder was found.");
        }

        return written;
    }

    /// <summary>
    /// Merges the required keys into an existing RustDesk.toml.
    /// </summary>
    /// <remarks>
    /// Rewritten key by key rather than replaced wholesale: the file also holds
    /// the peer id and any server the operator configured. Replacing it would
    /// mint a new id on the next start, which silently invalidates every saved
    /// remote entry and every id the server has on record for this machine.
    /// </remarks>
    private static void WriteConfig(string path, string? server, string? publicKey, string password)
    {
        var lines = File.Exists(path) ? File.ReadAllLines(path).ToList() : new List<string>();

        SetKey(lines, "rendezvous_server", server ?? DefaultRendezvous, beforeSection: true);

        // Both of these are top-level keys in RustDesk's config, not options.
        //
        // "password" was previously written inside [options], where RustDesk does
        // not read it: the client started, showed "One-time password: Generating..."
        // and rejected every remote login. "key" belongs beside "rendezvous_server"
        // for the same reason - it is connection setup, not a UI preference.
        if (!string.IsNullOrWhiteSpace(publicKey))
        {
            SetKey(lines, "key", publicKey, beforeSection: true);
        }

        SetKey(lines, "password", password, beforeSection: true);

        var text = string.Join(Environment.NewLine, lines).TrimEnd() + Environment.NewLine;

        // Written through a temp file and moved into place: RustDesk reads this on
        // start, and a half-written file would be a client that never comes back.
        var temp = path + ".tmp";
        File.WriteAllText(temp, text, new UTF8Encoding(false));

        if (File.Exists(path))
        {
            File.Replace(temp, path, null, ignoreMetadataErrors: true);
        }
        else
        {
            File.Move(temp, path);
        }
    }

    /// <summary>
    /// Sets <c>name = 'value'</c>, creating the key or the [options] section as
    /// needed, and leaving every other line alone.
    /// </summary>
    private static void SetKey(List<string> lines, string name, string value, bool beforeSection)
    {
        var quoted = $"'{value.Replace("'", "\\'")}'";

        if (beforeSection)
        {
            for (var i = 0; i < lines.Count; i++)
            {
                if (lines[i].TrimStart().StartsWith('['))
                {
                    break;
                }

                if (lines[i].TrimStart().StartsWith(name + " ", StringComparison.OrdinalIgnoreCase) ||
                    lines[i].TrimStart().StartsWith(name + "=", StringComparison.OrdinalIgnoreCase))
                {
                    lines[i] = $"{name} = {quoted}";
                    return;
                }
            }

            lines.Insert(0, $"{name} = {quoted}");
            return;
        }

        var sectionStart = lines.FindIndex(l => l.Trim().Equals("[options]", StringComparison.OrdinalIgnoreCase));

        if (sectionStart < 0)
        {
            lines.Add(string.Empty);
            lines.Add("[options]");
            lines.Add($"{name} = {quoted}");
            return;
        }

        for (var i = sectionStart + 1; i < lines.Count; i++)
        {
            var trimmed = lines[i].TrimStart();

            if (trimmed.StartsWith('['))
            {
                // End of [options]: insert just before the next section.
                lines.Insert(i, $"{name} = {quoted}");
                return;
            }

            if (trimmed.StartsWith(name + " ", StringComparison.OrdinalIgnoreCase) ||
                trimmed.StartsWith(name + "=", StringComparison.OrdinalIgnoreCase))
            {
                lines[i] = $"{name} = {quoted}";
                return;
            }
        }

        lines.Add($"{name} = {quoted}");
    }

    /// <summary>
    /// Restarts the RustDesk service so it reloads the config that was just
    /// written. Best effort: without elevation the write may still have reached
    /// the user profile, and the client picks it up on its next start.
    /// </summary>
    /// <summary>
    /// Closes the RustDesk desktop window and stops it from starting with Windows.
    /// </summary>
    /// <remarks>
    /// The client puts a window on the logged-in user's desktop - its own id, a
    /// banner offering to install the service, and "Not ready. Please check your
    /// connection" - and it is registered to run at every logon by default. On a
    /// managed laptop that is both noise and a standing invitation to press buttons
    /// that break remote access, so the interactive client is removed from the
    /// logon list while the service, which is what actually answers remote
    /// sessions, is left running.
    ///
    /// Best effort: a process in another user's session cannot always be ended from
    /// here, and a missing Run value is already the desired state.
    /// </remarks>
    internal static void SilenceDesktopClient()
    {
        try
        {
            foreach (var process in System.Diagnostics.Process.GetProcessesByName("rustdesk"))
            {
                try
                {
                    // The service usually runs as a second process with the same
                    // name; only the interactive one has a visible window.
                    if (process.MainWindowHandle != IntPtr.Zero || process.SessionId != 0)
                    {
                        process.Kill(entireProcessTree: false);
                    }
                }
                catch
                {
                    // Another user's session, or already gone.
                }
            }
        }
        catch
        {
            // Enumeration failed: nothing to do.
        }

        RemoveClientAutostart();
    }

    /// <summary>Removes RustDesk's per-user and per-machine logon entries.</summary>
    private static void RemoveClientAutostart()
    {
        foreach (var (root, view) in new[]
                 {
                     (Microsoft.Win32.Registry.CurrentUser, Microsoft.Win32.RegistryView.Default),
                     (Microsoft.Win32.Registry.LocalMachine, Microsoft.Win32.RegistryView.Registry64),
                 })
        {
            try
            {
                using var key = root.OpenSubKey(
                    @"SOFTWARE\Microsoft\Windows\CurrentVersion\Run", writable: true);

                if (key?.GetValue("RustDesk") is not null)
                {
                    key.DeleteValue("RustDesk", throwOnMissingValue: false);
                }
            }
            catch
            {
                // No rights (HKLM without elevation) or no key.
            }
        }
    }

    internal static bool TryRestartService(out string message)
    {
        try
        {
            var start = new ProcessStartInfo("sc.exe", "stop RustDesk")
            {
                CreateNoWindow = true,
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            };

            using (var stop = Process.Start(start))
            {
                stop?.WaitForExit(15000);
            }

            using var run = Process.Start(new ProcessStartInfo("sc.exe", "start RustDesk")
            {
                CreateNoWindow = true,
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            });

            if (run is null)
            {
                message = "Could not start the RustDesk service.";
                return false;
            }

            var output = run.StandardOutput.ReadToEnd() + run.StandardError.ReadToEnd();
            run.WaitForExit(15000);

            var ok = run.ExitCode == 0;

            message = ok ? "RustDesk service restarted." : $"RustDesk service restart failed: {output.Trim()}";

            return ok;
        }
        catch (Exception ex)
        {
            message = $"RustDesk service restart failed: {ex.Message}";
            return false;
        }
    }
}
