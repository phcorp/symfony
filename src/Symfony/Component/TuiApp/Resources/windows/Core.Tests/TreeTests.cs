using System.Text.Json.Nodes;
using Xunit;

namespace TuiAppHost.Core.Tests;

public class TreeTests
{
    [Fact]
    public void SessionStepsGiveTheExpectedTrees()
    {
        var tree = new NodeTree();
        var steps = (JsonArray)Fixtures.Load("session")["steps"]!;
        Assert.NotEmpty(steps);
        foreach (var step in steps)
        {
            var patch = (PatchMessage)MessageCodec.Parse(Fixtures.Messages[step!["patch"]!.GetValue<string>()].ToJsonString());
            var result = tree.Apply(patch.Ops);
            Assert.Empty(result.Warnings);
            Fixtures.AssertJsonEqual(step["tree"], NodeTree.Serialize(tree.Root!));
        }
    }

    [Fact]
    public void ReportsWhatChanged()
    {
        var tree = new NodeTree();
        var create = tree.Apply(Patch("patch-create").Ops);
        Assert.True(create.RootChanged);
        Assert.Equal(new[] { 1, 2, 3 }, create.Created.OrderBy(i => i));
        Assert.Contains(1, create.ChildrenChanged);

        tree.Apply(Patch("patch-all-kinds").Ops);
        var last = tree.Apply(Patch("patch-update-move-remove").Ops);
        Assert.Equal(new[] { 2 }, last.Updated);
        Assert.Equal(new[] { 3 }, last.Removed);
        Assert.Equal(new[] { 1 }, last.ChildrenChanged);
        Assert.Null(tree.Find(3));
        Assert.Null(tree.Find(2)!.Prop("style"));
    }

    [Fact]
    public void RemovingANodeRemovesItsDescendants()
    {
        var tree = new NodeTree();
        tree.Apply(Patch("patch-create").Ops);
        tree.Apply(Patch("patch-all-kinds").Ops);
        var result = tree.Apply(new[] { new RemoveOp(13) });
        Assert.Equal(new[] { 13, 14, 15 }, result.Removed.OrderBy(i => i));
        Assert.Null(tree.Find(14));
    }

    [Fact]
    public void MovesWithinAndAcrossParents()
    {
        var tree = new NodeTree();
        tree.Apply(Patch("patch-create").Ops);
        tree.Apply(Patch("patch-all-kinds").Ops);
        tree.Apply(new PatchOp[] { new MoveOp(21, 1, 0), new MoveOp(2, 14, 0) });
        Assert.Equal(21, tree.Root!.Children[0].Id);
        Assert.Equal(14, tree.Find(2)!.Parent!.Id);

        var cycle = tree.Apply(new[] { new MoveOp(13, 14, 0) });
        Assert.Single(cycle.Warnings);
    }

    [Fact]
    public void UnknownNodesAreWarningsNotErrors()
    {
        var tree = new NodeTree();
        var result = tree.Apply(new PatchOp[] { new UpdateOp(9, new JsonObject()), new RemoveOp(9), new MoveOp(9, 1, 0), new CreateOp(5, "label", 4, 0, new JsonObject()) });
        Assert.Equal(4, result.Warnings.Count);
        Assert.Null(tree.Root);
    }

    private static PatchMessage Patch(string name) => (PatchMessage)MessageCodec.Parse(Fixtures.Messages[name].ToJsonString());
}
