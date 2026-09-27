using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

namespace TrackerAgent;

public static class Api
{
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(60) };

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

    public static List<JsonElement>? GetInbox(string url, string token)
    {
        using var req = new HttpRequestMessage(HttpMethod.Get, $"{url}/api/agent/inbox");
        req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);

        using var resp = Http.Send(req);
        var body = resp.Content.ReadAsStringAsync().GetAwaiter().GetResult();

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
