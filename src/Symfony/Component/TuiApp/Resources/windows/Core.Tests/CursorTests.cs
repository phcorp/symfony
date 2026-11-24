using System.Reflection.Metadata;
using System.Reflection.PortableExecutable;
using System.Text.Json.Nodes;
using Xunit;

namespace TuiAppHost.Core.Tests;

public class CursorTests
{
    /// <summary>Every keyword of the contract and the pointer Windows shows for it.</summary>
    private static readonly Dictionary<string, CursorShape> Expected = new()
    {
        ["default"] = CursorShape.Arrow,
        ["none"] = CursorShape.None,
        ["context-menu"] = CursorShape.Arrow,
        ["help"] = CursorShape.Help,
        ["pointer"] = CursorShape.Hand,
        ["progress"] = CursorShape.AppStarting,
        ["wait"] = CursorShape.Wait,
        ["cell"] = CursorShape.Cross,
        ["crosshair"] = CursorShape.Cross,
        ["text"] = CursorShape.IBeam,
        ["vertical-text"] = CursorShape.IBeam,
        ["alias"] = CursorShape.Arrow,
        ["copy"] = CursorShape.Arrow,
        ["move"] = CursorShape.SizeAll,
        ["no-drop"] = CursorShape.No,
        ["not-allowed"] = CursorShape.No,
        ["grab"] = CursorShape.Hand,
        ["grabbing"] = CursorShape.Hand,
        ["all-scroll"] = CursorShape.SizeAll,
        ["col-resize"] = CursorShape.SizeWE,
        ["row-resize"] = CursorShape.SizeNS,
        ["n-resize"] = CursorShape.SizeNS,
        ["e-resize"] = CursorShape.SizeWE,
        ["s-resize"] = CursorShape.SizeNS,
        ["w-resize"] = CursorShape.SizeWE,
        ["ne-resize"] = CursorShape.SizeNESW,
        ["nw-resize"] = CursorShape.SizeNWSE,
        ["se-resize"] = CursorShape.SizeNWSE,
        ["sw-resize"] = CursorShape.SizeNESW,
        ["ew-resize"] = CursorShape.SizeWE,
        ["ns-resize"] = CursorShape.SizeNS,
        ["nesw-resize"] = CursorShape.SizeNESW,
        ["nwse-resize"] = CursorShape.SizeNWSE,
        ["zoom-in"] = CursorShape.Arrow,
        ["zoom-out"] = CursorShape.Arrow,
    };

    [Fact]
    public void DecodesTheFixture()
    {
        var cursor = Assert.IsType<CursorMessage>(MessageCodec.Parse(Fixtures.Messages["cursor"].ToJsonString()));
        Assert.Equal((7, "pointer"), (cursor.Node, cursor.Shape));
        Assert.Equal("{\"type\":\"cursor\",\"node\":7,\"shape\":\"pointer\"}", MessageCodec.Serialize(cursor));
    }

    [Fact]
    public void EveryKeywordOfTheContractHasItsPointer()
    {
        var keywords = ((JsonArray)Fixtures.LoadFile("protocol.json")["$defs"]!["cursor"]!["properties"]!["shape"]!["enum"]!)
            .Select(k => k!.GetValue<string>()).ToList();
        Assert.Equal(Expected.Keys.OrderBy(k => k, StringComparer.Ordinal), keywords.OrderBy(k => k, StringComparer.Ordinal));
        var wrong = keywords.Where(k => CursorShapes.Of(k) != Expected[k]).Select(k => $"{k}: {CursorShapes.Of(k)}, not {Expected[k]}").ToList();
        Assert.True(wrong.Count == 0, string.Join("; ", wrong));
    }

    [Theory]
    [InlineData("")]
    [InlineData("Pointer")]
    [InlineData("-webkit-grab")]
    [InlineData("url(hand.cur), pointer")]
    public void AKeywordItDoesNotKnowIsTheArrow(string keyword) => Assert.Equal(CursorShape.Arrow, CursorShapes.Of(keyword));

    /// <summary>Each shape is a pointer of System.Windows.Input.Cursors in the WPF reference assembly, as the App takes it by name.</summary>
    [Fact]
    public void EveryShapeIsAWpfCursor()
    {
        using var pe = new PEReader(File.OpenRead(KeyTests.WpfReference("PresentationCore.dll")));
        var metadata = pe.GetMetadataReader();
        var cursors = metadata.TypeDefinitions.Select(metadata.GetTypeDefinition)
            .Single(t => metadata.GetString(t.Namespace) == "System.Windows.Input" && metadata.GetString(t.Name) == "Cursors");
        var properties = cursors.GetProperties().Select(p => metadata.GetString(metadata.GetPropertyDefinition(p).Name)).ToHashSet();
        var missing = Enum.GetNames<CursorShape>().Where(name => !properties.Contains(name)).ToList();
        Assert.True(missing.Count == 0, "no Cursors." + string.Join(", Cursors.", missing));
    }
}
