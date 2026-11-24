using System.Text.Json;

namespace TuiAppHost.Core;

/// <summary>Device-independent pixels to cells.</summary>
public static class Cells
{
    // Layout rounding leaves 84.00000001 where 84 was meant: never a whole extra cell for that.
    private const double Epsilon = 1e-6;

    /// <summary>[row, column, columns, rows] of a frame relative to the content area.</summary>
    public static int[] Rect(double x, double y, double width, double height, double cellWidth, double cellHeight) => new[]
    {
        (int)Math.Floor(y / cellHeight + Epsilon),
        (int)Math.Floor(x / cellWidth + Epsilon),
        (int)Math.Ceiling(width / cellWidth - Epsilon),
        (int)Math.Ceiling(height / cellHeight - Epsilon),
    };

    /// <summary>
    /// Cells <paramref name="start"/> to <paramref name="start"/> + <paramref name="count"/> of a size, as DIPs whose
    /// edges are on the device pixels nearest the cells' at a scale: the start and the size between the snapped edges
    /// (layout rounding would round the start and the size each, the far edge past the cells').
    /// </summary>
    public static (double Start, double Size) Snap(int start, int count, double cell, double scale)
    {
        double Edge(double dips) => Math.Round(dips * scale) / scale;
        var first = Edge(start * cell);
        return (first, Math.Max(0, Edge((start + Math.Max(0, count)) * cell) - first));
    }

    /// <summary>How many whole cells fit, at least one.</summary>
    public static int Fit(double length, double cell) => Math.Max(1, (int)Math.Floor(length / cell + Epsilon));
}

/// <summary>The window's place, kept in %APPDATA%\&lt;app id&gt;\window.json with rememberGeometry.</summary>
public sealed record WindowGeometry(double Left, double Top, double Width, double Height, bool Maximized)
{
    private static readonly JsonSerializerOptions Options = new() { PropertyNamingPolicy = JsonNamingPolicy.CamelCase, WriteIndented = true };

    public string ToJson() => JsonSerializer.Serialize(this, Options);

    public static WindowGeometry? FromJson(string? json)
    {
        if (string.IsNullOrWhiteSpace(json))
        {
            return null;
        }

        try
        {
            var geometry = JsonSerializer.Deserialize<WindowGeometry>(json, Options);
            return geometry is { Width: > 0, Height: > 0 } ? geometry : null;
        }
        catch (JsonException)
        {
            return null;
        }
    }

    /// <summary>Whether enough of the window is on a screen to be grabbed: a monitor unplugged since does not keep it out of reach.</summary>
    public bool IsVisibleOn(double screenLeft, double screenTop, double screenWidth, double screenHeight)
    {
        const double Grab = 48;
        return Left + Width - Grab >= screenLeft && Left + Grab <= screenLeft + screenWidth
            && Top >= screenTop - Grab / 2 && Top + Grab <= screenTop + screenHeight;
    }

    public static string FilePath(string appData, string appId) => Path.Combine(appData, appId, "window.json");
}
