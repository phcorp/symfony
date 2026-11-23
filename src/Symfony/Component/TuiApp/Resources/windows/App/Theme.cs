using System;
using System.Globalization;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using Microsoft.Win32;

namespace TuiAppHost;

/// <summary>Light or dark: the colours the host paints with, and what it lays over the system colours.</summary>
public sealed class Theme
{
    private Theme(bool dark)
    {
        Dark = dark;
        Background = Frozen(dark ? "#202020" : "#f9f9f9");
        Surface = Frozen(dark ? "#2b2b2b" : "#ffffff");
        Text = Frozen(dark ? "#e6e6e6" : "#1b1b1b");
        Secondary = Frozen(dark ? "#a6a6a6" : "#5f5f5f");
        Border = Frozen(dark ? "#4a4a4a" : "#c8c8c8");
        Accent = Frozen(dark ? "#4cc2ff" : "#005fb8");
        Selection = Frozen(dark ? "#264f78" : "#cce4f7");
    }

    public static Theme Light { get; } = new(false);

    public static Theme DarkTheme { get; } = new(true);

    public bool Dark { get; }

    public string Name => Dark ? "dark" : "light";

    public Brush Background { get; }

    public Brush Surface { get; }

    public Brush Text { get; }

    public Brush Secondary { get; }

    public Brush Border { get; }

    public Brush Accent { get; }

    public Brush Selection { get; }

    /// <summary>window.theme: system follows AppsUseLightTheme.</summary>
    public static Theme For(string setting) => setting switch
    {
        "dark" => DarkTheme,
        "light" => Light,
        _ => SystemIsDark() ? DarkTheme : Light,
    };

    public static bool SystemIsDark()
    {
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize");
            return key?.GetValue("AppsUseLightTheme") is int value && value == 0;
        }
        catch (Exception exception) when (exception is System.Security.SecurityException or UnauthorizedAccessException)
        {
            return false;
        }
    }

    /// <summary>The system colours the stock control templates read, in this theme's colours.</summary>
    public void Apply(ResourceDictionary resources)
    {
        resources[SystemColors.WindowBrushKey] = Surface;
        resources[SystemColors.WindowTextBrushKey] = Text;
        resources[SystemColors.ControlBrushKey] = Background;
        resources[SystemColors.ControlTextBrushKey] = Text;
        resources[SystemColors.GrayTextBrushKey] = Secondary;
        resources[SystemColors.HighlightBrushKey] = Selection;
        resources[SystemColors.HighlightTextBrushKey] = Text;
        resources[SystemColors.InactiveSelectionHighlightBrushKey] = Selection;
        resources[SystemColors.InactiveSelectionHighlightTextBrushKey] = Text;
        resources[SystemColors.MenuBrushKey] = Surface;
        resources[SystemColors.MenuBarBrushKey] = Background;
        resources[SystemColors.MenuTextBrushKey] = Text;
        resources[SystemColors.ControlLightBrushKey] = Border;
        resources[SystemColors.ControlDarkBrushKey] = Border;

        // The stock templates (Aero2) paint some chrome in fixed light colours, whatever the system colours: in the
        // dark, their text stays dark (buttons, the arrows of a number field, a pop-up, tab headers), and what takes
        // a background (a list box, a tab's page) takes the surface
        foreach (var type in new[] { typeof(System.Windows.Controls.Button), typeof(System.Windows.Controls.Primitives.RepeatButton), typeof(System.Windows.Controls.ComboBox), typeof(System.Windows.Controls.TabItem), typeof(System.Windows.Controls.ComboBoxItem), typeof(System.Windows.Controls.ListBox), typeof(System.Windows.Controls.TabControl) })
        {
            resources.Remove(type);
        }

        if (Dark)
        {
            var chromeText = Frozen("#1b1b1b");
            foreach (var type in new[] { typeof(System.Windows.Controls.Button), typeof(System.Windows.Controls.Primitives.RepeatButton), typeof(System.Windows.Controls.ComboBox), typeof(System.Windows.Controls.TabItem) })
            {
                resources[type] = Styled(type, (Control.ForegroundProperty, chromeText));
            }

            // A pop-up's list opens on the window's colour
            resources[typeof(System.Windows.Controls.ComboBoxItem)] = Styled(typeof(System.Windows.Controls.ComboBoxItem), (Control.ForegroundProperty, Text));
            resources[typeof(System.Windows.Controls.ListBox)] = Styled(typeof(System.Windows.Controls.ListBox), (Control.BackgroundProperty, Surface), (Control.BorderBrushProperty, Border));
            resources[typeof(System.Windows.Controls.TabControl)] = Styled(typeof(System.Windows.Controls.TabControl), (Control.BackgroundProperty, Surface), (Control.BorderBrushProperty, Border));
        }
    }

    private static Style Styled(Type type, params (DependencyProperty Property, object Value)[] setters)
    {
        var style = new Style(type);
        foreach (var (property, value) in setters)
        {
            style.Setters.Add(new Setter(property, value));
        }

        style.Seal();
        return style;
    }

    /// <summary>#rrggbb to a brush; null when it is not one.</summary>
    public static Brush? Brush(string? value)
    {
        if (value is not { Length: 7 } || value[0] != '#' || !int.TryParse(value.AsSpan(1), NumberStyles.HexNumber, CultureInfo.InvariantCulture, out var rgb))
        {
            return null;
        }

        var brush = new SolidColorBrush(Color.FromRgb((byte)(rgb >> 16), (byte)(rgb >> 8), (byte)rgb));
        brush.Freeze();
        return brush;
    }

    private static Brush Frozen(string value) => Brush(value)!;
}
