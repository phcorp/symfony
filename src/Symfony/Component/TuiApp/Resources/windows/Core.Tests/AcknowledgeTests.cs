using System.Text.Json.Nodes;
using Xunit;

namespace TuiAppHost.Core.Tests;

/// <summary>What the user changes on a control is its prop from then on, as the application takes it.</summary>
public class AcknowledgeTests
{
    private static NodeTree Tree()
    {
        var tree = new NodeTree();
        // As a patch gives them: parsed
        tree.Apply(((PatchMessage)MessageCodec.Parse("""
            {"type": "patch", "ops": [
                {"op": "create", "id": 1, "kind": "stack", "parent": null, "index": 0, "props": {}},
                {"op": "create", "id": 2, "kind": "textfield", "parent": 1, "index": 0, "props": {"value": "Ada", "maxLength": 40}},
                {"op": "create", "id": 3, "kind": "checkbox", "parent": 1, "index": 1, "props": {"checked": false}},
                {"op": "create", "id": 4, "kind": "popup", "parent": 1, "index": 2, "props": {"choices": ["a", "b"], "selected": 0}},
                {"op": "create", "id": 5, "kind": "list", "parent": 1, "index": 3, "props": {"multiple": true}},
                {"op": "create", "id": 6, "kind": "listbox", "parent": 1, "index": 4, "props": {"selected": [0]}},
                {"op": "create", "id": 7, "kind": "slider", "parent": 1, "index": 5, "props": {"value": 5}},
                {"op": "create", "id": 8, "kind": "tabs", "parent": 1, "index": 6, "props": {"active": 0}},
                {"op": "create", "id": 9, "kind": "password", "parent": 1, "index": 7, "props": {"value": ""}},
                {"op": "create", "id": 10, "kind": "number", "parent": 1, "index": 8, "props": {"value": "1"}},
                {"op": "create", "id": 11, "kind": "disclosure", "parent": 1, "index": 9, "props": {"expanded": false}}
            ]}
            """)).Ops);
        return tree;
    }

    [Fact]
    public void AnEditIsKeptWhenOtherPropsChange()
    {
        var tree = Tree();
        Assert.True(tree.Acknowledge(2, "change", "Ada Lovelace"));
        // The application lowers the limit: the value it took stays
        tree.Apply(new PatchOp[] { new UpdateOp(2, new JsonObject { ["maxLength"] = 30 }) });
        Assert.Equal("Ada Lovelace", tree.Find(2)!.String("value"));
        Assert.Equal(30, tree.Find(2)!.Int("maxLength"));
        // Its own update wins
        tree.Apply(new PatchOp[] { new UpdateOp(2, new JsonObject { ["value"] = "Ada" }) });
        Assert.Equal("Ada", tree.Find(2)!.String("value"));
    }

    [Fact]
    public void EachKindKeepsTheProp()
    {
        var tree = Tree();
        // As the reconciler sends them: values made in code
        Assert.True(tree.Acknowledge(3, "toggle", true));
        Assert.True(tree.Acknowledge(4, "select", 1));
        Assert.True(tree.Acknowledge(5, "select", new JsonArray(0, 2)));
        Assert.True(tree.Acknowledge(6, "select", new JsonArray(1, 2)));
        Assert.True(tree.Acknowledge(7, "change", Stepping.Json(7.5)));
        Assert.True(tree.Acknowledge(8, "tab", 2));
        Assert.True(tree.Acknowledge(9, "submit", "s3cret"));
        Assert.True(tree.Acknowledge(10, "change", "1x"));
        Assert.True(tree.Acknowledge(11, "toggle", true));
        Assert.True(tree.Find(3)!.Bool("checked"));
        Assert.Equal(1, tree.Find(4)!.Int("selected"));
        Assert.Equal("[0,2]", tree.Find(5)!.Prop("checked")!.ToJsonString());
        Assert.Null(tree.Find(5)!.Prop("selected"));
        Assert.Equal("[1,2]", tree.Find(6)!.Prop("selected")!.ToJsonString());
        Assert.Equal(7.5, tree.Find(7)!.Number("value"));
        Assert.Equal(2, tree.Find(8)!.Int("active"));
        Assert.Equal("s3cret", tree.Find(9)!.String("value"));
        Assert.Equal("1x", tree.Find(10)!.String("value"));
        Assert.True(tree.Find(11)!.Bool("expanded"));
        // A whole number read back as a double and an int
        Assert.True(tree.Acknowledge(7, "change", Stepping.Json(55)));
        Assert.Equal(55, tree.Find(7)!.Number("value"));
        Assert.Equal(55, tree.Find(7)!.Int("value"));
    }

    [Fact]
    public void OtherEventsChangeNothing()
    {
        var tree = Tree();
        Assert.False(tree.Acknowledge(2, "cancel", null));
        Assert.False(tree.Acknowledge(2, "focus", null));
        Assert.False(tree.Acknowledge(3, "toggle", false), "no change");
        Assert.False(tree.Acknowledge(4, "select", "b"), "not an index");
        Assert.False(tree.Acknowledge(6, "select", 1), "a list box sends a list");
        Assert.False(tree.Acknowledge(7, "change", "55"), "a slider sends a number");
        Assert.False(tree.Acknowledge(99, "change", "x"), "no such node");
        Assert.Equal("Ada", tree.Find(2)!.String("value"));
    }
}
