using System;
using System.Net.Sockets;
using Xunit;

namespace TuiAppHost.Core.Tests;

public class FlowTests
{
    [Fact]
    public void CloseBeforeTheAppConnectsClosesNow()
    {
        var flow = new CloseFlow();
        Assert.Equal(CloseStep.CloseNow, flow.CloseRequested());
    }

    [Fact]
    public void AcceptedCloseWaitsForByeAndExit()
    {
        var flow = new CloseFlow();
        flow.Connected();
        Assert.Equal(CloseStep.AskApp, flow.CloseRequested());
        Assert.Equal(CloseStep.Wait, flow.CloseRequested());
        Assert.Equal(CloseStep.WaitForExit, flow.Replied(true));
        Assert.Equal(CloseStep.WaitForExit, flow.Bye());
        Assert.True(flow.ByeReceived);
        Assert.Equal(CloseStep.CloseNow, flow.Exited());
        Assert.True(flow.HasExited);
    }

    [Fact]
    public void RefusedCloseKeepsTheWindow()
    {
        var flow = new CloseFlow();
        flow.Connected();
        flow.CloseRequested();
        Assert.Equal(CloseStep.StopWaiting, flow.Replied(false));
        Assert.Equal(CloseStep.Wait, flow.TimedOut());
        Assert.Equal(CloseStep.AskApp, flow.CloseRequested());
    }

    [Fact]
    public void NoAnswerOffersForceQuit()
    {
        var flow = new CloseFlow();
        flow.Connected();
        flow.CloseRequested();
        Assert.Equal(CloseStep.OfferForceQuit, flow.TimedOut());
        Assert.Equal(CloseStep.StopWaiting, flow.ForceQuitDeclined());
        Assert.Equal(CloseStep.AskApp, flow.CloseRequested());
        Assert.Equal(CloseStep.OfferForceQuit, flow.TimedOut());
        Assert.Equal(CloseStep.CloseNow, flow.ForceQuit());
        Assert.Equal(CloseStep.Wait, flow.Exited());
    }

    [Fact]
    public void NoExitAfterByeOffersForceQuitAndKeepsWaitingOnNo()
    {
        var flow = new CloseFlow();
        flow.Connected();
        flow.CloseRequested();
        flow.Replied(true);
        flow.Bye();
        Assert.Equal(CloseStep.OfferForceQuit, flow.TimedOut());
        Assert.Equal(CloseStep.WaitForExit, flow.ForceQuitDeclined());
    }

    [Fact]
    public void AnExitBeforeAuthShowsWhy()
    {
        Assert.Equal(CloseStep.ShowStartFailure, new CloseFlow().Exited());
        var connected = new CloseFlow();
        connected.Connected();
        Assert.Equal(CloseStep.CloseNow, connected.Exited());
    }

    [Fact]
    public void ByeWithoutCloseWaitsForTheExit()
    {
        var flow = new CloseFlow();
        flow.Connected();
        Assert.Equal(CloseStep.WaitForExit, flow.Bye());
        Assert.Equal(CloseStep.CloseNow, flow.Exited());
    }

    [Fact]
    public void ConvertsFramesToCells()
    {
        Assert.Equal(new[] { 0, 0, 100, 30 }, Cells.Rect(0, 0, 840, 510, 8.4, 17));
        Assert.Equal(new[] { 1, 2, 3, 1 }, Cells.Rect(16.8, 17, 25.2000001, 16.9, 8.4, 17));
        Assert.Equal(new[] { 2, 1, 1, 1 }, Cells.Rect(8.5, 34, 0.1, 0.1, 8.4, 17));
        Assert.Equal(100, Cells.Fit(845, 8.4));
        Assert.Equal(1, Cells.Fit(0, 8.4));
    }

    /// <summary>
    /// A placed control's edges on the device pixels nearest its cells', at the DPI given (the window's new one when it
    /// changes, not the one it had): 10 columns of 10.15625 from column 10, at 100%, 125% and 150%.
    /// </summary>
    [Theory]
    [InlineData(1.0, 102, 101)]
    [InlineData(1.25, 101.6, 101.6)]
    [InlineData(1.5, 101.333333, 102)]
    public void SnapsToThePixelsOfTheScaleGiven(double scale, double start, double size)
    {
        var (snappedStart, snappedSize) = Cells.Snap(10, 10, 10.15625, scale);
        Assert.Equal(start, snappedStart, 5);
        Assert.Equal(size, snappedSize, 5);
        // Whole pixels at that scale, the far edge within half a pixel of the cells'
        Assert.Equal(Math.Round(snappedStart * scale), snappedStart * scale, 6);
        Assert.Equal(Math.Round((snappedStart + snappedSize) * scale), (snappedStart + snappedSize) * scale, 6);
        Assert.True(Math.Abs((snappedStart + snappedSize) - 20 * 10.15625) <= 0.5 / scale + 1e-9);
    }

    [Fact]
    public void GeometryRoundTripsAndStaysReachable()
    {
        var geometry = new WindowGeometry(100, 50, 900, 640, false);
        Assert.Equal(geometry, WindowGeometry.FromJson(geometry.ToJson()));
        Assert.Null(WindowGeometry.FromJson("{nope"));
        Assert.Null(WindowGeometry.FromJson(null));
        Assert.True(geometry.IsVisibleOn(0, 0, 1920, 1080));
        Assert.False((geometry with { Left = 3000 }).IsVisibleOn(0, 0, 1920, 1080));
        Assert.False((geometry with { Top = -500 }).IsVisibleOn(0, 0, 1920, 1080));
    }

    [Fact]
    public async Task RefusesBadAuthAndAcceptsTheRightToken()
    {
        var token = LaunchPlan.NewToken();
        using var connection = AppConnection.Listen();
        Assert.StartsWith("tcp://127.0.0.1:", connection.Address);
        var accepting = connection.AcceptAsync(token, TimeSpan.FromSeconds(10));

        using (var wrong = new TcpClient())
        {
            await wrong.ConnectAsync("127.0.0.1", connection.Port);
            await wrong.GetStream().WriteAsync(MessageCodec.Frame(new AuthMessage(new string('0', 32), 1)));
            Assert.Null(await FrameCodec.ReadAsync(wrong.GetStream()));
        }

        using (var protocol = new TcpClient())
        {
            await protocol.ConnectAsync("127.0.0.1", connection.Port);
            await protocol.GetStream().WriteAsync(MessageCodec.Frame(new AuthMessage(token, 2)));
            Assert.Null(await FrameCodec.ReadAsync(protocol.GetStream()));
        }

        using var app = new TcpClient();
        await app.ConnectAsync("127.0.0.1", connection.Port);
        var stream = app.GetStream();
        await stream.WriteAsync(MessageCodec.Frame(new AuthMessage(token, 1)));
        Assert.True(await accepting);
        Assert.Equal(2, connection.Refused.Count);

        connection.Send(new BeepMessage());
        Assert.Equal("{\"type\":\"beep\"}", System.Text.Encoding.UTF8.GetString((await FrameCodec.ReadAsync(stream))!));

        var received = new List<Message>();
        var malformed = new List<string>();
        var reading = connection.ReadAsync(received.Add, malformed.Add);
        await stream.WriteAsync(FrameCodec.Encode("not json"));
        await stream.WriteAsync(MessageCodec.Frame(new ByeMessage(0)));
        app.Client.Shutdown(SocketShutdown.Send);
        await reading.WaitAsync(TimeSpan.FromSeconds(10));
        Assert.IsType<ByeMessage>(Assert.Single(received));
        Assert.Single(malformed);
    }

    [Fact]
    public async Task ASilentConnectionDoesNotKeepTheApplicationOut()
    {
        var token = LaunchPlan.NewToken();
        using var connection = AppConnection.Listen();
        var accepting = connection.AcceptAsync(token, TimeSpan.FromSeconds(10));

        using var silent = new TcpClient();
        await silent.ConnectAsync("127.0.0.1", connection.Port);
        using var app = new TcpClient();
        await app.ConnectAsync("127.0.0.1", connection.Port);
        await app.GetStream().WriteAsync(MessageCodec.Frame(new AuthMessage(token, 1)));

        Assert.True(await accepting.WaitAsync(TimeSpan.FromSeconds(2)));
        Assert.Null(await FrameCodec.ReadAsync(silent.GetStream()).WaitAsync(TimeSpan.FromSeconds(2)));
    }

    [Fact]
    public async Task RefusesALargeFirstFrame()
    {
        var token = LaunchPlan.NewToken();
        using var connection = AppConnection.Listen();
        var accepting = connection.AcceptAsync(token, TimeSpan.FromMilliseconds(1500));

        using var large = new TcpClient();
        await large.ConnectAsync("127.0.0.1", connection.Port);
        var header = new byte[4];
        System.Buffers.Binary.BinaryPrimitives.WriteUInt32BigEndian(header, AppConnection.MaxAuthBytes + 1);
        await large.GetStream().WriteAsync(header);

        Assert.False(await accepting);
        Assert.Contains(connection.Refused, reason => reason.Contains("byte limit"));
    }

    [Fact]
    public async Task GivesUpWhenNobodyConnects()
    {
        using var connection = AppConnection.Listen();
        Assert.False(await connection.AcceptAsync("00", TimeSpan.FromMilliseconds(300)));
    }
}
