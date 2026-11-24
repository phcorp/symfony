using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace TuiAppHost.Core;

/// <summary>app.manifest.json (contract/manifest.schema.json), next to the .exe on Windows.</summary>
public sealed record Manifest(
    AppInfo App,
    WindowSettings Window,
    MenuSettings Menu,
    LaunchSettings Launch,
    LoggingSettings Logging)
{
    public const string FileName = "app.manifest.json";

    public static Manifest Parse(string json)
    {
        var root = JsonNode.Parse(json) as JsonObject ?? throw new ManifestException("the manifest is not a JSON object");
        if (root["manifest"] is not JsonValue version || !version.TryGetValue<int>(out var v) || v != 1)
        {
            throw new ManifestException("manifest must be 1");
        }

        var app = Object(root, "app", required: true)!;
        var id = String(app, "id")!;
        if (!Regex.IsMatch(id, @"^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$"))
        {
            throw new ManifestException($"app.id {id} is not a reverse domain name");
        }

        var window = Object(root, "window", required: true)!;
        var font = Object(window, "canvasFont", required: false);
        var menu = Object(root, "menu", required: true)!;
        var launch = Object(root, "launch", required: true)!;
        var php = launch["php"] as JsonObject;
        var env = Object(launch, "env", required: false);
        var logging = Object(root, "logging", required: false);
        var mode = String(launch, "mode")!;
        if (mode is not ("embedded" or "custom" or "system" or "elephc"))
        {
            throw new ManifestException($"launch.mode {mode} is not embedded, custom, system or elephc");
        }

        return new Manifest(
            new AppInfo(
                String(app, "name")!, id, String(app, "version")!,
                String(app, "description", required: false), String(app, "publisher", required: false),
                String(app, "copyright", required: false), String(app, "homepage", required: false),
                Bool(app, "singleInstance") ?? false,
                String(app, "category", required: false),
                (app["fileAssociations"] as JsonArray)?.OfType<JsonObject>().Select(a => new FileAssociation(
                    String(a, "extension")!.TrimStart('.'), String(a, "name")!, String(a, "mimeType", required: false), String(a, "role", required: false) ?? "editor")).ToList()
                    ?? new List<FileAssociation>(),
                (app["urlSchemes"] as JsonArray)?.Select(s => s!.GetValue<string>()).ToList() ?? new List<string>()),
            new WindowSettings(
                String(window, "title")!, Int(window, "width") ?? throw new ManifestException("window.width is required"),
                Int(window, "height") ?? throw new ManifestException("window.height is required"),
                Int(window, "minWidth"), Int(window, "minHeight"),
                Bool(window, "resizable") ?? true, Bool(window, "rememberGeometry") ?? false,
                String(window, "theme", required: false) ?? "system",
                font is null ? null : String(font, "family", required: false),
                font is not null && font["size"] is JsonValue size && size.TryGetValue<double>(out var points) ? points : WindowSettings.DefaultFontSize),
            new MenuSettings(Bool(menu, "native") ?? true, Bool(menu, "about") ?? true, Bool(menu, "quit") ?? true),
            new LaunchSettings(
                mode, String(launch, "command", required: false),
                (launch["args"] as JsonArray ?? throw new ManifestException("launch.args is required")).Select(a => a!.GetValue<string>()).ToList(),
                php is null ? null : String(php, "path", required: false),
                php is null ? null : String(php, "minVersion", required: false),
                (env?["pass"] as JsonArray)?.Select(a => a!.GetValue<string>()).ToList() ?? new List<string>(),
                (env?["set"] as JsonObject)?.ToDictionary(e => e.Key, e => e.Value!.GetValue<string>()) ?? new Dictionary<string, string>()),
            new LoggingSettings(logging is null ? null : String(logging, "file", required: false), logging is null ? "warning" : String(logging, "level", required: false) ?? "warning"));
    }

    /// <summary>What `--dev -- command…` runs with: no manifest, the defaults of the schema.</summary>
    public static Manifest Development(string command, IReadOnlyList<string> args) => new(
        new AppInfo("TuiApp", "dev.tuiapp.host", "0.0.0-dev", null, null, null, null, false, null, new List<FileAssociation>(), new List<string>()),
        new WindowSettings("TuiApp", 900, 760, 320, 240, true, false, "system", null, WindowSettings.DefaultFontSize),
        new MenuSettings(true, true, true),
        new LaunchSettings("custom", command, args, null, null, new List<string>(), new Dictionary<string, string>()),
        new LoggingSettings(null, "debug"));

    private static JsonObject? Object(JsonObject o, string name, bool required) =>
        o[name] as JsonObject ?? (required ? throw new ManifestException($"{name} is required") : null);

    private static string? String(JsonObject o, string name, bool required = true)
    {
        if (o[name] is JsonValue v && v.TryGetValue<string>(out var s))
        {
            return s;
        }

        return required ? throw new ManifestException($"{name} must be a string") : null;
    }

    private static int? Int(JsonObject o, string name) => o[name] is JsonValue v && v.TryGetValue<int>(out var i) ? i : null;

    private static bool? Bool(JsonObject o, string name) => o[name] is JsonValue v && v.TryGetValue<bool>(out var b) ? b : null;
}

/// <param name="Category">macOS and Linux only: Windows has no equivalent.</param>
public sealed record AppInfo(
    string Name, string Id, string Version, string? Description, string? Publisher, string? Copyright, string? Homepage, bool SingleInstance,
    string? Category, IReadOnlyList<FileAssociation> FileAssociations, IReadOnlyList<string> UrlSchemes);

/// <summary>A file type the application opens; on Windows a ProgID and an extension key, written by the installer.</summary>
public sealed record FileAssociation(string Extension, string Name, string? MimeType, string Role);

public sealed record WindowSettings(string Title, int Width, int Height, int? MinWidth, int? MinHeight, bool Resizable, bool RememberGeometry, string Theme, string? FontFamily, double FontSize)
{
    public const double DefaultFontSize = 13;
}

public sealed record MenuSettings(bool Native, bool About, bool Quit);

public sealed record LaunchSettings(string Mode, string? Command, IReadOnlyList<string> Args, string? PhpPath, string? PhpMinVersion, IReadOnlyList<string> Pass, IReadOnlyDictionary<string, string> Set);

public sealed record LoggingSettings(string? File, string Level);

public sealed class ManifestException(string message) : Exception(message);
