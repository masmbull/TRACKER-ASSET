using System.IO;
using System.Text;
using System.Text.Json;

namespace TrackerAgent;

/// <summary>
/// Zero-config install breadcrumb (agent/README.md).
///
/// The server serves the installer with a
/// <c>&lt;&lt;&lt;TRACKER-AGENT-CONFIG:&lt;base64 json&gt;:END&gt;&gt;&gt;</c> trailer appended to the
/// executable, so a freshly downloaded agent already knows which server it
/// belongs to — no URL or token prompt during installation.
///
/// The .NET 8 single-file host locates its bundle through an absolute offset
/// (the manifest lives at the end of the file, followed by nothing), so the
/// trailing bytes are ignored by the loader.
/// </summary>
public static class AgentConfigTrailer
{
    private const string MarkerStart = "<<<TRACKER-AGENT-CONFIG:";
    private const string MarkerEnd = ":END>>>";

    /// <summary>How many trailing bytes are searched for the breadcrumb.</summary>
    private const int TailBytes = 64 * 1024;

    public sealed record BakedConfig(List<string> ServerUrls, string? IssuedAt);

    /// <summary>
    /// Reads the breadcrumb from the running executable (falling back to the
    /// installed copy). Returns null for locally built binaries.
    /// </summary>
    public static BakedConfig? TryRead()
    {
        foreach (var path in CandidatePaths())
        {
            var config = TryReadFrom(path);

            if (config is not null)
            {
                return config;
            }
        }

        return null;
    }

    private static List<string> CandidatePaths()
    {
        var paths = new List<string>();
        var processPath = Environment.ProcessPath;

        if (!string.IsNullOrWhiteSpace(processPath))
        {
            paths.Add(processPath!);
        }

        var installed = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "TRACKER",
            "TrackerAgent.exe");

        if (!paths.Any(p => string.Equals(p, installed, StringComparison.OrdinalIgnoreCase)))
        {
            paths.Add(installed);
        }

        return paths;
    }

    /// <summary>
    /// Extracts the baked configuration from <paramref name="path"/>. Any read
    /// or parse problem simply yields null: the installer then falls back to
    /// probing known addresses, so a broken trailer can never block an install.
    /// </summary>
    public static BakedConfig? TryReadFrom(string path)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(path) || !File.Exists(path))
            {
                return null;
            }

            using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite);
            var length = stream.Length;
            var tail = (int)Math.Min(TailBytes, length);

            if (tail <= 0)
            {
                return null;
            }

            stream.Seek(length - tail, SeekOrigin.Begin);
            var buffer = new byte[tail];
            var read = stream.Read(buffer, 0, tail);

            if (read <= 0)
            {
                return null;
            }

            var text = Encoding.UTF8.GetString(buffer, 0, read);
            var start = text.LastIndexOf(MarkerStart, StringComparison.Ordinal);

            if (start < 0)
            {
                return null;
            }

            var end = text.IndexOf(MarkerEnd, start, StringComparison.Ordinal);

            if (end < 0)
            {
                return null;
            }

            var encoded = text[(start + MarkerStart.Length)..end];
            var json = Encoding.UTF8.GetString(Convert.FromBase64String(encoded));

            using var doc = JsonDocument.Parse(json);
            var urls = new List<string>();

            if (doc.RootElement.TryGetProperty("server_urls", out var array) && array.ValueKind == JsonValueKind.Array)
            {
                foreach (var item in array.EnumerateArray())
                {
                    var url = item.GetString();

                    if (!string.IsNullOrWhiteSpace(url))
                    {
                        urls.Add(url!.TrimEnd('/'));
                    }
                }
            }

            var issuedAt = doc.RootElement.TryGetProperty("issued_at", out var issued) ? issued.GetString() : null;

            return urls.Count > 0 ? new BakedConfig(urls, issuedAt) : null;
        }
        catch
        {
            return null;
        }
    }
}
