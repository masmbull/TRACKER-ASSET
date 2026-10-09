using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

namespace TrackerAgent;

public static class Api
{
    // Has to outlive the server's long-poll cap (60s), or every parked request is
    // cancelled client-side and the long-poll degenerates into a slow poll.
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(90) };

    /// <summary>
    /// camelCase everywhere.
    /// </summary>
    /// <remarks>
    /// System.Text.Json defaults to PascalCase for property names, so the
    /// installed-software records were serialised as {"Name":...} while the API
    /// validates software.*.name. The server rejected the whole report with 422,
    /// and because the agent swallows the body it just logged "rejected". These
    /// options are shared so the collector and the sender cannot drift apart.
    /// </remarks>
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
        PropertyNameCaseInsensitive = true,
    };

    public static JsonElement? Post(string url, string token, Dictionary<string, string> payload)
    {
        var json = JsonSerializer.Serialize(payload);
        using var req = new HttpRequestMessage(HttpMethod.Post, $"{url}/api/audits")
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json")
        };
        Authenticate(req, token);

        using var resp = Http.Send(req);
        var body = resp.Content.ReadAsStringAsync().GetAwaiter().GetResult();

        if (!resp.IsSuccessStatusCode) return null;

        return JsonDocument.Parse(body).RootElement.Clone();
    }

    /// <summary>
    /// Pushes a lightweight telemetry ping so the server's Live Fleet view stays
    /// current without a full audit. Returns true when the server accepted it.
    /// </summary>
    /// <remarks>
    /// Deliberately fire-and-forget from the caller's point of view: this runs
    /// on the poll loop's own schedule, and a heartbeat that fails must never
    /// interrupt command handling. A full audit on this path would write an
    /// asset_audits row every minute.
    /// </remarks>
    public static bool Heartbeat(string url, string token, Dictionary<string, string> payload)
    {
        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Post, $"{url}/api/agent/heartbeat")
            {
                Content = new StringContent(
                    JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json")
            };
            Authenticate(req, token);

            using var resp = Http.Send(req);

            if (resp.IsSuccessStatusCode)
            {
                return true;
            }

            // Silent failure here is what made a rejected heartbeat look
            // identical to an unreachable server for hours. Say which.
            Console.Error.WriteLine(
                $"[!] Heartbeat rejected: HTTP {(int)resp.StatusCode} {resp.ReasonPhrase}");

            return false;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Heartbeat unreachable: {ex.Message}");

            return false;
        }
    }

    /// <summary>
    /// Pushes the deep-inventory report to /api/agent/fingerprint.
    /// </summary>
    /// <remarks>
    /// The `software` entry arrives as a JSON string because the agent's payload
    /// dictionary is string-typed; it is re-hydrated here into a real array so
    /// the server can validate it as a structured list rather than trusting an
    /// opaque blob.
    /// </remarks>
    public static bool Fingerprint(string url, string token, Dictionary<string, string> payload)
    {
        try
        {
            // A fresh Dictionary<string, object?> cannot copy a
            // Dictionary<string, string> directly: the two have different value
            // types even though every entry is a string.
            var body = payload.ToDictionary(
                pair => pair.Key,
                pair => (object?)pair.Value);

            if (payload.TryGetValue("software", out var rawSoftware) && !string.IsNullOrWhiteSpace(rawSoftware))
            {
                body["software"] = JsonSerializer.Deserialize<List<SoftwareEntry>>(rawSoftware, JsonOptions)
                    ?? new List<SoftwareEntry>();
            }
            else
            {
                body.Remove("software");
            }

            using var req = new HttpRequestMessage(HttpMethod.Post, $"{url}/api/agent/fingerprint")
            {
                Content = new StringContent(
                    JsonSerializer.Serialize(body, JsonOptions), Encoding.UTF8, "application/json")
            };
            Authenticate(req, token);

            using var resp = Http.Send(req);

            if (resp.IsSuccessStatusCode)
            {
                return true;
            }

            Console.Error.WriteLine(
                $"[!] Fingerprint rejected: HTTP {(int)resp.StatusCode} {resp.ReasonPhrase}");

            return false;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Fingerprint error: {ex.Message}");

            return false;
        }
    }

    /// <summary>Version string of the running binary, or null when it cannot be read.</summary>
/// <remarks>
/// Sent as X-Agent-Version on every call, so the server always knows which build
/// is on a given laptop. Without it every device reads "unknown" forever, which
/// is indistinguishable from a fleet of agents that never reported - an
/// operator cannot tell a stale build from a silent one.
///
/// The full identity (version + git hash) goes in the header. The server reads
/// the numeric part, which is what decides whether an update is needed.
/// </remarks>
internal static string? CurrentBuildIdentity()
{
    try
    {
        var location = System.Reflection.Assembly.GetEntryAssembly()?.Location;

        return string.IsNullOrWhiteSpace(location) ? null : InstallCheck.ReadBuildIdentity(location);
    }
    catch
    {
        return null;
    }
}

/// <summary>Attach the bearer token and this build's identity to a request.</summary>
/// <remarks>
/// Every authenticated call goes through here, so the version cannot be
/// forgotten on whichever endpoint happened to report first. The server stores it
/// whenever the header is present and leaves the recorded version alone when it
/// is not, so an agent built before this still works - it just keeps reading
/// "unknown" until it is updated, which is the honest thing to show.
/// </remarks>
private static void Authenticate(HttpRequestMessage req, string token)
{
    req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);

    var identity = CurrentBuildIdentity();

    if (!string.IsNullOrWhiteSpace(identity))
    {
        req.Headers.TryAddWithoutValidation("X-Agent-Version", identity);
    }
}

/// <summary>One entry of the installed-software list, as the server sees it.</summary>
private sealed record SoftwareEntry(string? Name, string? Version, string? Publisher, string? InstalledAt);

    public static List<JsonElement>? GetInbox(string url, string token) =>
        GetInbox(url, token, 0, out _);

    /// <param name="unauthorized">
    /// True when the server actively rejected the credential. The daemon needs
    /// this to tell "token is dead" apart from "network blip": the first is
    /// recoverable by re-registering, the second must simply be retried.
    /// </param>
    /// <param name="waitSeconds">
    /// Ask the server to hold the request open until a command exists, instead of
    /// returning empty straight away. Zero keeps the old behaviour: return now,
    /// sleep, ask again.
    /// </param>
    public static List<JsonElement>? GetInbox(string url, string token, int waitSeconds, out bool unauthorized)
    {
        unauthorized = false;

        try
        {
            var endpoint = waitSeconds > 0
                ? $"{url}/api/agent/inbox?wait={Math.Clamp(waitSeconds, 1, 60)}"
                : $"{url}/api/agent/inbox";

            using var req = new HttpRequestMessage(HttpMethod.Get, endpoint);
            Authenticate(req, token);

            // Must outlast the server-side wait (clamped to 60s there) or every
            // parked long-poll is cancelled here instead of answered, turning
            // the long-poll into a slow plain poll.
            using var cts = new System.Threading.CancellationTokenSource(TimeSpan.FromSeconds(65));

            HttpResponseMessage resp = Http.Send(req, cts.Token);

            using (resp)
            {
                var body = resp.Content.ReadAsStringAsync().GetAwaiter().GetResult();

                if (resp.StatusCode == HttpStatusCode.Unauthorized)
                {
                    unauthorized = true;
                    return null;
                }

                if (!resp.IsSuccessStatusCode)
                {
                    return null;
                }

                using var doc = JsonDocument.Parse(body);
                if (doc.RootElement.TryGetProperty("commands", out var cmds) && cmds.GetArrayLength() > 0)
                {
                    return cmds.EnumerateArray().Select(c => c.Clone()).ToList();
                }

                return null;
            }
        }
        catch (System.Threading.Tasks.TaskCanceledException)
        {
            // Idle long-poll expiring is the normal path, not an error.
            return null;
        }
        catch (Exception ex)
        {
            Console.WriteLine($"[API] Error: {ex.GetType().Name}: {ex.Message}");
            return null;
        }
    }

    /// <summary>
    /// Re-registers this device and returns a freshly issued token, or null when
    /// the server refused (revoked/disabled device, or unreachable).
    /// </summary>
    /// <remarks>
    /// This is the self-heal half of the token contract: <c>/api/agent/register</c>
    /// only ever returns a non-empty token, and an empty string is treated as a
    /// failure here rather than being adopted as a credential. Adopting it is
    /// what used to leave the agent polling with a blank bearer forever.
    /// </remarks>
    public static string? Register(string url, string deviceId, string? name = null, string? platform = null)
    {
        try
        {
            var payload = new Dictionary<string, string> { ["device_id"] = deviceId };

            // The server replaces device_id with a readable number (MITO-0001) and
            // matches on hardware_id instead. An agent built before that still
            // works - the server falls back to matching device_id - but sending it
            // means this build is identified by an id it chose itself rather than
            // by the number it was handed back.
            payload["hardware_id"] = deviceId;

            var identity = CurrentBuildIdentity();

            if (!string.IsNullOrWhiteSpace(identity))
            {
                payload["agent_version"] = identity;
            }

            if (!string.IsNullOrWhiteSpace(name))
            {
                payload["name"] = name;
            }

            if (!string.IsNullOrWhiteSpace(platform))
            {
                payload["platform"] = platform;
            }

            using var req = new HttpRequestMessage(HttpMethod.Post, $"{url}/api/agent/register")
            {
                Content = new StringContent(
                    JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json")
            };

            using var resp = Http.Send(req);
            var body = resp.Content.ReadAsStringAsync().GetAwaiter().GetResult();

            if (!resp.IsSuccessStatusCode) return null;

            using var doc = JsonDocument.Parse(body);

            if (!doc.RootElement.TryGetProperty("token", out var token)
                || token.ValueKind != JsonValueKind.String)
            {
                return null;
            }

            var issued = token.GetString();

            return string.IsNullOrWhiteSpace(issued) ? null : issued;
        }
        catch
        {
            return null;
        }
    }

    /// <param name="status">done | failed</param>
    /// <param name="result">
    /// Optional detail stored on the command row (the Command Center shows it),
    /// e.g. why an uninstall could not complete. Sent as the `result` object the
    /// API validates as an array.
    /// </param>
    public static void Ack(
        string url,
        string token,
        long commandId,
        string status,
        Dictionary<string, string>? result = null)
    {
        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Post, $"{url}/api/agent/commands/{commandId}/ack");
            Authenticate(req, token);
            req.Content = new StringContent(
                JsonSerializer.Serialize(new { status, result }),
                Encoding.UTF8, "application/json");
            Http.Send(req);
        }
        catch { }
    }

    /// <summary>
    /// Report where a queued command has got to, so the fleet page shows a
    /// progress bar instead of a static "queued" badge.
    /// </summary>
    /// <remarks>
    /// Best-effort by design. A failed progress report must never change whether
    /// the command itself counts as delivered - the agent acks it afterwards, and
    /// throwing here would skip real work on someone's laptop over a lost
    /// progress ping. The stage text is owned by the agent: the server stores
    /// whatever it is given, so renaming a step here needs no server change.
    /// </remarks>
    public static void ReportProgress(
        string url,
        string token,
        long commandId,
        int percent,
        string? stage = null)
    {
        try
        {
            var body = new Dictionary<string, object?>
            {
                ["progress"] = Math.Clamp(percent, 0, 100),
                ["stage"] = stage,
            };

            using var req = new HttpRequestMessage(
                HttpMethod.Post, $"{url}/api/agent/commands/{commandId}/progress")
            {
                Content = new StringContent(
                    JsonSerializer.Serialize(body, JsonOptions), Encoding.UTF8, "application/json")
            };
            Authenticate(req, token);

            using var resp = Http.Send(req);

            if (!resp.IsSuccessStatusCode)
            {
                Console.Error.WriteLine($"[!] Progress rejected: HTTP {(int)resp.StatusCode}");
            }
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Progress report failed: {ex.Message}");
        }
    }

    /// <summary>
    /// Report install or update progress, which no queued command drives.
    /// </summary>
    /// <remarks>
    /// The installer runs before any daemon exists, so it reports under the token
    /// it has just registered. A terminal report is sent with status done or
    /// failed, which is what settles the row on the server.
    /// </remarks>
    public static void ReportInstallProgress(
        string url,
        string token,
        string command,
        string status,
        int percent,
        string? stage = null,
        Dictionary<string, string>? result = null)
    {
        try
        {
            var body = new Dictionary<string, object?>
            {
                ["command"] = command,
                ["status"] = status,
                ["progress"] = Math.Clamp(percent, 0, 100),
                ["stage"] = stage,
                ["result"] = result,
            };

            using var req = new HttpRequestMessage(HttpMethod.Post, $"{url}/api/agent/install-progress")
            {
                Content = new StringContent(
                    JsonSerializer.Serialize(body, JsonOptions), Encoding.UTF8, "application/json")
            };
            Authenticate(req, token);

            using var resp = Http.Send(req);

            if (!resp.IsSuccessStatusCode)
            {
                Console.Error.WriteLine($"[!] Install progress rejected: HTTP {(int)resp.StatusCode}");
            }
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine($"[!] Install progress report failed: {ex.Message}");
        }
    }
}
