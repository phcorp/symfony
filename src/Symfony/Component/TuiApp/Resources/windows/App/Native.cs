using System;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Input;
using System.Windows.Interop;
using TuiAppHost.Core;

namespace TuiAppHost;

internal static class Native
{
    public const uint LeftDown = 0x0002;
    public const uint LeftUp = 0x0004;
    public const uint RightDown = 0x0008;
    public const uint RightUp = 0x0010;
    public const uint Wheel = 0x0800;
    public const uint HorizontalWheel = 0x1000;

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    public static extern bool SetCursorPos(int x, int y);

    [DllImport("user32.dll", EntryPoint = "GetCursorPos", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetCursorPosNative(out CursorPoint point);

    [StructLayout(LayoutKind.Sequential)]
    private struct CursorPoint
    {
        public int X;
        public int Y;
    }

    /// <summary>Where the cursor is on the screen, in pixels; null when it cannot be read.</summary>
    public static (int X, int Y)? CursorPosition() => GetCursorPosNative(out var point) ? (point.X, point.Y) : null;

    [DllImport("user32.dll", EntryPoint = "GetWindowRect", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetWindowRectNative(IntPtr window, out WindowFrame frame);

    [StructLayout(LayoutKind.Sequential)]
    private struct WindowFrame
    {
        public int Left;
        public int Top;
        public int Right;
        public int Bottom;
    }

    /// <summary>A window's frame on the screen, title bar and borders included, in pixels; null when it cannot be read.</summary>
    public static (int Left, int Top, int Right, int Bottom)? WindowRect(IntPtr window) =>
        GetWindowRectNative(window, out var frame) ? (frame.Left, frame.Top, frame.Right, frame.Bottom) : null;

    [DllImport("user32.dll", EntryPoint = "WindowFromPoint")]
    private static extern IntPtr WindowFromPointNative(CursorPoint point);

    [DllImport("user32.dll", EntryPoint = "GetAncestor")]
    private static extern IntPtr GetAncestorNative(IntPtr window, uint flags);

    /// <summary>The top-level window at a point of the screen (WindowFromPoint, then its root).</summary>
    public static IntPtr TopWindowAt(int x, int y) => GetAncestorNative(WindowFromPointNative(new CursorPoint { X = x, Y = y }), 2 /* GA_ROOT */);

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    public static extern bool OpenClipboard(IntPtr owner);

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    public static extern bool CloseClipboard();

    [DllImport("user32.dll", EntryPoint = "SystemParametersInfoW", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetScaleStep(uint action, uint param, out int value, uint update);

    [DllImport("user32.dll", EntryPoint = "SystemParametersInfoW", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetScaleStep(uint action, int param, IntPtr value, uint update);

    /// <summary>
    /// The display's scale as Settings sets it, in steps from the recommended one (SPI_GETLOGICALDPIOVERRIDE, not
    /// documented): null when it cannot be read.
    /// </summary>
    public static int? ScaleStep() => GetScaleStep(0x009E, 0, out var step, 0) ? step : null;

    /// <summary>
    /// The display's scale set as Settings does, at once (SPI_SETLOGICALDPIOVERRIDE): WM_DPICHANGED to the windows. Saved in
    /// the user's profile (PerMonitorSettings\&lt;monitor&gt;\DpiValue): unsaved, Windows applies none (measured in the VM).
    /// </summary>
    public static bool SetScaleStepNow(int step) => SetScaleStep(0x009F, step, IntPtr.Zero, 1 /* SPIF_UPDATEINIFILE */);

    [DllImport("user32.dll", EntryPoint = "keybd_event")]
    private static extern void KeyEventNative(byte key, byte scan, uint flags, UIntPtr extra);

    /// <summary>A key pressed and released, as input from the keyboard (the canvas test).</summary>
    public static void KeyPress(byte virtualKey)
    {
        KeyEventNative(virtualKey, 0, 0, UIntPtr.Zero);
        KeyEventNative(virtualKey, 0, 2 /* KEYEVENTF_KEYUP */, UIntPtr.Zero);
    }

    /// <summary>A key pressed and released with a modifier held, as input from the keyboard (the canvas test).</summary>
    public static void KeyChord(byte modifier, byte virtualKey)
    {
        KeyEventNative(modifier, 0, 0, UIntPtr.Zero);
        KeyPress(virtualKey);
        KeyEventNative(modifier, 0, 2 /* KEYEVENTF_KEYUP */, UIntPtr.Zero);
    }

    [DllImport("user32.dll", EntryPoint = "mouse_event")]
    private static extern void MouseEventNative(uint flags, int dx, int dy, int data, UIntPtr extra);

    /// <summary>A mouse button or a wheel, as input from the mouse, where the cursor is (the canvas test).</summary>
    public static void MouseEvent(uint flags, int data = 0) => MouseEventNative(flags, 0, 0, data, UIntPtr.Zero);

    public const int IdcArrow = 32512;
    public const int IdcIBeam = 32513;
    public const int IdcHand = 32649;

    [DllImport("user32.dll", EntryPoint = "LoadCursorW")]
    private static extern IntPtr LoadCursorNative(IntPtr instance, IntPtr name);

    [DllImport("user32.dll")]
    private static extern IntPtr GetCursor();

    /// <summary>A pointer of the system (IDC_*), the handle WPF's Cursors show for it.</summary>
    public static IntPtr SystemCursor(int id) => LoadCursorNative(IntPtr.Zero, (IntPtr)id);

    /// <summary>
    /// The pointer this thread last gave Windows (SetCursor, which WPF calls on WM_SETCURSOR for the element under
    /// the mouse), zero for none (the canvas test). Not GetCursorInfo: with no mouse (CURSOR_SUPPRESSED, as in a
    /// VM taking the pointer as a tablet) it says no pointer is shown, whatever is set.
    /// </summary>
    public static IntPtr ShownCursor() => GetCursor();

    private const int DwmUseImmersiveDarkMode = 20;
    private const int DwmUseImmersiveDarkModeBefore20H1 = 19;
    private const uint FlashTray = 2;
    private const uint FlashUntilForeground = 12;
    private const int AttachParentProcess = -1;
    private const int StdOutput = -11;
    private const uint MapVkToChar = 2;

    /// <summary>A dark or light title bar, as the theme says (Windows 10 20H1 and later; earlier builds use 19).</summary>
    public static void DarkTitleBar(Window window, bool dark)
    {
        var handle = new WindowInteropHelper(window).Handle;
        if (handle == IntPtr.Zero)
        {
            return;
        }

        var value = dark ? 1 : 0;
        if (DwmSetWindowAttribute(handle, DwmUseImmersiveDarkMode, ref value, sizeof(int)) != 0)
        {
            DwmSetWindowAttribute(handle, DwmUseImmersiveDarkModeBefore20H1, ref value, sizeof(int));
        }
    }

    /// <summary>The taskbar button flashes until the window comes to the front.</summary>
    public static void Flash(Window window)
    {
        var info = new FlashInfo
        {
            Size = (uint)Marshal.SizeOf<FlashInfo>(),
            Window = new WindowInteropHelper(window).Handle,
            Flags = FlashTray | FlashUntilForeground,
        };
        FlashWindowEx(ref info);
    }

    /// <summary>A GUI program has no console: print to the one it was started from, unless output is redirected.</summary>
    public static void ConsoleForOutput()
    {
        var handle = GetStdHandle(StdOutput);
        if (handle == IntPtr.Zero || handle == new IntPtr(-1))
        {
            AttachConsole(AttachParentProcess);
        }

        // UTF-8, not the OEM code page: the tests print what the application wrote, é and 日本 too
        try
        {
            Console.OutputEncoding = new System.Text.UTF8Encoding(false);
        }
        catch (System.IO.IOException)
        {
            // No console, only a redirected stdout: the encoding is the writer's
            Console.SetOut(new System.IO.StreamWriter(Console.OpenStandardOutput(), new System.Text.UTF8Encoding(false)) { AutoFlush = true });
        }
    }

    /// <summary>What a key types on the current layout without modifiers, for OEM keys: ù, not ;.</summary>
    public static string? LayoutCharacter(Key key)
    {
        var virtualKey = KeyInterop.VirtualKeyFromKey(key);
        var mapped = MapVirtualKey((uint)virtualKey, MapVkToChar);
        // The high bit marks a dead key; its character is still the one shown on the key.
        var character = (char)(mapped & 0x7FFFFFFF);
        return character == 0 ? null : character.ToString();
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct FlashInfo
    {
        public uint Size;
        public IntPtr Window;
        public uint Flags;
        public uint Count;
        public uint Timeout;
    }

    [DllImport("dwmapi.dll")]
    private static extern int DwmSetWindowAttribute(IntPtr window, int attribute, ref int value, int size);

    [DllImport("user32.dll")]
    private static extern bool FlashWindowEx(ref FlashInfo info);

    [DllImport("kernel32.dll")]
    private static extern bool AttachConsole(int process);

    [DllImport("kernel32.dll")]
    private static extern IntPtr GetStdHandle(int handle);

    [DllImport("user32.dll")]
    private static extern uint MapVirtualKey(uint code, uint type);
}

/// <summary>WPF key events to Tui key ids.</summary>
internal static class Input
{
    /// <summary>Alt+X arrives as Key.System, an IME or a dead key as their own: the key that was pressed.</summary>
    public static Key ActualKey(KeyEventArgs e) => e.Key switch
    {
        Key.System => e.SystemKey,
        Key.ImeProcessed => e.ImeProcessedKey,
        Key.DeadCharProcessed => e.DeadCharProcessedKey,
        _ => e.Key,
    };

    public static string? KeyId(Key key, WpfModifiers modifiers)
    {
        var oem = (int)key >= WpfKey.OemSemicolon && (int)key <= WpfKey.OemBackslash;
        return KeyIds.FromKey((int)key, modifiers, oem ? Native.LayoutCharacter(key) : null);
    }

    /// <summary>The copied key table against this WPF's enum; empty when every value matches.</summary>
    public static string CheckKeyTable()
    {
        foreach (var (name, value) in WpfKey.Named)
        {
            if (!Enum.TryParse<Key>(name, out var key) || (int)key != value)
            {
                return $"Key.{name} is {(int)key} in WPF, {value} in the table";
            }
        }

        return "";
    }
}
