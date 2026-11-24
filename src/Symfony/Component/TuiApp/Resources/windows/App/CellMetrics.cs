using System.Linq;
using System.Windows;
using System.Windows.Media;

namespace TuiAppHost;

/// <summary>The canvas font and the size of one of its cells, in device-independent pixels.</summary>
public sealed class CellMetrics
{
    public const string Preferred = "Cascadia Mono";
    public const string Fallback = "Consolas";

    private CellMetrics(FontFamily family, double emSize, double width, double height, double baseline)
    {
        Family = family;
        EmSize = emSize;
        Width = width;
        Height = height;
        Baseline = baseline;
        Typeface = new Typeface(family, FontStyles.Normal, FontWeights.Normal, FontStretches.Normal);
    }

    public FontFamily Family { get; }

    public Typeface Typeface { get; }

    /// <summary>The font size in DIPs: canvasFont.size is in points.</summary>
    public double EmSize { get; }

    public double Width { get; }

    public double Height { get; }

    public double Baseline { get; }

    /// <param name="family">canvasFont.family; null for Cascadia Mono, else Consolas</param>
    public static CellMetrics For(string? family, double points)
    {
        var name = family is { Length: > 0 } && Installed(family) ? family : Installed(Preferred) ? Preferred : Fallback;
        var fontFamily = new FontFamily(name);
        var emSize = points * 96.0 / 72.0;
        var typeface = new Typeface(fontFamily, FontStyles.Normal, FontWeights.Normal, FontStretches.Normal);
        double width;
        if (typeface.TryGetGlyphTypeface(out var glyphs) && glyphs.CharacterToGlyphMap.TryGetValue('M', out var glyph))
        {
            width = glyphs.AdvanceWidths[glyph] * emSize;
        }
        else
        {
            var text = new FormattedText(new string('M', 10), System.Globalization.CultureInfo.InvariantCulture, FlowDirection.LeftToRight, typeface, emSize, Brushes.Black, 1.0);
            width = text.WidthIncludingTrailingWhitespace / 10;
        }

        var height = fontFamily.LineSpacing * emSize;
        return new CellMetrics(fontFamily, emSize, width, height, fontFamily.Baseline * emSize);
    }

    public static bool Installed(string family) =>
        Fonts.SystemFontFamilies.Any(f => f.Source.Equals(family, System.StringComparison.OrdinalIgnoreCase)
            || f.FamilyNames.Values.Any(n => n.Equals(family, System.StringComparison.OrdinalIgnoreCase)));
}
