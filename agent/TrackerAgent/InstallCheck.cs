using System.Diagnostics;
using System.IO;

namespace TrackerAgent;

/// <summary>How a double-clicked installer relates to the agent already on the machine.</summary>
public enum InstallerMode
{
    /// <summary>Agent not installed yet — full zero-config install.</summary>
    Install,

    /// <summary>Agent installed, clicked build has a different version — in-place update.</summary>
    Update,

    /// <summary>Agent installed, clicked build is the same version — just a notice.</summary>
    AlreadyInstalled,

    /// <summary>System tray update check: detect server reachability and update availability.</summary>
    CheckForUpdates,
}

/// <summary>
/// Double-click install-state probe. When an admin double-clicks a downloaded
/// TrackerAgent.exe on a machine that may already run the agent, this class
/// answers two questions: "is anything installed?" and "is the clicked build
/// the same version as the installed one?" (agent/README.md).
/// </summary>
public static class InstallCheck
{
    /// <summary>%LocalAppData%\TRACKER — same folder the installer writes.</summary>
    public static string InstallDir =>
        Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "TRACKER");

    public static string InstalledExe => Path.Combine(InstallDir, "TrackerAgent.exe");

    public static string InstalledConfig => Path.Combine(InstallDir, "config.json");

    internal static string BuildTrayAutostartCommand(string exePath) => $"\"{exePath}\"";

    /// <summary>
    /// Read the full build identity of an exe: "1.2.0+abcdef".
    /// </summary>
    /// <remarks>
    /// The commit suffix is kept, and it matters. The install check refuses to
    /// run when the clicked build looks identical to the installed one, so
    /// stripping the hash made every rebuild under an unchanged version number
    /// indistinguishable from the build already on the machine: a bug fix was
    /// published, the download served it, and the target answered "Aplikasi
    /// Sudah Pernah Diinstall" and never took it. The hash is the only thing
    /// that distinguishes two binaries that claim the same version.
    ///
    /// Returns null when the file is missing or unreadable, and the caller then
    /// falls back to the pre-version-check behaviour.
    /// </remarks>
    public static string? ReadBuildIdentity(string? exePath)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(exePath) || !File.Exists(exePath))
            {
                return null;
            }

            var info = FileVersionInfo.GetVersionInfo(exePath);
            var raw = !string.IsNullOrWhiteSpace(info.ProductVersion)
                ? info.ProductVersion
                : info.FileVersion;

            return string.IsNullOrWhiteSpace(raw) ? null : raw.Trim();
        }
        catch
        {
            return null;
        }
    }

    /// <summary>
    /// Read the assembly version of an exe, stripping the "+gitHash" suffix.
    /// </summary>
    /// <remarks>
    /// Kept for display and for the "installed vX vs new vY" message, where the
    /// numeric part is what a human reads. Never use this to decide whether the
    /// two binaries are the same build - use <see cref="ReadBuildIdentity"/>.
    /// </remarks>
    public static Version? ReadVersion(string? exePath)
    {
        var identity = ReadBuildIdentity(exePath);

        if (identity is null)
        {
            return null;
        }

        var normalized = identity.Split('+')[0].Trim();

        return Version.TryParse(normalized, out var version) ? version : null;
    }

    /// <summary>
    /// True when the two binaries are genuinely the same build.
    /// </summary>
    /// <remarks>
    /// Compares the full identity, hash included. Anything less means a rebuilt
    /// binary is treated as already-installed and the update never lands.
    ///
    /// A null on either side is not "same": the caller cannot prove the builds
    /// match, and answering "different" is the safe direction - it lets the
    /// update proceed rather than locking the machine on an older binary because
    /// one file was unreadable.
    /// </remarks>
    public static bool IsSameBuild(string? leftIdentity, string? rightIdentity)
    {
        if (string.IsNullOrWhiteSpace(leftIdentity) || string.IsNullOrWhiteSpace(rightIdentity))
        {
            return false;
        }

        return string.Equals(leftIdentity.Trim(), rightIdentity.Trim(), StringComparison.OrdinalIgnoreCase);
    }

    /// <summary>True when both paths point at the same file.</summary>
    public static bool IsSameFile(string? left, string? right)
    {
        if (string.IsNullOrWhiteSpace(left) || string.IsNullOrWhiteSpace(right))
        {
            return false;
        }

        try
        {
            return string.Equals(
                Path.GetFullPath(left),
                Path.GetFullPath(right),
                StringComparison.OrdinalIgnoreCase);
        }
        catch
        {
            return string.Equals(left, right, StringComparison.OrdinalIgnoreCase);
        }
    }
}