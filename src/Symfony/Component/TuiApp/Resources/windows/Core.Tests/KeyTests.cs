using System.Reflection.Metadata;
using System.Reflection.PortableExecutable;
using Xunit;

namespace TuiAppHost.Core.Tests;

public class KeyTests
{
    [Theory]
    [InlineData(WpfKey.Enter, WpfModifiers.None, "enter")]
    [InlineData(WpfKey.Escape, WpfModifiers.None, "escape")]
    [InlineData(WpfKey.Tab, WpfModifiers.Shift, "shift+tab")]
    [InlineData(WpfKey.Space, WpfModifiers.None, "space")]
    [InlineData(WpfKey.Back, WpfModifiers.None, "backspace")]
    [InlineData(WpfKey.Delete, WpfModifiers.None, "delete")]
    [InlineData(WpfKey.Insert, WpfModifiers.None, "insert")]
    [InlineData(WpfKey.Home, WpfModifiers.None, "home")]
    [InlineData(WpfKey.End, WpfModifiers.None, "end")]
    [InlineData(WpfKey.PageUp, WpfModifiers.None, "page_up")]
    [InlineData(WpfKey.PageDown, WpfModifiers.None, "page_down")]
    [InlineData(WpfKey.Up, WpfModifiers.None, "up")]
    [InlineData(WpfKey.Down, WpfModifiers.Alt, "alt+down")]
    [InlineData(WpfKey.Left, WpfModifiers.None, "left")]
    [InlineData(WpfKey.Right, WpfModifiers.Control | WpfModifiers.Shift, "ctrl+shift+right")]
    [InlineData(WpfKey.F1, WpfModifiers.None, "f1")]
    [InlineData(WpfKey.F12, WpfModifiers.None, "f12")]
    [InlineData(WpfKey.A + 18, WpfModifiers.Control, "ctrl+s")]
    [InlineData(WpfKey.D0 + 7, WpfModifiers.Control, "ctrl+7")]
    [InlineData(WpfKey.NumPad0 + 3, WpfModifiers.None, "3")]
    [InlineData(WpfKey.OemComma, WpfModifiers.Control, "ctrl+,")]
    [InlineData(WpfKey.A, WpfModifiers.Control | WpfModifiers.Shift | WpfModifiers.Alt | WpfModifiers.Windows, "ctrl+shift+alt+super+a")]
    public void MapsWpfKeysToTuiIds(int key, WpfModifiers modifiers, string expected)
    {
        Assert.Equal(expected, KeyIds.FromKey(key, modifiers));
    }

    [Fact]
    public void ModifiersAloneHaveNoId()
    {
        foreach (var key in new[] { WpfKey.LeftShift, WpfKey.RightCtrl, WpfKey.LeftAlt, WpfKey.LWin })
        {
            Assert.Null(KeyIds.FromKey(key, WpfModifiers.Shift));
        }
    }

    [Fact]
    public void TheLayoutNamesOemKeys()
    {
        // ; on a US keyboard is ù on a French one.
        Assert.Equal("ù", KeyIds.FromKey(WpfKey.OemQuotes, WpfModifiers.None, "ù"));
        Assert.Equal("ctrl+;", KeyIds.FromKey(WpfKey.OemSemicolon, WpfModifiers.Control));
    }

    [Theory]
    [InlineData("a", "a")]
    [InlineData("A", "shift+a")]
    [InlineData("é", "é")]
    [InlineData("É", "shift+é")]
    [InlineData("!", "!")]
    [InlineData(" ", "space")]
    [InlineData("\r", null)]
    [InlineData("\u001b", null)]
    public void TypedTextHasTheLowercaseId(string text, string? expected)
    {
        Assert.Equal(expected, KeyIds.FromText(text));
    }

    [Fact]
    public void CommittedTextIsOneKeyPerCharacter()
    {
        Assert.Equal(new[] { ("é", "é") }, KeyIds.Committed("é"));
        Assert.Equal(new[] { ("日", "日"), ("本", "本") }, KeyIds.Committed("日本"));
        Assert.Equal(new[] { ("shift+é", "É"), ("space", " "), ("a", "a") }, KeyIds.Committed("É a"));
        // E and a combining acute is an uppercase letter, as É is (macOS and Linux say so too)
        Assert.Equal(new[] { ("shift+e\u0301", "E\u0301") }, KeyIds.Committed("E\u0301"));
        // A grapheme of several code points stays one key: a flag, a family, an accent combined
        Assert.Equal(new[] { ("🇫🇷", "🇫🇷"), ("👨‍👩‍👧", "👨‍👩‍👧"), ("e\u0301", "e\u0301") }, KeyIds.Committed("🇫🇷👨‍👩‍👧e\u0301"));
        Assert.Empty(KeyIds.Committed("\n\t"));
        Assert.Empty(KeyIds.Committed(""));
    }

    [Fact]
    public void TextKeysWaitForTextInput()
    {
        Assert.True(KeyIds.TypesText(WpfKey.A, WpfModifiers.Shift));
        Assert.True(KeyIds.TypesText(WpfKey.D0 + 2, WpfModifiers.Control | WpfModifiers.Alt));
        Assert.False(KeyIds.TypesText(WpfKey.A, WpfModifiers.Control));
        Assert.False(KeyIds.TypesText(WpfKey.Enter, WpfModifiers.None));
        Assert.False(KeyIds.TypesText(WpfKey.F1, WpfModifiers.None));
    }

    [Theory]
    [InlineData("ctrl+s", WpfKey.A + 18, WpfModifiers.Control, "Ctrl+S")]
    [InlineData("ctrl+shift+page_down", WpfKey.PageDown, WpfModifiers.Control | WpfModifiers.Shift, "Ctrl+Shift+PgDn")]
    [InlineData("f5", WpfKey.F1 + 4, WpfModifiers.None, "F5")]
    [InlineData("alt+enter", WpfKey.Enter, WpfModifiers.Alt, "Alt+Enter")]
    [InlineData("ctrl+,", WpfKey.OemComma, WpfModifiers.Control, "Ctrl+,")]
    [InlineData("super+1", WpfKey.D0 + 1, WpfModifiers.Windows, "Win+1")]
    public void ShortcutsGoBackToWpfGestures(string id, int key, WpfModifiers modifiers, string display)
    {
        Assert.Equal((key, modifiers), KeyIds.ToWpf(id));
        Assert.Equal(display, KeyIds.Display(id));
        Assert.Equal(id, KeyIds.FromKey(key, modifiers));
    }

    [Fact]
    public void ShortcutsWithoutAKeyHaveNoGesture()
    {
        Assert.Null(KeyIds.ToWpf("ctrl+"));
        Assert.Null(KeyIds.ToWpf("ctrl+nothing"));
    }

    /// <summary>The copied values against System.Windows.Input.Key in the WPF reference assembly.</summary>
    [Fact]
    public void CopiedValuesMatchWpf()
    {
        var assembly = WpfReference("WindowsBase.dll");
        using var pe = new PEReader(File.OpenRead(assembly));
        var metadata = pe.GetMetadataReader();
        var key = metadata.TypeDefinitions.Select(metadata.GetTypeDefinition)
            .Single(t => metadata.GetString(t.Namespace) == "System.Windows.Input" && metadata.GetString(t.Name) == "Key");
        var values = new Dictionary<string, int>();
        foreach (var handle in key.GetFields())
        {
            var field = metadata.GetFieldDefinition(handle);
            var constant = field.GetDefaultValue();
            if (!constant.IsNil)
            {
                values[metadata.GetString(field.Name)] = metadata.GetBlobReader(metadata.GetConstant(constant).Value).ReadInt32();
            }
        }

        Assert.True(values.Count > 150, "Key has " + values.Count + " values");
        foreach (var (name, value) in WpfKey.Named)
        {
            Assert.True(values.TryGetValue(name, out var wpf), "no Key." + name);
            Assert.True(wpf == value, $"Key.{name} is {wpf} in WPF, {value} here");
        }
    }

    internal static string WpfReference(string file)
    {
        var root = Environment.GetEnvironmentVariable("NUGET_PACKAGES")
            ?? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), ".nuget", "packages");
        var found = Directory.Exists(Path.Combine(root, "microsoft.windowsdesktop.app.ref"))
            ? Directory.GetFiles(Path.Combine(root, "microsoft.windowsdesktop.app.ref"), file, SearchOption.AllDirectories).OrderBy(p => p).LastOrDefault()
            : null;
        return found ?? throw new FileNotFoundException("no Microsoft.WindowsDesktop.App.Ref package under " + root + " (restored by this project's PackageDownload)");
    }
}
