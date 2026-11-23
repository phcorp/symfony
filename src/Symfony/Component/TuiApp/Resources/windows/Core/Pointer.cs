using System;
using System.Collections.Generic;

namespace TuiAppHost.Core;

/// <summary>
/// What the mouse does on one canvas, as <see cref="PointerMessage"/>s (HOSTS.md, The mouse on a canvas): positions in
/// the canvas's units turned to cells, a move sent only when its cell changes, the button held, and a wheel's deltas
/// added up into whole rows and columns. Node is left 0 for the caller to set.
/// </summary>
public sealed class PointerTracker
{
    /// <summary>A notch of a mouse wheel, in the units of Windows' wheel deltas (WHEEL_DELTA): one row, or one column.</summary>
    public const int Notch = 120;

    private string? held;
    private (int Row, int Column)? last;
    private int rowsLeft;
    private int columnsLeft;

    /// <summary>The button held, pressed on the canvas and not released yet: its moves and release are the canvas's.</summary>
    public string? Held => held;

    /// <summary>The cell under a point: its position divided by the cell's size, rounded down, also when negative.</summary>
    public static (int Row, int Column) Cell(double x, double y, double cellWidth, double cellHeight) =>
        ((int)Math.Floor(y / cellHeight), (int)Math.Floor(x / cellWidth));

    /// <summary>The modifier ids held, in the protocol's order.</summary>
    public static IReadOnlyList<string> Modifiers(bool ctrl, bool shift, bool alt, bool super)
    {
        var ids = new List<string>(4);
        if (ctrl)
        {
            ids.Add("ctrl");
        }

        if (shift)
        {
            ids.Add("shift");
        }

        if (alt)
        {
            ids.Add("alt");
        }

        if (super)
        {
            ids.Add("super");
        }

        return ids;
    }

    public PointerMessage Press(string button, (int Row, int Column) cell, IReadOnlyList<string> modifiers)
    {
        held = button;
        last = cell;
        return new PointerMessage(0, "press", cell.Row, cell.Column, button, modifiers);
    }

    public PointerMessage Release(string button, (int Row, int Column) cell, IReadOnlyList<string> modifiers)
    {
        if (held == button)
        {
            held = null;
        }

        last = cell;
        return new PointerMessage(0, "release", cell.Row, cell.Column, button, modifiers);
    }

    /// <summary>A move to a cell: null when it is the cell last sent.</summary>
    public PointerMessage? Move((int Row, int Column) cell, IReadOnlyList<string> modifiers)
    {
        if (last == cell)
        {
            return null;
        }

        last = cell;
        return new PointerMessage(0, "move", cell.Row, cell.Column, held, modifiers);
    }

    /// <summary>The pointer left the canvas: at the cell last sent, when no button is held and one was sent.</summary>
    public PointerMessage? Leave(IReadOnlyList<string> modifiers)
    {
        if (held is not null || last is not { } cell)
        {
            return null;
        }

        last = null;
        return new PointerMessage(0, "leave", cell.Row, cell.Column, null, modifiers);
    }

    /// <summary>
    /// A wheel turned at a cell, by Windows' deltas: <paramref name="up"/> (WM_MOUSEWHEEL, positive away from the
    /// user: up) and <paramref name="right"/> (WM_MOUSEHWHEEL, positive right). A notch (120) is a row or a column;
    /// what a precise wheel or a touchpad sends in parts is added up, the rest kept for the next delta, dropped when
    /// one goes the other way. Null until they make a whole row or column.
    /// </summary>
    public PointerMessage? Wheel((int Row, int Column) cell, int up, int right, IReadOnlyList<string> modifiers)
    {
        var rows = Add(ref rowsLeft, -up);
        var columns = Add(ref columnsLeft, right);
        if (rows == 0 && columns == 0)
        {
            return null;
        }

        return new PointerMessage(0, "wheel", cell.Row, cell.Column, null, modifiers, rows, columns);
    }

    private static int Add(ref int left, int delta)
    {
        if (delta == 0)
        {
            return 0;
        }

        if (Math.Sign(delta) != Math.Sign(left))
        {
            left = 0;
        }

        left += delta;
        var whole = left / Notch;
        left -= whole * Notch;
        return whole;
    }
}
