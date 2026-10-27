using System.Diagnostics;
using System.IO;
using System.Linq;

namespace TrackerAgent;

/// <summary>
/// Standard installer sequence: check whether the agent is running, read its
/// version, stop an older build, clean leftovers, then copy the new build in.
/// Mirrors what a typical Windows installer does when launched on a machine
/// that already has an older version present.
/// </summary>
public static class InstallerService
{
    public const string ProcessName = "TrackerAgent";

    /// <summary>Every TrackerAgent process currently alive on this machine.</summary>
    public static IEnumerable<Process> RunningProcesses()
    {
        try
        {
            return Process.GetProcessesByName(ProcessName);
        }
        catch
        {
            return Enumerable.Empty<Process>();
        }
    }

    /// <summary>True when any TrackerAgent process is alive.</summary>
    public static bool IsRunning() => RunningProcesses().Any(p => !p.HasExited);

    /// <summary>Version of a running agent (numeric part only, e.g. 1.7.2), or null.</summary>
    public static Version? RunningVersion()
    {
        foreach (var proc in RunningProcesses())
        {
            try
            {
                var file = proc.MainModule?.FileName;
                if (string.IsNullOrWhiteSpace(file)) continue;
                return InstallCheck.ReadVersion(file);
            }
            catch { }
        }
        return null;
    }

    /// <summary>Build identity of a running agent, or null.</summary>
    public static string? RunningBuildIdentity()
    {
        foreach (var proc in RunningProcesses())
        {
            try
            {
                var file = proc.MainModule?.FileName;
                if (string.IsNullOrWhiteSpace(file)) continue;
                return InstallCheck.ReadBuildIdentity(file);
            }
            catch { }
        }
        return null;
    }

    /// <summary>Kill every TrackerAgent process and wait for each to exit.</summary>
    public static bool KillRunning(int timeoutMs = 5000)
    {
        var killedAny = false;
        foreach (var proc in RunningProcesses().ToList())
        {
            try
            {
                if (proc.HasExited) continue;
                if (proc.Id == Environment.ProcessId) continue; // jangan bunuh diri
                proc.Kill(entireProcessTree: true);
                proc.WaitForExit(timeoutMs);
                killedAny = true;
            }
            catch { }
        }
        return killedAny;
    }

    /// <summary>Remove stale artifacts left by a previous partial update.</summary>
    public static void CleanLeftovers(string installDir)
    {
        foreach (var pattern in new[] { "*.old", "_new_TrackerAgent_*.exe", "_check_TrackerAgent_*.exe", "_update_runner.bat" })
        {
            try
            {
                foreach (var file in Directory.GetFiles(installDir, pattern, SearchOption.TopDirectoryOnly))
                {
                    try { File.Delete(file); } catch { }
                }
            }
            catch { }
        }
    }

    /// <summary>
    /// Full standard install sequence. Returns <see cref="InstallResult.Aborted"/>
    /// when a same-or-newer build is already running (nothing to do), otherwise
    /// kills the old build, cleans leftovers and copies the new build over the
    /// installed executable. The caller is responsible for restarting the agent.
    /// </summary>
    public static InstallResult RunStandardInstall(
        string sourceExe,
        string installedExe,
        Version? newVersion,
        Version? installedVersion,
        Action<int, string>? onProgress = null)
    {
        var result = new InstallResult();
        var installDir = Path.GetDirectoryName(installedExe)!;

        // 1. Cek ada yang running
        onProgress?.Invoke(5, "Memeriksa agent yang sedang berjalan...");
        var running = RunningProcesses().Where(p => !p.HasExited).ToList();
        result.WasRunning = running.Count > 0;

        // 2. Cek versi yang running
        Version? runningVersion = null;
        foreach (var proc in running)
        {
            try
            {
                var file = proc.MainModule?.FileName;
                if (!string.IsNullOrWhiteSpace(file))
                {
                    runningVersion = InstallCheck.ReadVersion(file);
                    break;
                }
            }
            catch { }
        }
        result.RunningVersion = runningVersion;

        // 3. Kalo ada versi lama, kill yg lama.
        // Kill when nothing readable is running (clear the lock) or the running
        // build is older than the one being installed.
        var shouldKill = runningVersion is null ||
                         (newVersion is not null && runningVersion < newVersion);
        if (!shouldKill)
        {
            result.Aborted = true;
            result.Message = runningVersion is not null
                ? $"Versi {runningVersion} sudah berjalan. Tidak ada yang perlu diinstall."
                : "Agent sedang berjalan tetapi versinya tidak terbaca. Tidak ada yang diinstall.";
            return result;
        }

        onProgress?.Invoke(15, "Menghentikan agent versi lama...");
        KillRunning();

        // 4. Udh bersih → bersihkan sisa file lama
        onProgress?.Invoke(30, "Membersihkan sisa instalasi lama...");
        CleanLeftovers(installDir);

        // 5. Proses install versi baru
        onProgress?.Invoke(50, "Menginstall versi baru...");
        CopyWithRetry(sourceExe, installedExe);

        result.Installed = true;
        result.Message = newVersion is not null
            ? $"Berhasil diinstall versi {newVersion}."
            : "Berhasil diinstall.";
        return result;
    }

    public static void CopyWithRetry(string source, string destination)    {
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
}

/// <summary>Outcome of the standard install sequence.</summary>
public class InstallResult
{
    public bool WasRunning { get; set; }
    public Version? RunningVersion { get; set; }
    public bool Aborted { get; set; }
    public bool Installed { get; set; }
    public string Message { get; set; } = "";
}
