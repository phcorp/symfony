namespace TuiAppHost.Core;

/// <summary>The pointers Windows has (WPF's <c>Cursors</c>), for the CSS cursor keywords of a <see cref="CursorMessage"/>.</summary>
public enum CursorShape
{
    Arrow,
    None,
    Hand,
    IBeam,
    Cross,
    SizeAll,
    No,
    SizeWE,
    SizeNS,
    SizeNESW,
    SizeNWSE,
    Wait,
    AppStarting,
    Help,
}

public static class CursorShapes
{
    /// <summary>The closest pointer Windows has for a CSS cursor keyword: the arrow for one it has none for, or one it does not know.</summary>
    public static CursorShape Of(string keyword) => keyword switch
    {
        "none" => CursorShape.None,
        "pointer" or "grab" or "grabbing" => CursorShape.Hand,
        "text" or "vertical-text" => CursorShape.IBeam,
        "crosshair" or "cell" => CursorShape.Cross,
        "move" or "all-scroll" => CursorShape.SizeAll,
        "not-allowed" or "no-drop" => CursorShape.No,
        "ew-resize" or "col-resize" or "e-resize" or "w-resize" => CursorShape.SizeWE,
        "ns-resize" or "row-resize" or "n-resize" or "s-resize" => CursorShape.SizeNS,
        "nesw-resize" or "ne-resize" or "sw-resize" => CursorShape.SizeNESW,
        "nwse-resize" or "nw-resize" or "se-resize" => CursorShape.SizeNWSE,
        "wait" => CursorShape.Wait,
        "progress" => CursorShape.AppStarting,
        "help" => CursorShape.Help,
        _ => CursorShape.Arrow,
    };
}
