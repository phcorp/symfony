using System.Text;

namespace TuiAppHost.Core;

/// <summary>
/// Values of System.Windows.Input.Key, copied so that this project needs no WPF. A test reads the
/// WPF reference assembly and checks every one of them.
/// </summary>
public static class WpfKey
{
    public const int None = 0, Back = 2, Tab = 3, Enter = 6, Escape = 13, Space = 18, PageUp = 19, PageDown = 20,
        End = 21, Home = 22, Left = 23, Up = 24, Right = 25, Down = 26, Insert = 31, Delete = 32,
        D0 = 34, D9 = 43, A = 44, Z = 69, LWin = 70, RWin = 71, Apps = 72,
        NumPad0 = 74, NumPad9 = 83, Multiply = 84, Add = 85, Separator = 86, Subtract = 87, Decimal = 88, Divide = 89,
        F1 = 90, F12 = 101, F24 = 113,
        LeftShift = 116, RightShift = 117, LeftCtrl = 118, RightCtrl = 119, LeftAlt = 120, RightAlt = 121,
        OemSemicolon = 140, OemPlus = 141, OemComma = 142, OemMinus = 143, OemPeriod = 144, OemQuestion = 145,
        OemTilde = 146, OemOpenBrackets = 149, OemPipe = 150, OemCloseBrackets = 151, OemQuotes = 152, Oem8 = 153,
        OemBackslash = 154, ImeProcessed = 155, System = 156, DeadCharProcessed = 172;

    /// <summary>The names, for the test against the WPF assembly.</summary>
    public static IReadOnlyDictionary<string, int> Named { get; } = typeof(WpfKey)
        .GetFields()
        .Where(field => field.IsLiteral)
        .ToDictionary(field => field.Name, field => (int)field.GetRawConstantValue()!);
}

/// <summary>Values of System.Windows.Input.ModifierKeys.</summary>
[Flags]
public enum WpfModifiers
{
    None = 0,
    Alt = 1,
    Control = 2,
    Shift = 4,
    Windows = 8,
}

/// <summary>Tui key ids (ctrl+shift+alt+super+key) from WPF keys, and back for menu shortcuts.</summary>
public static class KeyIds
{
    private static readonly Dictionary<int, string> Named = new()
    {
        [WpfKey.Enter] = "enter",
        [WpfKey.Escape] = "escape",
        [WpfKey.Tab] = "tab",
        [WpfKey.Space] = "space",
        [WpfKey.Back] = "backspace",
        [WpfKey.Delete] = "delete",
        [WpfKey.Insert] = "insert",
        [WpfKey.Home] = "home",
        [WpfKey.End] = "end",
        [WpfKey.PageUp] = "page_up",
        [WpfKey.PageDown] = "page_down",
        [WpfKey.Up] = "up",
        [WpfKey.Down] = "down",
        [WpfKey.Left] = "left",
        [WpfKey.Right] = "right",
    };

    /// <summary>The character of each key on a US layout, used when the real layout cannot say.</summary>
    private static readonly Dictionary<int, string> UsCharacters = new()
    {
        [WpfKey.OemSemicolon] = ";",
        [WpfKey.OemPlus] = "=",
        [WpfKey.OemComma] = ",",
        [WpfKey.OemMinus] = "-",
        [WpfKey.OemPeriod] = ".",
        [WpfKey.OemQuestion] = "/",
        [WpfKey.OemTilde] = "`",
        [WpfKey.OemOpenBrackets] = "[",
        [WpfKey.OemPipe] = "\\",
        [WpfKey.OemCloseBrackets] = "]",
        [WpfKey.OemQuotes] = "'",
        [WpfKey.OemBackslash] = "\\",
        [WpfKey.Multiply] = "*",
        [WpfKey.Add] = "+",
        [WpfKey.Subtract] = "-",
        [WpfKey.Decimal] = ".",
        [WpfKey.Divide] = "/",
    };

    private static readonly int[] ModifierKeys =
    {
        WpfKey.LeftShift, WpfKey.RightShift, WpfKey.LeftCtrl, WpfKey.RightCtrl, WpfKey.LeftAlt, WpfKey.RightAlt, WpfKey.LWin, WpfKey.RWin,
    };

    public static string Prefix(WpfModifiers modifiers)
    {
        var prefix = new StringBuilder();
        if (modifiers.HasFlag(WpfModifiers.Control))
        {
            prefix.Append("ctrl+");
        }

        if (modifiers.HasFlag(WpfModifiers.Shift))
        {
            prefix.Append("shift+");
        }

        if (modifiers.HasFlag(WpfModifiers.Alt))
        {
            prefix.Append("alt+");
        }

        if (modifiers.HasFlag(WpfModifiers.Windows))
        {
            prefix.Append("super+");
        }

        return prefix.ToString();
    }

    /// <summary>The key without modifiers: enter, f5, a, 7, ; …; null for a modifier alone or a key Tui has no id for.</summary>
    /// <param name="layoutCharacter">what the key types on the current layout without modifiers, if known</param>
    public static string? BaseId(int key, string? layoutCharacter = null)
    {
        if (Named.TryGetValue(key, out var name))
        {
            return name;
        }

        if (key >= WpfKey.A && key <= WpfKey.Z)
        {
            return ((char)('a' + key - WpfKey.A)).ToString();
        }

        if (key >= WpfKey.D0 && key <= WpfKey.D9)
        {
            return ((char)('0' + key - WpfKey.D0)).ToString();
        }

        if (key >= WpfKey.NumPad0 && key <= WpfKey.NumPad9)
        {
            return ((char)('0' + key - WpfKey.NumPad0)).ToString();
        }

        if (key >= WpfKey.F1 && key <= WpfKey.F24)
        {
            return "f" + (key - WpfKey.F1 + 1);
        }

        if (ModifierKeys.Contains(key))
        {
            return null;
        }

        if (!string.IsNullOrEmpty(layoutCharacter) && !char.IsControl(layoutCharacter[0]))
        {
            return layoutCharacter.ToLowerInvariant();
        }

        return UsCharacters.TryGetValue(key, out var character) ? character : null;
    }

    public static string? FromKey(int key, WpfModifiers modifiers, string? layoutCharacter = null) =>
        BaseId(key, layoutCharacter) is { } id ? Prefix(modifiers) + id : null;

    /// <summary>
    /// Whether the key, with these modifiers, types text that a TextInput event will carry: such keys
    /// are not sent from KeyDown. Ctrl+Alt is AltGr on many layouts, so it may type too.
    /// </summary>
    public static bool TypesText(int key, WpfModifiers modifiers)
    {
        var ctrlAlt = WpfModifiers.Control | WpfModifiers.Alt;
        var held = modifiers & (ctrlAlt | WpfModifiers.Windows);
        if (held != WpfModifiers.None && held != ctrlAlt)
        {
            return false;
        }

        return key == WpfKey.Space
            || key >= WpfKey.D0 && key <= WpfKey.D9
            || key >= WpfKey.A && key <= WpfKey.Z
            || key >= WpfKey.NumPad0 && key <= WpfKey.Divide && key != WpfKey.Separator
            || key >= WpfKey.OemSemicolon && key <= WpfKey.OemBackslash;
    }

    /// <summary>The key id of typed text: the lowercase character, with shift+ for an uppercase letter. Null for control characters.</summary>
    public static string? FromText(string text)
    {
        if (text.Length == 0 || char.IsControl(text[0]))
        {
            return null;
        }

        if (text == " ")
        {
            return "space";
        }

        var lower = text.ToLowerInvariant();
        return lower != text && text.ToUpperInvariant() == text ? "shift+" + lower : lower;
    }

    /// <summary>
    /// Text an input method committed (a dead key and a letter, a word of an IME, an emoji), as the
    /// keys that type it: one per character (text element), with the id of <see cref="FromText"/> and
    /// the character as text. Control characters are dropped.
    /// </summary>
    public static IReadOnlyList<(string Key, string Text)> Committed(string text)
    {
        var keys = new List<(string Key, string Text)>();
        var elements = System.Globalization.StringInfo.GetTextElementEnumerator(text);
        while (elements.MoveNext())
        {
            var element = elements.GetTextElement();
            if (FromText(element) is { } id)
            {
                keys.Add((id, element));
            }
        }

        return keys;
    }

    /// <summary>A Tui key id back to a WPF key and modifiers, for a KeyBinding; null when it has no single key.</summary>
    public static (int Key, WpfModifiers Modifiers)? ToWpf(string id)
    {
        var (modifiers, rest) = SplitModifiers(id);
        if (rest.Length == 0)
        {
            return null;
        }

        foreach (var (key, name) in Named)
        {
            if (name == rest)
            {
                return (key, modifiers);
            }
        }

        if (rest.Length == 1 && rest[0] is >= 'a' and <= 'z')
        {
            return (WpfKey.A + rest[0] - 'a', modifiers);
        }

        if (rest.Length == 1 && rest[0] is >= '0' and <= '9')
        {
            return (WpfKey.D0 + rest[0] - '0', modifiers);
        }

        if (rest[0] == 'f' && int.TryParse(rest.AsSpan(1), out var number) && number is >= 1 and <= 24)
        {
            return (WpfKey.F1 + number - 1, modifiers);
        }

        foreach (var (key, character) in UsCharacters)
        {
            if (character == rest && key >= WpfKey.OemSemicolon)
            {
                return (key, modifiers);
            }
        }

        return null;
    }

    /// <summary>A shortcut as Windows menus show it: ctrl+shift+s is Ctrl+Shift+S.</summary>
    public static string Display(string id)
    {
        var (modifiers, rest) = SplitModifiers(id);
        var parts = new List<string>();
        if (modifiers.HasFlag(WpfModifiers.Control))
        {
            parts.Add("Ctrl");
        }

        if (modifiers.HasFlag(WpfModifiers.Shift))
        {
            parts.Add("Shift");
        }

        if (modifiers.HasFlag(WpfModifiers.Alt))
        {
            parts.Add("Alt");
        }

        if (modifiers.HasFlag(WpfModifiers.Windows))
        {
            parts.Add("Win");
        }

        parts.Add(rest switch
        {
            "page_up" => "PgUp",
            "page_down" => "PgDn",
            "escape" => "Esc",
            "delete" => "Del",
            "insert" => "Ins",
            "backspace" => "Backspace",
            _ when rest.Length == 1 => rest.ToUpperInvariant(),
            _ when rest.Length > 0 => char.ToUpperInvariant(rest[0]) + rest[1..],
            _ => rest,
        });
        return string.Join("+", parts);
    }

    private static (WpfModifiers Modifiers, string Key) SplitModifiers(string id)
    {
        var modifiers = WpfModifiers.None;
        var rest = id;
        while (true)
        {
            // "ctrl++" is ctrl and the plus key: a prefix needs something after it.
            var plus = rest.IndexOf('+');
            if (plus <= 0 || plus == rest.Length - 1)
            {
                break;
            }

            var flag = rest[..plus] switch
            {
                "ctrl" => WpfModifiers.Control,
                "shift" => WpfModifiers.Shift,
                "alt" => WpfModifiers.Alt,
                "super" => WpfModifiers.Windows,
                _ => (WpfModifiers?)null,
            };
            if (flag is null)
            {
                break;
            }

            modifiers |= flag.Value;
            rest = rest[(plus + 1)..];
        }

        return (modifiers, rest);
    }
}
