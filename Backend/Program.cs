using System.Text.Json;
using Microsoft.AspNetCore.SignalR;

var builder = WebApplication.CreateBuilder(args);

builder.Services.AddHttpClient("ml", c => {
    c.BaseAddress = new Uri("http://127.0.0.1:5000");
    c.Timeout = TimeSpan.FromSeconds(10);
});

builder.Services.AddSignalR(o => {
    o.EnableDetailedErrors = true;
    o.KeepAliveInterval = TimeSpan.FromSeconds(15);
    o.ClientTimeoutInterval = TimeSpan.FromSeconds(90);
    o.HandshakeTimeout = TimeSpan.FromSeconds(30);
});

var corsOrigins = builder.Configuration.GetSection("Cors:Origins").Get<string[]>()
    ?? new[] { "http://localhost", "http://127.0.0.1" };

builder.Services.AddCors(o => o.AddPolicy("ApexCors", p =>
    p.SetIsOriginAllowed(origin => {
            if (string.IsNullOrWhiteSpace(origin)) return false;
            var u = new Uri(origin);
            return u.Host is "localhost" or "127.0.0.1"
                || u.Host.EndsWith(".localhost", StringComparison.OrdinalIgnoreCase);
        })
     .AllowAnyHeader()
     .AllowAnyMethod()
     .AllowCredentials()));

const string ApiKey = "apex-singular-key-2025";

var app = builder.Build();
app.UseCors("ApexCors");
app.UseWebSockets();
app.MapHub<ApexHub>("/hub");

bool Authorized(HttpContext ctx) =>
    ctx.Request.Headers["X-Api-Key"].FirstOrDefault() == ApiKey;

app.MapGet("/health", () => Results.Ok(new {
    status = "ok",
    version = "6.0",
    role = "realtime-hub",
    hub = "/hub",
    time = DateTime.UtcNow,
}));

/// <summary>PHP → SignalR bridge. Single entry for all realtime events.</summary>
app.MapPost("/api/realtime/push", async (HttpContext ctx, IHubContext<ApexHub> hub) => {
    if (!Authorized(ctx))
        return Results.Json(new { error = "Unauthorized" }, statusCode: 401);

    using var doc = await JsonDocument.ParseAsync(ctx.Request.Body);
    var root = doc.RootElement;
    var ev = root.TryGetProperty("event", out var ep) ? ep.GetString() ?? "" : "";
    if (string.IsNullOrWhiteSpace(ev))
        return Results.BadRequest(new { error = "event required" });

    int? userId = root.TryGetProperty("userId", out var up) && up.ValueKind == JsonValueKind.Number
        ? up.GetInt32() : null;
    bool toAdmins = root.TryGetProperty("toAdmins", out var ap) && ap.GetBoolean();
    JsonElement payload = root.TryGetProperty("payload", out var pp) ? pp : default;

    object data = payload.ValueKind == JsonValueKind.Undefined
        ? new { }
        : JsonSerializer.Deserialize<object>(payload.GetRawText()) ?? new { };

    if (userId is > 0)
        await hub.Clients.Group($"user_{userId}").SendAsync(ev, data);

    if (toAdmins)
        await hub.Clients.Group("admins").SendAsync(ev, data);

    return Results.Ok(new { sent = ev, userId, toAdmins });
});

app.MapPost("/api/moderate/preview", async (HttpContext ctx, IHttpClientFactory hf) => {
    if (!Authorized(ctx))
        return Results.Json(new { error = "Unauthorized" }, statusCode: 401);

    using var doc = await JsonDocument.ParseAsync(ctx.Request.Body);
    var text = doc.RootElement.TryGetProperty("text", out var tp)
        ? tp.GetString()?.Trim() ?? "" : "";
    if (text.Length < 2)
        return Results.Ok(new { verdict = "ALLOWED", harmful_prob = 0, category = "safe" });

    var (lbl, prob, cat, method, reason, offline) =
        await MlClient.AnalyzeAsync(hf, text, "preview");
    var verdict = offline ? "OFFLINE"
        : lbl == 1 ? (prob >= 52 && prob < 78 ? "REVIEW" : "FORBIDDEN")
        : "ALLOWED";
    return Results.Ok(new { verdict, harmful_prob = prob, category = cat, method, reason, offline });
});

Console.WriteLine("[STARTUP] ApexSocial Realtime Hub :8080");
app.Run("http://0.0.0.0:8080");

public static class MlClient
{
    public static async Task<(int label, float prob, string cat, string method, string reason, bool offline)>
        AnalyzeAsync(IHttpClientFactory hf, string text, string type)
{
    try {
            var client = hf.CreateClient("ml");
            var body = JsonSerializer.Serialize(new { text, type });
            var res = await client.PostAsync("/analyze",
                new StringContent(body, System.Text.Encoding.UTF8, "application/json"));
            if (!res.IsSuccessStatusCode)
                return (0, 0f, "offline", "offline", "", true);

            using var doc = JsonDocument.Parse(await res.Content.ReadAsStringAsync());
            var root = doc.RootElement;
            var verdict = root.TryGetProperty("verdict", out var vp) ? vp.GetString() ?? "" : "";
            int lbl = verdict is "FORBIDDEN" or "REVIEW" ? 1 : 0;
            float prob = root.TryGetProperty("harmful_prob", out var pp) ? (float)pp.GetDouble() : 0f;
            string cat = root.TryGetProperty("category", out var cp) ? cp.GetString()! : "safe";
            string mth = root.TryGetProperty("method", out var mp) ? mp.GetString()! : "sklearn";
            string rsn = root.TryGetProperty("reason", out var rp) ? rp.GetString()! : "";
            return (lbl, prob, cat, mth, rsn, false);
        } catch {
            return (0, 0f, "offline", "offline", "", true);
        }
    }
}

public class ApexHub : Hub
{
    public async Task Join(int userId, bool isAdmin)
    {
        if (userId <= 0) throw new HubException("Invalid userId");
        await Groups.AddToGroupAsync(Context.ConnectionId, $"user_{userId}");
        if (isAdmin)
            await Groups.AddToGroupAsync(Context.ConnectionId, "admins");
        await Clients.Caller.SendAsync("Joined", new { userId, isAdmin, at = DateTime.UtcNow });
    }

    public Task Ping() => Clients.Caller.SendAsync("Pong", DateTime.UtcNow);

    public async Task PreviewModeration(string text)
    {
        if (string.IsNullOrWhiteSpace(text) || text.Length < 2) {
            await Clients.Caller.SendAsync("LiveModeration", new {
                verdict = "ALLOWED", harmful_prob = 0, category = "safe", method = "trivial"
            });
            return;
        }
        if (text.Length > 8000)
            throw new HubException("Text too long");

        var hf = Context.GetHttpContext()!.RequestServices.GetRequiredService<IHttpClientFactory>();
        var (lbl, prob, cat, method, reason, offline) =
            await MlClient.AnalyzeAsync(hf, text.Trim(), "preview");
        var verdict = offline ? "OFFLINE"
            : lbl == 1 ? (prob >= 52 && prob < 78 ? "REVIEW" : "FORBIDDEN")
            : "ALLOWED";
        await Clients.Caller.SendAsync("LiveModeration", new {
            verdict, harmful_prob = prob, category = cat, method, reason, offline
        });
    }
}
