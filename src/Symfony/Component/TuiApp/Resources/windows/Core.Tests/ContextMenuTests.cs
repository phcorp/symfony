using System.Text.Json.Nodes;
using Xunit;

namespace TuiAppHost.Core.Tests;

public class ContextMenuTests
{
    [Fact]
    public void DecodesTheContextMenuFixture()
    {
        var menu = Assert.IsType<ContextMenuMessage>(MessageCodec.Parse(Fixtures.Messages["contextmenu"].ToJsonString()));
        Assert.Equal((7, 3, 12), (menu.Node, menu.Row, menu.Column));
        Assert.Equal(5, menu.Items.Count);
        Assert.Equal(("1", "Open Link", true, false), (menu.Items[0].Id.Value, menu.Items[0].Label.Value, menu.Items[0].IsEnabled, menu.Items[0].IsPaste));
        Assert.Equal(("2", "Copy Link Address"), (menu.Items[1].Id.Value, menu.Items[1].Label.Value));
        Assert.True(menu.Items[2].IsSeparator);
        Assert.Equal(("3", "Paste", true, true), (menu.Items[3].Id.Value, menu.Items[3].Label.Value, menu.Items[3].IsEnabled, menu.Items[3].IsPaste));
        Assert.Equal(("4", "Nothing to undo", false, false), (menu.Items[4].Id.Value, menu.Items[4].Label.Value, menu.Items[4].IsEnabled, menu.Items[4].IsPaste));
        Assert.All(new[] { 0, 1, 3, 4 }, i => Assert.False(menu.Items[i].IsSeparator));
    }

    [Fact]
    public void DecodesTheClipboardAndSelectFixtures()
    {
        Assert.Equal("https://example.com/docs", Assert.IsType<ClipboardMessage>(MessageCodec.Parse(Fixtures.Messages["clipboard"].ToJsonString())).Text);
        Assert.Equal("2", Assert.IsType<ContextMenuSelectMessage>(MessageCodec.Parse(Fixtures.Messages["contextmenu-select"].ToJsonString())).Id);
    }

    [Fact]
    public void EncodesASelectionAsTheFixture()
    {
        Fixtures.AssertJsonEqual(Fixtures.Messages["contextmenu-select"], JsonNode.Parse(MessageCodec.Serialize(new ContextMenuSelectMessage("2"))));
    }

    /// <summary>An item may say separator false; its id is opaque, passed on as it came ("1:1").</summary>
    [Fact]
    public void TakesAnItemThatSaysSeparatorFalseAndAnOpaqueId()
    {
        var menu = Assert.IsType<ContextMenuMessage>(MessageCodec.Parse("""{"type":"contextmenu","node":1,"row":0,"column":0,"items":[{"separator":false,"id":"1:1","label":"Open"},{"separator":true},{"id":"2:7","label":"Copy"}]}"""));
        Assert.Equal(3, menu.Items.Count);
        Assert.False(menu.Items[0].IsSeparator);
        Assert.Equal(("1:1", "Open"), (menu.Items[0].Id.Value, menu.Items[0].Label.Value));
        Assert.True(menu.Items[1].IsSeparator);
        Assert.Equal("2:7", menu.Items[2].Id.Value);
        Fixtures.AssertJsonEqual(JsonNode.Parse("""{"type":"contextmenu-select","id":"1:1"}""")!, JsonNode.Parse(MessageCodec.Serialize(new ContextMenuSelectMessage(menu.Items[0].Id.Value))));
    }

    [Fact]
    public void RefusesAnItemWithNoLabel()
    {
        Assert.Throws<MessageException>(() => MessageCodec.Parse("""{"type":"contextmenu","node":1,"row":0,"column":0,"items":[{"id":"1"}]}"""));
    }
}
