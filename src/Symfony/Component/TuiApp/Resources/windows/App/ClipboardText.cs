using System;
using System.Runtime.InteropServices;
using System.Windows;

namespace TuiAppHost;

/// <summary>The clipboard's text as a paste sends it, read without letting a busy clipboard throw.</summary>
internal static class ClipboardText
{
    /// <summary>
    /// The clipboard's text, null when it holds none, an empty one, or cannot be read: another process or thread has it
    /// open (CLIPBRD_E_CANT_OPEN, after WPF's own tries), which <paramref name="busy"/> is told.
    /// </summary>
    public static string? ToPaste(Action<string>? busy = null)
    {
        try
        {
            return Clipboard.ContainsText() && Clipboard.GetText() is { Length: > 0 } text ? text : null;
        }
        catch (COMException exception)
        {
            busy?.Invoke(exception.Message);
            return null;
        }
    }
}
