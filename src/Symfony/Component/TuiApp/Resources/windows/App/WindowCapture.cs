using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;

namespace TuiAppHost;

/// <summary>
/// PNGs of windows as the screen shows them, for the tests' captures: the desktop window manager's copy of a
/// window (PrintWindow with PW_RENDERFULLCONTENT), not the window drawn again to a bitmap (RenderTargetBitmap),
/// which is not what is on the screen. Nothing above the window gets in. Cut to the frame that is seen, without
/// the invisible resize borders around it.
/// </summary>
internal static class WindowCapture
{
    private const uint RenderFullContent = 2;
    private const int ExtendedFrameBounds = 9;

    /// <summary>Writes the window to a PNG; its size in pixels.</summary>
    public static (int Width, int Height) Save(IntPtr window, string path)
    {
        if (!GetWindowRect(window, out var outer))
        {
            throw new InvalidOperationException($"no window {window}");
        }

        var seen = DwmGetWindowAttribute(window, ExtendedFrameBounds, out Rect frame, Marshal.SizeOf<Rect>()) == 0 && frame.Right > frame.Left && frame.Bottom > frame.Top ? frame : outer;

        using var whole = new Bitmap(Math.Max(1, outer.Right - outer.Left), Math.Max(1, outer.Bottom - outer.Top), PixelFormat.Format32bppArgb);
        using (var graphics = Graphics.FromImage(whole))
        {
            var dc = graphics.GetHdc();
            try
            {
                if (!PrintWindow(window, dc, RenderFullContent))
                {
                    throw new InvalidOperationException($"PrintWindow failed ({Marshal.GetLastWin32Error()})");
                }
            }
            finally
            {
                graphics.ReleaseHdc(dc);
            }
        }

        var cut = Rectangle.Intersect(new Rectangle(seen.Left - outer.Left, seen.Top - outer.Top, seen.Right - seen.Left, seen.Bottom - seen.Top), new Rectangle(0, 0, whole.Width, whole.Height));
        using var shown = whole.Clone(cut.IsEmpty ? new Rectangle(0, 0, whole.Width, whole.Height) : cut, PixelFormat.Format32bppArgb);
        shown.Save(path, ImageFormat.Png);
        return (shown.Width, shown.Height);
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct Rect
    {
        public int Left, Top, Right, Bottom;
    }

    [DllImport("user32.dll", SetLastError = true)]
    private static extern bool GetWindowRect(IntPtr handle, out Rect rect);

    [DllImport("user32.dll", SetLastError = true)]
    private static extern bool PrintWindow(IntPtr handle, IntPtr dc, uint flags);

    [DllImport("dwmapi.dll")]
    private static extern int DwmGetWindowAttribute(IntPtr handle, int attribute, out Rect value, int size);
}
