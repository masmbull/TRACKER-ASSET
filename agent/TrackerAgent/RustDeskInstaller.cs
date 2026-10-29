using System;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Reflection;

namespace TrackerAgent;

/// <summary>
/// Installs RustDesk silently from the copy embedded in this agent.
/// </summary>
/// <remarks>
/// The embedded file is an NSIS installer, which supports <c>/S</c> for a silent
/// run. It is extracted to a temporary path first because Windows cannot execute a
/// resource out of an assembly: the process has to be a file on disk with a real
/// path.
///
/// The temporary copy is private to this process (a GUID folder under the user's
/// temp) and is deleted afterwards - not because it is secret, but so a failed
/// install does not leave a 26 MB stray installer on every laptop.
/// </remarks>
internal static class RustDeskInstaller
{
    private const string ResourceName = "TrackerAgent.vendor.rustdesk-setup.exe";

    /// <summary>True when RustDesk is already on this machine.</summary>
    internal static bool IsInstalled() => RustDeskProvisioner.FindExecutable() is not null;

    /// <summary>
    /// Installs RustDesk and waits for it to finish.
    /// </summary>
    /// <param name="progress">Human-readable stage, shown in the installer list.</param>
    internal static bool TryInstall(Action<string>? progress = null)
    {
        if (IsInstalled())
        {
            progress?.Invoke("RustDesk sudah terpasang.");
            return true;
        }

        var tempDir = Path.Combine(Path.GetTempPath(), "tracker-rustdesk-" + Guid.NewGuid().ToString("N"));
        var installerPath = Path.Combine(tempDir, "rustdesk-setup.exe");

        try
        {
            Directory.CreateDirectory(tempDir);

            progress?.Invoke("Menyiapkan RustDesk...");

            using (var resource = Assembly.GetExecutingAssembly().GetManifestResourceStream(ResourceName))
            {
                if (resource is null)
                {
                    // A build without the resource is a packaging mistake, and it
                    // must not stop the agent from installing: remote desktop is
                    // an extra, fleet reporting is the point.
                    progress?.Invoke("RustDesk tidak tersedia di paket ini.");
                    return false;
                }

                using var file = File.Create(installerPath);
                resource.CopyTo(file);
            }

            progress?.Invoke("Memasang dependensi agent (RustDesk)...");

            using var process = Process.Start(new ProcessStartInfo
            {
                FileName = installerPath,
                // /S silent, /D the target folder as the last argument unquoted -
                // an NSIS requirement, not a typo.
                Arguments = "/S",
                UseShellExecute = false,
                CreateNoWindow = true,
            });

            if (process is null)
            {
                progress?.Invoke("RustDesk gagal dijalankan.");
                return false;
            }

            // Generous but bounded: an installer that hangs must not hang setup.
            if (!process.WaitForExit(300_000))
            {
                try { process.Kill(entireProcessTree: true); } catch { }
                progress?.Invoke("Pemasangan RustDesk melewati batas waktu.");
                return false;
            }

            // The installer returns before the service is registered on a slow
            // disk, so the check is "is the binary there", not the exit code.
            for (var attempt = 0; attempt < 20 && !IsInstalled(); attempt++)
            {
                System.Threading.Thread.Sleep(1000);
            }

            var ok = IsInstalled();

            progress?.Invoke(ok ? "RustDesk terpasang." : "RustDesk tidak terpasang.");

            return ok;
        }
        catch (Exception ex)
        {
            AgentLog.Write($"RustDesk install failed: {ex}");
            progress?.Invoke("RustDesk gagal dipasang.");
            return false;
        }
        finally
        {
            try
            {
                if (Directory.Exists(tempDir))
                {
                    Directory.Delete(tempDir, recursive: true);
                }
            }
            catch
            {
                // A leftover temp file is not worth failing setup over.
            }
        }
    }
}
