using System;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

// .NET 8+ console sample
//   dotnet new console -n JarvisClientSample
//   replace Program.cs with this file

class Program
{
    static async Task Main()
    {
        var baseUrl = Environment.GetEnvironmentVariable("JARVIS_BASE_URL") ?? "http://localhost:8000";
        var username = Environment.GetEnvironmentVariable("JARVIS_USER") ?? "nick";
        var password = Environment.GetEnvironmentVariable("JARVIS_PASS") ?? "password";

        using var http = new HttpClient { BaseAddress = new Uri(baseUrl) };
        var token = await Login(http, username, password);
        http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", token);

        var briefing = await PostJson(http, "/api/command", new { text = "briefing" });
        Console.WriteLine("Briefing:\n" + briefing);

        var send = await PostJson(http, "/api/messages", new { message = "Hello from .NET", channel = "" });
        Console.WriteLine("Slack send:\n" + send);
    }

    static async Task<string> Login(HttpClient http, string user, string pass)
    {
        var json = await PostJson(http, "/api/auth/login", new { username = user, password = pass });
        using var doc = JsonDocument.Parse(json);
        if (!doc.RootElement.TryGetProperty("token", out var t))
            throw new Exception("Login failed: " + json);
        return t.GetString() ?? throw new Exception("No token");
    }

    static async Task<string> PostJson(HttpClient http, string path, object body)
    {
        var payload = JsonSerializer.Serialize(body);
        using var content = new StringContent(payload, Encoding.UTF8, "application/json");
        var resp = await http.PostAsync(path, content);
        var text = await resp.Content.ReadAsStringAsync();
        return text;
    }
}
