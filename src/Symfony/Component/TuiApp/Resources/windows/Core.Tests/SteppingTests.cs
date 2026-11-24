using System.Text.Json.Nodes;
using Xunit;

namespace TuiAppHost.Core.Tests;

/// <summary>Number fields and sliders step as HTML does, and as the macOS host's Stepping.</summary>
public class SteppingTests
{
    [Fact]
    public void UpAndDownGoToTheNextStepWithinTheBounds()
    {
        Assert.Equal("1.5", Stepping.Step("1", 1, 0, 10, 0.5));
        Assert.Equal("1", Stepping.Step("1.5", -1, 0, 10, 0.5));
        Assert.Equal("0", Stepping.Step("0", -1, 0, 10, 0.5));
        Assert.Equal("10", Stepping.Step("10", 1, 0, 10, 0.5));
        // Past max: the highest step at or below it, not max
        Assert.Equal("9", Stepping.Step("25", -1, 0, 10, 3));
        Assert.Equal("9", Stepping.Step("9", 1, 0, 10, 3));
        // Like HTML: no change when the bounds would take the value the other way
        Assert.Equal("12", Stepping.Step("12", 1, 0, 10, 3));
        Assert.Equal("-3", Stepping.Step("-3", -1, 0, 10, 3));
        Assert.Equal("0", Stepping.Step("-3", 1, 0, 10, 3));
        Assert.Equal("10", Stepping.Step("12", -1, 0, 10, 0.5));
        // An empty or invalid value is 0
        Assert.Equal("1", Stepping.Step("", 1, null, null, 1));
        Assert.Equal("-1", Stepping.Step("abc", -1, null, null, 1));
        Assert.Equal("1", Stepping.Step("1x", 1, null, null, 1));
        Assert.Equal("0", Stepping.Step("-0.5", 1, null, null, 0.5));
    }

    [Fact]
    public void AValueOffTheStepsGoesToTheNearestStepThatWay()
    {
        Assert.Equal("1.5", Stepping.Step("1.2", 1, 0, null, 0.5));
        Assert.Equal("1", Stepping.Step("1.2", -1, 0, null, 0.5));
        Assert.Equal("5", Stepping.Step("4", 1, 1, null, 2));
        Assert.Equal("0.3", Stepping.Step("0.2", 1, null, null, 0.1));
    }

    [Fact]
    public void ASliderSnapsToItsSteps()
    {
        Assert.Equal(75, Stepping.Snap(73, 0, 100, 5));
        Assert.Equal(8, Stepping.Snap(9, 0, 9, 2));
        Assert.Equal(0, Stepping.Snap(-4, 0, 10, 1));
        Assert.Equal(0.3, Stepping.Snap(0.30000000000000004, 0, 1, 0.1));
        Assert.Equal(55, Stepping.Snap(52.5, 0, 100, 5));
        Assert.Equal("55", Stepping.Json(55).ToJsonString());
        Assert.Equal("0.5", Stepping.Json(0.5).ToJsonString());
    }

    [Fact]
    public void TheRangeComesFromTheProps()
    {
        var tree = new NodeTree();
        tree.Apply(new PatchOp[]
        {
            new CreateOp(1, "stack", null, 0, new JsonObject()),
            new CreateOp(2, "number", 1, 0, (JsonObject)JsonNode.Parse("""{"min": 0, "max": 10.0, "step": 0.5}""")!),
            new CreateOp(3, "slider", 1, 1, (JsonObject)JsonNode.Parse("""{"step": 0}""")!),
        });
        Assert.Equal(((double?)0, (double?)10, 0.5), Stepping.Range(tree.Find(2)!));
        Assert.Equal(((double?)null, (double?)null, 1.0), Stepping.Range(tree.Find(3)!));
    }
}
