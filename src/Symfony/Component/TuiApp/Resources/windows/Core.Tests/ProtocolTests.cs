using System.Text;
using System.Text.Json.Nodes;
using Xunit;

namespace TuiAppHost.Core.Tests;

public class ProtocolTests
{
    public static IEnumerable<object[]> Frames() =>
        ((JsonArray)Fixtures.Load("frames")["frames"]!).Select(frame => new object[] { frame!["name"]!.GetValue<string>() });

    private static (string Json, byte[] Bytes) Frame(string name)
    {
        var frame = ((JsonArray)Fixtures.Load("frames")["frames"]!).Single(f => f!["name"]!.GetValue<string>() == name)!;
        return (frame["json"]!.GetValue<string>(), Convert.FromHexString(frame["hex"]!.GetValue<string>()));
    }

    [Theory]
    [MemberData(nameof(Frames))]
    public void EncodesFramesByteForByte(string name)
    {
        var (json, bytes) = Frame(name);
        Assert.Equal(bytes, FrameCodec.Encode(json));
    }

    [Theory]
    [MemberData(nameof(Frames))]
    public async Task DecodesFramesByteForByte(string name)
    {
        var (json, bytes) = Frame(name);
        var payload = await FrameCodec.ReadAsync(new MemoryStream(bytes));
        Assert.Equal(Encoding.UTF8.GetBytes(json), payload);
        Assert.Null(await FrameCodec.ReadAsync(new MemoryStream(Array.Empty<byte>())));
    }

    [Theory]
    [MemberData(nameof(Frames))]
    public void MessagesSerializeToTheFrameJson(string name)
    {
        var (json, bytes) = Frame(name);
        Assert.Equal(bytes, MessageCodec.Frame(MessageCodec.Parse(json)));
    }

    [Fact]
    public void DecoderSplitsFramesArrivingByteByByte()
    {
        var all = Frames().SelectMany(f => Frame((string)f[0]).Bytes).ToArray();
        var decoder = new FrameDecoder();
        var payloads = new List<string>();
        foreach (var value in all)
        {
            decoder.Push(new[] { value });
            while (decoder.TryRead(out var payload))
            {
                payloads.Add(Encoding.UTF8.GetString(payload));
            }
        }

        Assert.Equal(Frames().Select(f => Frame((string)f[0]).Json), payloads);
    }

    [Fact]
    public async Task RefusesOversizedFrames()
    {
        var header = new byte[] { 0x01, 0x00, 0x00, 0x01 };
        await Assert.ThrowsAsync<FrameException>(() => FrameCodec.ReadAsync(new MemoryStream(header)));
        await Assert.ThrowsAsync<FrameException>(() => FrameCodec.ReadAsync(new MemoryStream(new byte[] { 0, 0, 0, 9, 1 })));
    }

    [Theory]
    [MemberData(nameof(Fixtures.MessageNames), MemberType = typeof(Fixtures))]
    public void EveryMessageRoundTripsToEqualJson(string name)
    {
        var original = Fixtures.Messages[name];
        var message = MessageCodec.Parse(original.ToJsonString());
        Assert.IsNotType<UnknownMessage>(message);
        Fixtures.AssertJsonEqual(original, JsonNode.Parse(MessageCodec.Serialize(message)));
    }

    [Fact]
    public void KeepsNullApartFromAbsent()
    {
        var cleared = (WindowMessage)MessageCodec.Parse(Fixtures.Messages["window-no-progress"].ToJsonString());
        Assert.False(cleared.Title.IsSet);
        Assert.True(cleared.Progress.IsSet);
        Assert.Null(cleared.Progress.Value);

        var hostTitle = (WindowMessage)MessageCodec.Parse(Fixtures.Messages["window-host-title"].ToJsonString());
        Assert.True(hostTitle.Title.IsSet);
        Assert.Null(hostTitle.Title.Value);
        Assert.False(hostTitle.Progress.IsSet);

        var cancel = (EventMessage)MessageCodec.Parse(Fixtures.Messages["event-cancel"].ToJsonString());
        Assert.False(cancel.Value.IsSet);
    }

    [Fact]
    public void KeepsUnknownTypesAndIgnoresUnknownProperties()
    {
        var unknown = MessageCodec.Parse("{\"type\":\"future\",\"x\":[1]}");
        Assert.Equal("future", unknown.Type);
        Assert.Equal("{\"type\":\"future\",\"x\":[1]}", MessageCodec.Serialize(unknown));

        var beep = MessageCodec.Parse("{\"type\":\"beep\",\"loud\":true}");
        Assert.IsType<BeepMessage>(beep);
    }

    [Fact]
    public void PointerAsTheHostSendsIt()
    {
        // button and modifiers always there, button null with none held; rows and columns with wheel only
        Assert.Equal(
            "{\"type\":\"pointer\",\"node\":21,\"action\":\"move\",\"row\":-2,\"column\":15,\"button\":null,\"modifiers\":[]}",
            MessageCodec.Serialize(new PointerMessage(21, "move", -2, 15, null, System.Array.Empty<string>())));
        Assert.Equal(
            "{\"type\":\"pointer\",\"node\":21,\"action\":\"wheel\",\"row\":3,\"column\":12,\"button\":null,\"modifiers\":[\"ctrl\",\"shift\"],\"rows\":-3,\"columns\":0}",
            MessageCodec.Serialize(new PointerMessage(21, "wheel", 3, 12, null, new[] { "ctrl", "shift" }, -3, 0)));
        var press = (PointerMessage)MessageCodec.Parse(Fixtures.Messages["pointer-press"].ToJsonString());
        Assert.Equal(("press", "left", 3, 12), (press.Action, press.Button, press.Row, press.Column));
        Assert.False(press.Rows.IsSet);
        Assert.False(press.Columns.IsSet);
        var wheel = (PointerMessage)MessageCodec.Parse(Fixtures.Messages["pointer-wheel"].ToJsonString());
        Assert.Equal((-3, 0), (wheel.Rows.Value, wheel.Columns.Value));
        Assert.Null(wheel.Button);
    }

    [Fact]
    public void HelloAsTheHostSendsIt()
    {
        var hello = new HelloMessage(1, "windows", 100, 30, 8.4, 17, 1.5, "dark", new[] { "menu" });
        Assert.Equal(
            "{\"type\":\"hello\",\"protocol\":1,\"os\":\"windows\",\"columns\":100,\"rows\":30,\"cell\":{\"width\":8.4,\"height\":17},\"scale\":1.5,\"appearance\":\"dark\",\"capabilities\":[\"menu\"]}",
            MessageCodec.Serialize(hello));
    }
}
