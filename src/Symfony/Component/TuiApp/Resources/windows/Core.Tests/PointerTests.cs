using System;
using TuiAppHost.Core;
using Xunit;

namespace TuiAppHost.Core.Tests;

public class PointerTests
{
    private static readonly string[] None = Array.Empty<string>();

    [Fact]
    public void CellsAreRoundedDownAlsoPastTheEdges()
    {
        Assert.Equal((0, 1), PointerTracker.Cell(10.5, 3, 8.4, 17));
        Assert.Equal((1, 0), PointerTracker.Cell(8.39, 17, 8.4, 17));
        Assert.Equal((-1, -2), PointerTracker.Cell(-9, -0.5, 8.4, 17));
    }

    /// <summary>
    /// At 125%, as the canvas test met it in a VM: cells are in DIPs (WPF's positions), not device pixels. The middle of
    /// cell (0, 1), 15.2 DIPs from the canvas's corner, is 19 pixels; 23.2 DIPs (the 29 pixels the cursor was left at,
    /// kept at the screen's edge) is cell (0, 2), as the host sent.
    /// </summary>
    [Theory]
    [InlineData(19, 13, 0, 1)]
    [InlineData(29, 13, 0, 2)]
    [InlineData(12.6, 25.1, 0, 0)]
    [InlineData(12.8, 25.2, 1, 1)]
    public void CellsOfDevicePixelsAt125Percent(double xPixels, double yPixels, int row, int column)
    {
        const double scale = 1.25, width = 10.15625, height = 20.143229166666664;
        Assert.Equal((row, column), PointerTracker.Cell(xPixels / scale, yPixels / scale, width, height));
    }

    [Fact]
    public void ModifiersInTheProtocolsOrder()
    {
        Assert.Equal(new[] { "ctrl", "shift", "alt", "super" }, PointerTracker.Modifiers(true, true, true, true));
        Assert.Equal(new[] { "alt", "super" }, PointerTracker.Modifiers(false, false, true, true));
        Assert.Empty(PointerTracker.Modifiers(false, false, false, false));
    }

    [Fact]
    public void AMoveIsSentOncePerCellWithTheButtonHeld()
    {
        var pointer = new PointerTracker();
        Assert.Equal(new PointerMessage(0, "move", 2, 4, null, None), pointer.Move((2, 4), None));
        Assert.Null(pointer.Move((2, 4), None));
        Assert.Equal(new PointerMessage(0, "press", 0, 1, "left", None), pointer.Press("left", (0, 1), None));
        Assert.Null(pointer.Move((0, 1), None));
        Assert.Equal("left", pointer.Move((1, 3), None)!.Button);
        Assert.Null(pointer.Move((1, 3), None));
        // Held, the pointer leaving sends nothing; past the edge, negative cells
        Assert.Null(pointer.Leave(None));
        var past = pointer.Move((-1, 3), None)!;
        Assert.Equal((-1, 3, "left"), (past.Row, past.Column, past.Button));
        var release = pointer.Release("left", (-1, 3), None);
        Assert.Equal(("release", "left"), (release.Action, release.Button));
        Assert.Null(pointer.Held);
        Assert.Null(pointer.Move((-1, 3), None));
        Assert.Null(pointer.Move((0, 0), None)!.Button);
    }

    [Fact]
    public void LeaveIsAtTheCellLastSentAndOnce()
    {
        var pointer = new PointerTracker();
        Assert.Null(pointer.Leave(None));
        pointer.Move((4, 7), None);
        var leave = pointer.Leave(None)!;
        Assert.Equal(("leave", 4, 7, (string?)null), (leave.Action, leave.Row, leave.Column, leave.Button));
        Assert.Null(pointer.Leave(None));
        // Back in the same cell: sent again
        Assert.NotNull(pointer.Move((4, 7), None));
    }

    [Fact]
    public void AWheelSendsWholeRowsAndKeepsTheRest()
    {
        var pointer = new PointerTracker();
        // 3 notches down: 3 rows; up is negative
        var down = pointer.Wheel((1, 2), -360, 0, None)!;
        Assert.Equal((3, 0, true, true), (down.Rows.Value, down.Columns.Value, down.Rows.IsSet, down.Columns.IsSet));
        Assert.Equal(-1, pointer.Wheel((1, 2), 120, 0, None)!.Rows.Value);
        // A touchpad's parts: 40 + 40 + 50 make one row, 10 kept
        Assert.Null(pointer.Wheel((1, 2), -40, 0, None));
        Assert.Null(pointer.Wheel((1, 2), -40, 0, None));
        Assert.Equal(1, pointer.Wheel((1, 2), -50, 0, None)!.Rows.Value);
        Assert.Equal(1, pointer.Wheel((1, 2), -110, 0, None)!.Rows.Value);
        // The other way drops what was kept
        Assert.Null(pointer.Wheel((1, 2), -60, 0, None));
        Assert.Null(pointer.Wheel((1, 2), 60, 0, None));
        Assert.Equal(-1, pointer.Wheel((1, 2), 60, 0, None)!.Rows.Value);
        // Right is positive
        var right = pointer.Wheel((1, 2), 0, 240, None)!;
        Assert.Equal((0, 2), (right.Rows.Value, right.Columns.Value));
        Assert.Equal(
            "{\"type\":\"pointer\",\"node\":0,\"action\":\"wheel\",\"row\":1,\"column\":2,\"button\":null,\"modifiers\":[],\"rows\":0,\"columns\":2}",
            MessageCodec.Serialize(right));
    }
}
