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

    /// <summary>
    /// Reads the assembly version of an exe (ProductVersion, falling back to
    /// FileVersion), stripping the "+gitHash" suffix the SDK may append.
    /// Returns null when the file is missing or unreadable — the caller then
    /// falls back to the pre-version-check behaviour.
    /// </summary>
    public static Version? ReadVersion(string? exePath)
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

            if (string.IsNullOrWhiteSpace(raw))
            {
                return null;
            }

            var normalized = raw.Trim().Split('+')[0].Trim();

            return Version.TryParse(normalized, out var version) ? version : null;
        }
        catch
        {
            return null;
        }
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