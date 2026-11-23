using System.Text.Json;
using System.Text.Json.Nodes;

namespace TuiAppHost.Core;

public sealed class Node(int id, string kind)
{
    public int Id { get; } = id;

    public string Kind { get; internal set; } = kind;

    /// <summary>Only the props that are set: a prop updated to null is gone.</summary>
    public JsonObject Props { get; internal set; } = new();

    public Node? Parent { get; internal set; }

    public List<Node> Children { get; } = new();

    public JsonNode? Prop(string name) => Props.TryGetPropertyValue(name, out var value) ? value : null;

    public string? String(string name) => Prop(name) is JsonValue v && v.TryGetValue<string>(out var s) ? s : null;

    public bool? Bool(string name) => Prop(name) is JsonValue v && v.TryGetValue<bool>(out var b) ? b : null;

    public int? Int(string name) => Prop(name) is JsonValue v && v.TryGetValue<int>(out var i) ? i : null;

    public double? Number(string name) => Prop(name) is JsonValue v && v.TryGetValue<double>(out var d) ? d : null;
}

/// <summary>What a patch changed, for the reconciler: build, update, re-parent, drop.</summary>
public sealed class PatchResult
{
    public HashSet<int> Created { get; } = new();

    public HashSet<int> Updated { get; } = new();

    public HashSet<int> Removed { get; } = new();

    /// <summary>Nodes whose list of children changed.</summary>
    public HashSet<int> ChildrenChanged { get; } = new();

    public bool RootChanged { get; internal set; }

    public List<string> Warnings { get; } = new();
}

/// <summary>The application's widget tree, as the patches describe it.</summary>
public sealed class NodeTree
{
    private readonly Dictionary<int, Node> nodes = new();

    public Node? Root { get; private set; }

    public IReadOnlyDictionary<int, Node> Nodes => nodes;

    public Node? Find(int id) => nodes.TryGetValue(id, out var node) ? node : null;

    public PatchResult Apply(IEnumerable<PatchOp> ops)
    {
        var result = new PatchResult();
        foreach (var op in ops)
        {
            switch (op)
            {
                case CreateOp create:
                    Create(create, result);
                    break;
                case UpdateOp update:
                    Update(update, result);
                    break;
                case MoveOp move:
                    Move(move, result);
                    break;
                case RemoveOp remove:
                    if (nodes.TryGetValue(remove.Id, out var removed))
                    {
                        Remove(removed, result);
                    }
                    else
                    {
                        result.Warnings.Add($"remove: no node {remove.Id}");
                    }

                    break;
            }
        }

        result.Created.ExceptWith(result.Removed);
        result.Updated.ExceptWith(result.Removed);
        result.Updated.ExceptWith(result.Created);
        result.ChildrenChanged.ExceptWith(result.Removed);
        return result;
    }

    private void Create(CreateOp op, PatchResult result)
    {
        Node? parent = null;
        if (op.Parent is { } parentId && !nodes.TryGetValue(parentId, out parent))
        {
            result.Warnings.Add($"create {op.Id}: no parent {parentId}");
            return;
        }

        if (nodes.TryGetValue(op.Id, out var existing))
        {
            Remove(existing, result);
        }

        var node = new Node(op.Id, op.Kind);
        foreach (var (name, value) in op.Props)
        {
            if (value is not null)
            {
                node.Props[name] = value.DeepClone();
            }
        }

        nodes[op.Id] = node;
        result.Removed.Remove(op.Id);
        result.Created.Add(op.Id);
        if (parent is null)
        {
            if (Root is not null)
            {
                Remove(Root, result);
            }

            Root = node;
            result.RootChanged = true;
        }
        else
        {
            Insert(node, parent, op.Index, result);
        }
    }

    private void Update(UpdateOp op, PatchResult result)
    {
        if (!nodes.TryGetValue(op.Id, out var node))
        {
            result.Warnings.Add($"update: no node {op.Id}");
            return;
        }

        foreach (var (name, value) in op.Props)
        {
            if (value is null)
            {
                node.Props.Remove(name);
            }
            else
            {
                node.Props[name] = value.DeepClone();
            }
        }

        result.Updated.Add(op.Id);
    }

    private void Move(MoveOp op, PatchResult result)
    {
        if (!nodes.TryGetValue(op.Id, out var node) || !nodes.TryGetValue(op.Parent, out var parent))
        {
            result.Warnings.Add($"move {op.Id}: no such node or parent {op.Parent}");
            return;
        }

        for (var ancestor = parent; ancestor is not null; ancestor = ancestor.Parent)
        {
            if (ancestor == node)
            {
                result.Warnings.Add($"move {op.Id}: into its own subtree");
                return;
            }
        }

        Detach(node, result);
        Insert(node, parent, op.Index, result);
    }

    private void Remove(Node node, PatchResult result)
    {
        Detach(node, result);
        if (Root == node)
        {
            Root = null;
            result.RootChanged = true;
        }

        Forget(node, result);
    }

    private void Forget(Node node, PatchResult result)
    {
        foreach (var child in node.Children)
        {
            Forget(child, result);
        }

        nodes.Remove(node.Id);
        result.Removed.Add(node.Id);
    }

    private static void Detach(Node node, PatchResult result)
    {
        if (node.Parent is { } parent)
        {
            parent.Children.Remove(node);
            result.ChildrenChanged.Add(parent.Id);
            node.Parent = null;
        }
    }

    private static void Insert(Node node, Node parent, int index, PatchResult result)
    {
        parent.Children.Insert(Math.Clamp(index, 0, parent.Children.Count), node);
        node.Parent = parent;
        result.ChildrenChanged.Add(parent.Id);
    }

    /// <summary>
    /// The prop a user event changes on a control of a kind, as the application takes it: it does not send it
    /// back (TuiApp's HostEvent::acknowledge), so an update of its other props must not put the old one back.
    /// change and submit set the value of a text field, a password, a text area or a number field, change the
    /// value of a slider, toggle the checked of a check box and the expanded of a disclosure, select the
    /// selected of a pop-up, a list box or a list (the checked indexes of a multiple list), tab the active tab.
    /// </summary>
    public static string? Acknowledged(string kind, string name, JsonNode? value)
    {
        var type = value switch
        {
            JsonArray => JsonValueKind.Array,
            JsonValue v => v.GetValueKind(),
            _ => JsonValueKind.Undefined,
        };
        return (kind, name, type) switch
        {
            ("textfield" or "password" or "textarea" or "number", "change" or "submit", JsonValueKind.String) => "value",
            ("slider", "change", JsonValueKind.Number) => "value",
            ("checkbox", "toggle", JsonValueKind.True or JsonValueKind.False) => "checked",
            ("disclosure", "toggle", JsonValueKind.True or JsonValueKind.False) => "expanded",
            ("popup" or "list", "select", JsonValueKind.Number) or ("listbox", "select", JsonValueKind.Array) => "selected",
            ("list", "select", JsonValueKind.Array) => "checked",
            ("tabs", "tab", JsonValueKind.Number) => "active",
            _ => null,
        };
    }

    /// <summary>Records what a user event changed on a node (see Acknowledged); true when a prop changed.</summary>
    public bool Acknowledge(int id, string name, JsonNode? value)
    {
        if (!nodes.TryGetValue(id, out var node) || Acknowledged(node.Kind, name, value) is not { } prop || JsonNode.DeepEquals(node.Prop(prop), value))
        {
            return false;
        }

        // As a patch gives it, parsed: read back like the other props
        node.Props[prop] = JsonNode.Parse(value!.ToJsonString());
        return true;
    }

    /// <summary>As session.json writes trees: {id, kind, props (sorted, nulls dropped), children}.</summary>
    public static JsonObject Serialize(Node node)
    {
        var props = new JsonObject();
        foreach (var (name, value) in node.Props.OrderBy(p => p.Key, StringComparer.Ordinal))
        {
            if (value is not null)
            {
                props[name] = value.DeepClone();
            }
        }

        return new JsonObject
        {
            ["id"] = node.Id,
            ["kind"] = node.Kind,
            ["props"] = props,
            ["children"] = new JsonArray(node.Children.Select(child => (JsonNode?)Serialize(child)).ToArray()),
        };
    }

    public IEnumerable<Node> Walk() => Root is null ? Enumerable.Empty<Node>() : Walk(Root);

    private static IEnumerable<Node> Walk(Node node)
    {
        yield return node;
        foreach (var child in node.Children)
        {
            foreach (var descendant in Walk(child))
            {
                yield return descendant;
            }
        }
    }
}
