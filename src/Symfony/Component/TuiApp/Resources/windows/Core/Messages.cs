using System.Text.Encodings.Web;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace TuiAppHost.Core;

/// <summary>A property that may be absent, which is not the same as null: `"progress": null` clears, no `progress` keeps.</summary>
public readonly record struct Opt<T>(bool IsSet, T Value)
{
    public static Opt<T> None => default;

    public static implicit operator Opt<T>(T value) => new(true, value);

    public T Or(T fallback) => IsSet ? Value : fallback;
}

/// <summary>The messages of protocol 1 (contract/protocol.json). Unknown properties are dropped; unknown types are kept as they came.</summary>
public abstract record Message
{
    public abstract string Type { get; }
}

public sealed record AuthMessage(string Token, int Protocol) : Message
{
    public override string Type => "auth";
}

public sealed record HelloMessage(int Protocol, string Os, int Columns, int Rows, double CellWidth, double CellHeight, Opt<double> Scale, string Appearance, Opt<IReadOnlyList<string>> Capabilities) : Message
{
    public override string Type => "hello";
}

public sealed record PatchMessage(IReadOnlyList<PatchOp> Ops) : Message
{
    public override string Type => "patch";
}

public abstract record PatchOp;

public sealed record CreateOp(int Id, string Kind, int? Parent, int Index, JsonObject Props) : PatchOp;

public sealed record UpdateOp(int Id, JsonObject Props) : PatchOp;

public sealed record MoveOp(int Id, int Parent, int Index) : PatchOp;

public sealed record RemoveOp(int Id) : PatchOp;

/// <summary>An op this host does not know: skipped.</summary>
public sealed record UnknownOp(JsonObject Raw) : PatchOp;

public sealed record FocusMessage(int? Node) : Message
{
    public override string Type => "focus";
}

public sealed record WindowProgress(string State, Opt<double?> Value);

public sealed record WindowMessage(Opt<string?> Title, Opt<WindowProgress?> Progress) : Message
{
    public override string Type => "window";
}

public sealed record NotifyMessage(string Title, Opt<string> Body) : Message
{
    public override string Type => "notify";
}

public sealed record BeepMessage : Message
{
    public override string Type => "beep";
}

public sealed record MenuItemDef(Opt<string> Id, Opt<string> Label, Opt<string?> Shortcut, Opt<bool> Separator, Opt<bool> Enabled, Opt<bool?> Checked)
{
    public bool IsSeparator => Separator.Or(false);

    public bool IsEnabled => Enabled.Or(true);
}

public sealed record MenuDef(string Title, IReadOnlyList<MenuItemDef> Items);

public sealed record MenuMessage(IReadOnlyList<MenuDef> Menus) : Message
{
    public override string Type => "menu";
}

public sealed record CloseReplyMessage(bool Accept) : Message
{
    public override string Type => "close-reply";
}

public sealed record ByeMessage(Opt<int> Status) : Message
{
    public override string Type => "bye";
}

public sealed record ResizeMessage(int Columns, int Rows) : Message
{
    public override string Type => "resize";
}

public sealed record EventMessage(int Node, string Name, Opt<JsonNode?> Value) : Message
{
    public override string Type => "event";
}

public sealed record KeyMessage(string Key, Opt<string?> Text) : Message
{
    public override string Type => "key";
}

/// <summary>
/// What the mouse does on a canvas's surface (host → application, with the pointer capability): the cell under it,
/// press, release, move, leave or wheel. Button is null for leave, wheel and a move with no button held; Rows and
/// Columns are sent with wheel only.
/// </summary>
public sealed record PointerMessage(int Node, string Action, int Row, int Column, string? Button, IReadOnlyList<string> Modifiers, Opt<int> Rows = default, Opt<int> Columns = default) : Message
{
    public override string Type => "pointer";
}

/// <summary>
/// The CSS cursor keyword to show over a canvas's surface (application → host, with the pointer capability), sent
/// when it changes; see <see cref="CursorShapes"/> for the pointer shown for it.
/// </summary>
public sealed record CursorMessage(int Node, string Shape) : Message
{
    public override string Type => "cursor";
}

/// <summary>An item of a <see cref="ContextMenuMessage"/>: a separator, or an id and a label, maybe disabled, maybe the paste role.</summary>
public sealed record ContextMenuItem(Opt<string> Id, Opt<string> Label, bool IsSeparator, Opt<bool> Enabled, Opt<string> Role)
{
    public bool IsEnabled => Enabled.Or(true);

    public bool IsPaste => Role.IsSet && Role.Value == "paste";
}

/// <summary>A menu to show at a cell of a canvas (application → host, with the contextmenu capability).</summary>
public sealed record ContextMenuMessage(int Node, int Row, int Column, IReadOnlyList<ContextMenuItem> Items) : Message
{
    public override string Type => "contextmenu";
}

/// <summary>The item of the last context menu the user chose (host → application).</summary>
public sealed record ContextMenuSelectMessage(string Id) : Message
{
    public override string Type => "contextmenu-select";
}

/// <summary>Text to put on the clipboard (application → host, with the clipboard capability).</summary>
public sealed record ClipboardMessage(string Text) : Message
{
    public override string Type => "clipboard";
}

public sealed record PasteMessage(string Text) : Message
{
    public override string Type => "paste";
}

public sealed record RectsMessage(IReadOnlyList<KeyValuePair<int, int[]>> Rects) : Message
{
    public override string Type => "rects";
}

public sealed record MenuSelectMessage(string Id) : Message
{
    public override string Type => "menu-select";
}

public sealed record AppearanceMessage(string Value) : Message
{
    public override string Type => "appearance";
}

public sealed record OpenMessage(IReadOnlyList<string> Paths) : Message
{
    public override string Type => "open";
}

public sealed record CloseMessage : Message
{
    public override string Type => "close";
}

public sealed record UnknownMessage(string UnknownType, JsonObject Raw) : Message
{
    public override string Type => UnknownType;
}

public static class MessageCodec
{
    private static readonly JsonSerializerOptions Options = new()
    {
        // Text as it is, not \u-escaped: "é" stays two UTF-8 bytes, as the frame fixtures expect.
        Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
    };

    public static Message Parse(ReadOnlySpan<byte> utf8) =>
        FromJson(JsonNode.Parse(utf8) as JsonObject ?? throw new MessageException("a message is a JSON object"));

    public static Message Parse(string json) =>
        FromJson(JsonNode.Parse(json) as JsonObject ?? throw new MessageException("a message is a JSON object"));

    public static string Serialize(Message message) => ToJson(message).ToJsonString(Options);

    public static byte[] Frame(Message message) => FrameCodec.Encode(Serialize(message));

    public static string Serialize(JsonNode node) => node.ToJsonString(Options);

    public static Message FromJson(JsonObject o)
    {
        var type = Str(o, "type");
        return type switch
        {
            "auth" => new AuthMessage(Str(o, "token"), Int(o, "protocol")),
            "hello" => new HelloMessage(
                Int(o, "protocol"), Str(o, "os"), Int(o, "columns"), Int(o, "rows"),
                Num(Obj(o, "cell"), "width"), Num(Obj(o, "cell"), "height"),
                o.ContainsKey("scale") ? Num(o, "scale") : Opt<double>.None,
                Str(o, "appearance"),
                o["capabilities"] is JsonArray capabilities ? new Opt<IReadOnlyList<string>>(true, capabilities.Select(c => c!.GetValue<string>()).ToList()) : default),
            "patch" => new PatchMessage(Arr(o, "ops").Select(op => ParseOp(op as JsonObject ?? throw new MessageException("an op is an object"))).ToList()),
            "focus" => new FocusMessage(NullableInt(o, "node")),
            "window" => new WindowMessage(
                o.ContainsKey("title") ? new Opt<string?>(true, o["title"] is null ? null : Str(o, "title")) : default,
                o.ContainsKey("progress")
                    ? new Opt<WindowProgress?>(true, o["progress"] is JsonObject p
                        ? new WindowProgress(Str(p, "state"), p.ContainsKey("value") ? new Opt<double?>(true, p["value"] is null ? null : Num(p, "value")) : default)
                        : null)
                    : default),
            "notify" => new NotifyMessage(Str(o, "title"), o.ContainsKey("body") ? Str(o, "body") : Opt<string>.None),
            "beep" => new BeepMessage(),
            "menu" => new MenuMessage(Arr(o, "menus").Select(m => ParseMenu((JsonObject)m!)).ToList()),
            "close-reply" => new CloseReplyMessage(Bool(o, "accept")),
            "bye" => new ByeMessage(o["status"] is null ? Opt<int>.None : Int(o, "status")),
            "resize" => new ResizeMessage(Int(o, "columns"), Int(o, "rows")),
            "event" => new EventMessage(Int(o, "node"), Str(o, "name"), o.ContainsKey("value") ? new Opt<JsonNode?>(true, o["value"]?.DeepClone()) : default),
            "key" => new KeyMessage(Str(o, "key"), o.ContainsKey("text") ? new Opt<string?>(true, o["text"]?.GetValue<string>()) : default),
            "pointer" => new PointerMessage(
                Int(o, "node"), Str(o, "action"), Int(o, "row"), Int(o, "column"),
                o["button"]?.GetValue<string>(), Arr(o, "modifiers").Select(m => m!.GetValue<string>()).ToList(),
                o.ContainsKey("rows") ? Int(o, "rows") : Opt<int>.None, o.ContainsKey("columns") ? Int(o, "columns") : Opt<int>.None),
            "cursor" => new CursorMessage(Int(o, "node"), Str(o, "shape")),
            "paste" => new PasteMessage(Str(o, "text")),
            "contextmenu" => new ContextMenuMessage(Int(o, "node"), Int(o, "row"), Int(o, "column"), Arr(o, "items").Select(i => ParseContextMenuItem(i as JsonObject ?? throw new MessageException("a context menu item is an object"))).ToList()),
            "contextmenu-select" => new ContextMenuSelectMessage(Str(o, "id")),
            "clipboard" => new ClipboardMessage(Str(o, "text")),
            "rects" => new RectsMessage(Obj(o, "rects").Select(entry => new KeyValuePair<int, int[]>(
                int.Parse(entry.Key, System.Globalization.CultureInfo.InvariantCulture),
                ((JsonArray)entry.Value!).Select(v => v!.GetValue<int>()).ToArray())).ToList()),
            "menu-select" => new MenuSelectMessage(Str(o, "id")),
            "appearance" => new AppearanceMessage(Str(o, "value")),
            "open" => new OpenMessage(Arr(o, "paths").Select(p => p!.GetValue<string>()).ToList()),
            "close" => new CloseMessage(),
            _ => new UnknownMessage(type, (JsonObject)o.DeepClone()),
        };
    }

    public static JsonObject ToJson(Message message)
    {
        var o = new JsonObject { ["type"] = message.Type };
        switch (message)
        {
            case AuthMessage m:
                o["token"] = m.Token;
                o["protocol"] = m.Protocol;
                break;
            case HelloMessage m:
                o["protocol"] = m.Protocol;
                o["os"] = m.Os;
                o["columns"] = m.Columns;
                o["rows"] = m.Rows;
                o["cell"] = new JsonObject { ["width"] = m.CellWidth, ["height"] = m.CellHeight };
                if (m.Scale.IsSet)
                {
                    o["scale"] = m.Scale.Value;
                }

                o["appearance"] = m.Appearance;
                if (m.Capabilities.IsSet)
                {
                    o["capabilities"] = new JsonArray(m.Capabilities.Value.Select(c => (JsonNode?)c).ToArray());
                }

                break;
            case PatchMessage m:
                o["ops"] = new JsonArray(m.Ops.Select(op => (JsonNode?)OpToJson(op)).ToArray());
                break;
            case FocusMessage m:
                o["node"] = m.Node;
                break;
            case WindowMessage m:
                if (m.Title.IsSet)
                {
                    o["title"] = m.Title.Value;
                }

                if (m.Progress.IsSet)
                {
                    if (m.Progress.Value is { } progress)
                    {
                        var p = new JsonObject { ["state"] = progress.State };
                        if (progress.Value.IsSet)
                        {
                            p["value"] = progress.Value.Value;
                        }

                        o["progress"] = p;
                    }
                    else
                    {
                        o["progress"] = null;
                    }
                }

                break;
            case NotifyMessage m:
                o["title"] = m.Title;
                if (m.Body.IsSet)
                {
                    o["body"] = m.Body.Value;
                }

                break;
            case MenuMessage m:
                o["menus"] = new JsonArray(m.Menus.Select(menu => (JsonNode?)new JsonObject
                {
                    ["title"] = menu.Title,
                    ["items"] = new JsonArray(menu.Items.Select(item => (JsonNode?)MenuItemToJson(item)).ToArray()),
                }).ToArray());
                break;
            case CloseReplyMessage m:
                o["accept"] = m.Accept;
                break;
            case ByeMessage m:
                if (m.Status.IsSet)
                {
                    o["status"] = m.Status.Value;
                }

                break;
            case ResizeMessage m:
                o["columns"] = m.Columns;
                o["rows"] = m.Rows;
                break;
            case EventMessage m:
                o["node"] = m.Node;
                o["name"] = m.Name;
                if (m.Value.IsSet)
                {
                    o["value"] = m.Value.Value?.DeepClone();
                }

                break;
            case KeyMessage m:
                o["key"] = m.Key;
                if (m.Text.IsSet)
                {
                    o["text"] = m.Text.Value;
                }

                break;
            case PointerMessage m:
                o["node"] = m.Node;
                o["action"] = m.Action;
                o["row"] = m.Row;
                o["column"] = m.Column;
                // Always sent, null when no button
                o["button"] = m.Button;
                o["modifiers"] = new JsonArray(m.Modifiers.Select(x => (JsonNode?)x).ToArray());
                if (m.Rows.IsSet)
                {
                    o["rows"] = m.Rows.Value;
                }

                if (m.Columns.IsSet)
                {
                    o["columns"] = m.Columns.Value;
                }

                break;
            case CursorMessage m:
                o["node"] = m.Node;
                o["shape"] = m.Shape;
                break;
            case PasteMessage m:
                o["text"] = m.Text;
                break;
            case ContextMenuMessage m:
                o["node"] = m.Node;
                o["row"] = m.Row;
                o["column"] = m.Column;
                o["items"] = new JsonArray(m.Items.Select(item => (JsonNode?)ContextMenuItemToJson(item)).ToArray());
                break;
            case ContextMenuSelectMessage m:
                o["id"] = m.Id;
                break;
            case ClipboardMessage m:
                o["text"] = m.Text;
                break;
            case RectsMessage m:
                var rects = new JsonObject();
                foreach (var (id, rect) in m.Rects)
                {
                    rects[id.ToString(System.Globalization.CultureInfo.InvariantCulture)] = new JsonArray(rect.Select(v => (JsonNode?)v).ToArray());
                }

                o["rects"] = rects;
                break;
            case MenuSelectMessage m:
                o["id"] = m.Id;
                break;
            case AppearanceMessage m:
                o["value"] = m.Value;
                break;
            case OpenMessage m:
                o["paths"] = new JsonArray(m.Paths.Select(p => (JsonNode?)p).ToArray());
                break;
            case UnknownMessage m:
                return (JsonObject)m.Raw.DeepClone();
        }

        return o;
    }

    private static ContextMenuItem ParseContextMenuItem(JsonObject o) =>
        o["separator"] is JsonValue separator && separator.TryGetValue<bool>(out var isSeparator) && isSeparator
            ? new ContextMenuItem(default, default, true, default, default)
            : new ContextMenuItem(
                Str(o, "id"), Str(o, "label"), false,
                o.ContainsKey("enabled") ? Bool(o, "enabled") : Opt<bool>.None,
                o.ContainsKey("role") ? Str(o, "role") : Opt<string>.None);

    private static JsonObject ContextMenuItemToJson(ContextMenuItem item)
    {
        if (item.IsSeparator)
        {
            return new JsonObject { ["separator"] = true };
        }

        var o = new JsonObject { ["id"] = item.Id.Value, ["label"] = item.Label.Value };
        if (item.Enabled.IsSet)
        {
            o["enabled"] = item.Enabled.Value;
        }

        if (item.Role.IsSet)
        {
            o["role"] = item.Role.Value;
        }

        return o;
    }

    private static PatchOp ParseOp(JsonObject o) => o["op"]?.GetValue<string>() switch
    {
        "create" => new CreateOp(Int(o, "id"), Str(o, "kind"), NullableInt(o, "parent"), Int(o, "index"), Props(o)),
        "update" => new UpdateOp(Int(o, "id"), Props(o)),
        "move" => new MoveOp(Int(o, "id"), Int(o, "parent"), Int(o, "index")),
        "remove" => new RemoveOp(Int(o, "id")),
        _ => new UnknownOp((JsonObject)o.DeepClone()),
    };

    private static JsonObject OpToJson(PatchOp op) => op switch
    {
        CreateOp c => new JsonObject { ["op"] = "create", ["id"] = c.Id, ["kind"] = c.Kind, ["parent"] = c.Parent, ["index"] = c.Index, ["props"] = c.Props.DeepClone() },
        UpdateOp u => new JsonObject { ["op"] = "update", ["id"] = u.Id, ["props"] = u.Props.DeepClone() },
        MoveOp m => new JsonObject { ["op"] = "move", ["id"] = m.Id, ["parent"] = m.Parent, ["index"] = m.Index },
        RemoveOp r => new JsonObject { ["op"] = "remove", ["id"] = r.Id },
        UnknownOp u => (JsonObject)u.Raw.DeepClone(),
        _ => throw new MessageException("unknown op"),
    };

    private static MenuDef ParseMenu(JsonObject o) => new(
        Str(o, "title"),
        Arr(o, "items").Select(i =>
        {
            var item = (JsonObject)i!;
            return new MenuItemDef(
                item.ContainsKey("id") ? Str(item, "id") : Opt<string>.None,
                item.ContainsKey("label") ? Str(item, "label") : Opt<string>.None,
                item.ContainsKey("shortcut") ? new Opt<string?>(true, item["shortcut"]?.GetValue<string>()) : default,
                item.ContainsKey("separator") ? Bool(item, "separator") : Opt<bool>.None,
                item.ContainsKey("enabled") ? Bool(item, "enabled") : Opt<bool>.None,
                item.ContainsKey("checked") ? new Opt<bool?>(true, item["checked"]?.GetValue<bool>()) : default);
        }).ToList());

    private static JsonObject MenuItemToJson(MenuItemDef item)
    {
        var o = new JsonObject();
        if (item.Id.IsSet)
        {
            o["id"] = item.Id.Value;
        }

        if (item.Label.IsSet)
        {
            o["label"] = item.Label.Value;
        }

        if (item.Shortcut.IsSet)
        {
            o["shortcut"] = item.Shortcut.Value;
        }

        if (item.Separator.IsSet)
        {
            o["separator"] = item.Separator.Value;
        }

        if (item.Enabled.IsSet)
        {
            o["enabled"] = item.Enabled.Value;
        }

        if (item.Checked.IsSet)
        {
            o["checked"] = item.Checked.Value;
        }

        return o;
    }

    private static JsonObject Props(JsonObject o) => o["props"] is JsonObject props ? (JsonObject)props.DeepClone() : new JsonObject();

    private static string Str(JsonObject o, string name) =>
        o[name] is JsonValue v && v.TryGetValue<string>(out var s) ? s : throw new MessageException($"{name} must be a string");

    private static int Int(JsonObject o, string name) =>
        o[name] is JsonValue v && v.TryGetValue<int>(out var i) ? i : throw new MessageException($"{name} must be an integer");

    private static int? NullableInt(JsonObject o, string name) => o[name] is null ? null : Int(o, name);

    private static double Num(JsonObject o, string name) =>
        o[name] is JsonValue v && v.TryGetValue<double>(out var d) ? d : throw new MessageException($"{name} must be a number");

    private static bool Bool(JsonObject o, string name) =>
        o[name] is JsonValue v && v.TryGetValue<bool>(out var b) ? b : throw new MessageException($"{name} must be a boolean");

    private static JsonObject Obj(JsonObject o, string name) => o[name] as JsonObject ?? throw new MessageException($"{name} must be an object");

    private static JsonArray Arr(JsonObject o, string name) => o[name] as JsonArray ?? throw new MessageException($"{name} must be an array");
}

public sealed class MessageException(string message) : Exception(message);
