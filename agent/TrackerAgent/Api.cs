using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

namespace TrackerAgent;

public static class Api
{
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(60) };

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
        req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);

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
            req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);

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
            req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);

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

    /// <summary>One entry of the installed-software list, as the server sees it.</summary>
    private sealed record SoftwareEntry(string? Name, string? Version, string? Publisher, string? InstalledAt);

    public static List<JsonElement>? GetInbox(string url, string token) =>
        GetInbox(url, token, out _);

    /// <param name="unauthorized">
    /// True when the server actively rejected the credential. The daemon needs
    /// this to tell "token is dead" apart from "network blip": the first is
    /// recoverable by re-registering, the second must simply be retried.
    /// </param>
    public static List<JsonElement>? GetInbox(string url, string token, out bool unauthorized)
    {
        unauthorized = false;

        using var req = new HttpRequestMessage(HttpMethod.Get, $"{url}/api/agent/inbox");
        req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);

        using var resp = Http.Send(req);
        var body = resp.Content.ReadAsStringAsync().GetAwaiter().GetResult();

        if (resp.StatusCode == HttpStatusCode.Unauthorized)
        {
            unauthorized = true;
            return null;
        }

        if (!resp.IsSuccessStatusCode) return null;

        using var doc = JsonDocument.Parse(body);
        if (doc.RootElement.TryGetProperty("commands", out var cmds) && cmds.GetArrayLength() > 0)
        {
            // Clone: the JsonDocument is disposed when this method returns, so the
            // elements must own their own copy of the backing data.
            return cmds.EnumerateArray().Select(c => c.Clone()).ToList();
        }

        return null;
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
            req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            req.Content = new StringContent(
                JsonSerializer.Serialize(new { status, result }),
                Encoding.UTF8, "application/json");
            Http.Send(req);
        }
        catch { }
    }
}
