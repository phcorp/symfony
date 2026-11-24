using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text.Json.Nodes;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using TuiAppHost.Core;

namespace TuiAppHost;

/// <summary>
/// The canvas kind: lines of styled runs drawn on a cell grid in the canvas font. With the focus, every
/// key press goes to the application as key, paste as paste. It takes input methods: what is being
/// composed is drawn here only, at the cursor; the text committed goes out as typed keys.
/// </summary>
/// <remarks>
/// The focus goes to <see cref="Entry"/>, an invisible TextBox over the cursor cell, not to the canvas: an
/// input method asks the text store of the focused element where the composition is, to open its candidate
/// window there, and WPF's store for an element that is not a TextBox answers with an empty rectangle (the
/// window then opens at the screen's top left corner). The TextBox holds only what is being composed; its
/// keys and its commits go out as the canvas's.
/// </remarks>
public sealed class CanvasView : FrameworkElement
{
    private readonly CellMetrics cell;
    private readonly Theme theme;
    private readonly Func<string, bool> isShortcut;
    private readonly TextBox input;
    private List<List<JsonObject>> lines = new();
    private int columns;
    private (int Row, int Column)? cursor;
    private string preedit = "";
    private bool clearing;

    /// <summary>
    /// How much of the TextBox, from its start, an input method committed (and went out as keys): what follows it is
    /// being composed. One key may commit and start a new composition (Korean 한 then ㄱ, Japanese typed on after a
    /// conversion): the commit then stays in the TextBox, under the composition, until that one ends.
    /// </summary>
    private int committed
    {
        get => committedLength;
        set
        {
            if (value != committedLength)
            {
                committedLength = value;
                // The TextBox moves left by what the commit takes in it: the composition after it starts at the cell
                InvalidateArrange();
            }
        }
    }

    private int committedLength;

    public CanvasView(CellMetrics cell, Theme theme, Func<string, bool> isShortcut)
    {
        this.cell = cell;
        this.theme = theme;
        this.isShortcut = isShortcut;
        Focusable = false;
        ClipToBounds = true;
        SnapsToDevicePixels = true;
        TextOptions.SetTextFormattingMode(this, TextFormattingMode.Display);
        input = new TextBox
        {
            FontFamily = cell.Family,
            FontSize = cell.EmSize,
            Padding = new Thickness(0),
            Margin = new Thickness(0),
            BorderThickness = new Thickness(0),
            Background = Brushes.Transparent,
            Foreground = Brushes.Transparent,
            CaretBrush = Brushes.Transparent,
            SelectionOpacity = 0,
            FocusVisualStyle = null,
            IsHitTestVisible = false,
            IsUndoEnabled = false,
            AcceptsReturn = false,
            AcceptsTab = false,
            TextWrapping = TextWrapping.NoWrap,
            VerticalContentAlignment = VerticalAlignment.Top,
        };
        InputMethod.SetIsInputMethodEnabled(input, true);
        // Not the TextBox's editing menu (the menu key or Shift+F10 would open it): the canvas's menu is the
        // application's, a contextmenu message. A TextBox with no menu of its own makes and opens that one itself; given
        // an empty one, it does not, and that one is never opened either
        input.ContextMenu = new ContextMenu();
        input.ContextMenuOpening += (_, e) => e.Handled = true;
        AddVisualChild(input);
        input.PreviewKeyDown += (_, e) => Pressed(e);
        // Composition comes as TextInputStart and TextInputUpdate with the text being composed, which an input
        // method's text store puts in the TextBox too (dead keys only say it), then TextInput with the commit.
        input.AddHandler(TextCompositionManager.PreviewTextInputStartEvent, new TextCompositionEventHandler((_, e) => Composing(e)), true);
        input.AddHandler(TextCompositionManager.PreviewTextInputUpdateEvent, new TextCompositionEventHandler((_, e) => Composing(e)), true);
        input.AddHandler(TextCompositionManager.PreviewTextInputEvent, new TextCompositionEventHandler((_, e) => Committed(e)), true);
        input.TextChanged += (_, _) =>
        {
            if (clearing)
            {
                return;
            }

            Compose(Composed);
            // A composition after a commit taken back (Escape): the commit can go now
            if (committed > 0 && input.Text.Length == committed)
            {
                Dispatcher.BeginInvoke(System.Windows.Threading.DispatcherPriority.Input, Clear);
            }
        };
        input.IsKeyboardFocusedChanged += (_, _) =>
        {
            if (!input.IsKeyboardFocused)
            {
                // The input method ends its composition with the focus
                committed = input.Text.Length;
                Clear();
                Compose("");
            }

            InvalidateVisual();
        };
        DataObject.AddPastingHandler(input, (_, e) => e.CancelCommand());
    }

    public event Action<string, string?>? Key;

    public event Action<string>? Paste;

    /// <summary>What the mouse does on the canvas (not on the controls over it), node left 0.</summary>
    public event Action<PointerMessage>? Pointer;

    private readonly PointerTracker pointer = new();

    /// <summary>A menu shortcut pressed in the canvas: the TextBox that takes its keys must not use it.</summary>
    public event Action<string>? Shortcut;

    /// <summary>What takes the keyboard focus for the canvas, and the input methods: see the remarks.</summary>
    public TextBox Entry => input;

    public Brush? Foreground { get; set; }

    public Brush? Background { get; set; }

    /// <summary>What the input method is composing, drawn at the cursor and not sent.</summary>
    public string Preedit => preedit;

    /// <summary>An input method composes in the canvas: its keys are the input method's.</summary>
    public bool IsComposing => preedit.Length > 0;

    /// <summary>
    /// The cells of the controls shown over the canvas, [row, column, columns, rows]: no character is drawn there (a
    /// glyph like ─ or → reaches past its cell, into the one before the control). Drawn again as they change.
    /// </summary>
    public IReadOnlyList<(int Row, int Column, int Columns, int Rows)> Covered
    {
        get => covered;
        set
        {
            if (!value.SequenceEqual(covered))
            {
                covered = value;
                InvalidateVisual();
            }
        }
    }

    private IReadOnlyList<(int Row, int Column, int Columns, int Rows)> covered = Array.Empty<(int, int, int, int)>();

    /// <summary>Whether a character is drawn in a cell: not under a control.</summary>
    public bool Draws(int row, int column) => !covered.Any(c => row >= c.Row && row < c.Row + c.Rows && column >= c.Column && column < c.Column + c.Columns);

    /// <summary>The text drawn, line by line: what the self-test and accessibility read.</summary>
    public string Text => string.Join("\n", lines.Select(line => string.Concat(line.Select(run => run["t"]?.GetValue<string>() ?? ""))));

    public void Update(JsonArray? lineProps, int? columnsProp, JsonArray? cursorProp)
    {
        lines = lineProps?.Select(line => (line as JsonArray)?.OfType<JsonObject>().ToList() ?? new List<JsonObject>()).ToList() ?? new();
        columns = columnsProp ?? 0;
        cursor = cursorProp is { Count: 2 } ? (cursorProp[0]!.GetValue<int>(), cursorProp[1]!.GetValue<int>()) : null;
        InvalidateMeasure();
        InvalidateArrange();
        InvalidateVisual();
    }

    protected override int VisualChildrenCount => 1;

    protected override Visual GetVisualChild(int index) => index == 0 ? input : throw new ArgumentOutOfRangeException(nameof(index));

    protected override Size ArrangeOverride(Size final)
    {
        // Over the cell where the preedit is drawn, to the right edge: where the input method's windows open
        // A commit the TextBox still holds, under a composition the same key started, is before it: the TextBox starts
        // that much to the left, so that the composition, and the input method's candidate list, are at the cell
        var (row, column) = PreeditCell;
        var x = column * cell.Width;
        var shift = CommittedWidth();
        input.Arrange(new Rect(x - shift, row * cell.Height, Math.Max(cell.Width, final.Width - x) + shift, cell.Height));
        return final;
    }

    /// <summary>How wide the TextBox draws what was committed in it: what the composition after it is shifted by.</summary>
    private double CommittedWidth()
    {
        if (committed <= 0 || committed > input.Text.Length)
        {
            return 0;
        }

        var formatted = new FormattedText(
            input.Text[..committed], CultureInfo.CurrentUICulture, FlowDirection.LeftToRight,
            new Typeface(input.FontFamily, input.FontStyle, input.FontWeight, input.FontStretch), input.FontSize, Brushes.Transparent,
            null, TextOptions.GetTextFormattingMode(input), VisualTreeHelper.GetDpi(this).PixelsPerDip);
        return formatted.WidthIncludingTrailingWhitespace;
    }

    /// <summary>
    /// Where the TextBox draws the text after what was committed (the composition's start), from the canvas's corner,
    /// less the TextBox's own inset before its first character: the cell's corner when the commit is made room for.
    /// </summary>
    public Point CompositionOrigin
    {
        get
        {
            var inset = input.GetRectFromCharacterIndex(0).TopLeft;
            var at = input.TranslatePoint(input.GetRectFromCharacterIndex(Math.Min(committed, input.Text.Length)).TopLeft, this);
            return new Point(at.X - inset.X, at.Y - inset.Y);
        }
    }

    protected override Size MeasureOverride(Size available)
    {
        input.Measure(new Size(double.PositiveInfinity, cell.Height));
        var widest = lines.Count == 0 ? 0 : lines.Max(line => line.Sum(run => Length(run["t"]?.GetValue<string>() ?? "")));
        var rows = Math.Max(lines.Count, cursor is { } c ? c.Row + 1 : 0);
        return new Size(Math.Max(columns, widest) * cell.Width, rows * cell.Height);
    }

    protected override void OnRender(DrawingContext context)
    {
        var dpi = VisualTreeHelper.GetDpi(this).PixelsPerDip;
        var foreground = Foreground ?? theme.Text;
        var background = Background ?? Brushes.Transparent;
        context.DrawRectangle(background, null, new Rect(RenderSize));
        for (var row = 0; row < lines.Count; row++)
        {
            var column = 0;
            foreach (var run in lines[row])
            {
                var text = run["t"]?.GetValue<string>() ?? "";
                var length = Length(text);
                var fg = Color(run, "fg") ?? foreground;
                var bg = Color(run, "bg");
                if (Flag(run, "r"))
                {
                    (fg, bg) = (bg ?? theme.Background, fg);
                }

                var origin = new Point(column * cell.Width, row * cell.Height);
                if (bg is not null)
                {
                    context.DrawRectangle(bg, null, new Rect(origin, new Size(length * cell.Width, cell.Height)));
                }

                if (Flag(run, "d"))
                {
                    fg = fg.Clone();
                    fg.Opacity = 0.6;
                }

                // The characters out of the cells of the controls over the canvas, in stretches between them
                foreach (var (at, stretch) in Stretches(row, column, text))
                {
                    var formatted = new FormattedText(
                        stretch, CultureInfo.CurrentUICulture, FlowDirection.LeftToRight,
                        new Typeface(cell.Family, Flag(run, "i") ? FontStyles.Italic : FontStyles.Normal, Flag(run, "b") ? FontWeights.Bold : FontWeights.Normal, FontStretches.Normal),
                        cell.EmSize, fg, dpi);
                    var decorations = new TextDecorationCollection();
                    if (Flag(run, "u"))
                    {
                        decorations.Add(TextDecorations.Underline);
                    }

                    if (Flag(run, "s"))
                    {
                        decorations.Add(TextDecorations.Strikethrough);
                    }

                    if (decorations.Count > 0)
                    {
                        formatted.SetTextDecorations(decorations);
                    }

                    context.DrawText(formatted, new Point(at * cell.Width, row * cell.Height));
                }

                column += length;
            }
        }

        if (preedit.Length > 0)
        {
            DrawPreedit(context, foreground, dpi);
        }
        else if (cursor is { } c)
        {
            var rect = new Rect(c.Column * cell.Width, c.Row * cell.Height, cell.Width, cell.Height);
            if (input.IsKeyboardFocused)
            {
                var block = foreground.Clone();
                block.Opacity = 0.5;
                context.DrawRectangle(block, null, rect);
            }
            else
            {
                context.DrawRectangle(null, new Pen(foreground, 1), new Rect(rect.X + 0.5, rect.Y + 0.5, rect.Width - 1, rect.Height - 1));
            }
        }
    }

    /// <summary>The text being composed, from the cursor cell (or the first one), underlined over the lines.</summary>
    private void DrawPreedit(DrawingContext context, Brush foreground, double dpi)
    {
        var (row, column) = PreeditCell;
        var origin = new Point(column * cell.Width, row * cell.Height);
        var formatted = new FormattedText(preedit, CultureInfo.CurrentUICulture, FlowDirection.LeftToRight, cell.Typeface, cell.EmSize, foreground, dpi);
        formatted.SetTextDecorations(TextDecorations.Underline);
        var width = Math.Max(formatted.WidthIncludingTrailingWhitespace, Length(preedit) * cell.Width);
        context.DrawRectangle(Background is SolidColorBrush { Color.A: 255 } ? Background : theme.Background, null, new Rect(origin, new Size(width, cell.Height)));
        context.DrawText(formatted, origin);
    }

    /// <summary>Where the text being composed starts: the cursor cell, else the first one.</summary>
    public (int Row, int Column) PreeditCell => cursor ?? (0, 0);

    private void Composing(TextCompositionEventArgs e) => Compose(e.TextComposition.CompositionText is { Length: > 0 } text ? text : Composed);

    /// <summary>What the TextBox holds after what was committed: the composition.</summary>
    private string Composed => committed <= input.Text.Length ? input.Text[committed..] : input.Text;

    private void Compose(string text)
    {
        if (text != preedit)
        {
            preedit = text;
            InvalidateVisual();
        }
    }

    /// <summary>
    /// Empties the TextBox of what an input method committed, once its edit is over or the focus left. Not while it
    /// composes after it: changing the TextBox would end that composition (the next commit, or the composition taken
    /// back, clears it).
    /// </summary>
    private void Clear()
    {
        if (input.Text.Length > committed)
        {
            return;
        }

        committed = 0;
        if (input.Text.Length == 0)
        {
            return;
        }

        clearing = true;
        try
        {
            input.Clear();
        }
        finally
        {
            clearing = false;
        }
    }

    protected override void OnMouseDown(MouseButtonEventArgs e)
    {
        base.OnMouseDown(e);
        // Focused first: its event focus goes before the press
        if (input.Focusable)
        {
            input.Focus();
            e.Handled = true;
        }

        if (ButtonId(e.ChangedButton) is { } button)
        {
            // The canvas's until it is released, wherever the pointer goes (the platform's implicit grab)
            CaptureMouse();
            Pointer?.Invoke(pointer.Press(button, CellAt(e), PointerModifiers()));
            e.Handled = true;
        }
    }

    protected override void OnMouseUp(MouseButtonEventArgs e)
    {
        base.OnMouseUp(e);
        if (ButtonId(e.ChangedButton) is not { } button || pointer.Held is null)
        {
            return;
        }

        // Sent before the capture goes: letting it go raises MouseLeave at once when the pointer is off the canvas or
        // over a control placed on it, and its leave comes after the release
        Pointer?.Invoke(pointer.Release(button, CellAt(e), PointerModifiers()));
        if (pointer.Held is null && IsMouseCaptured)
        {
            ReleaseMouseCapture();
        }

        e.Handled = true;
    }

    protected override void OnMouseMove(MouseEventArgs e)
    {
        base.OnMouseMove(e);
        // With no button held, only while the window is the active one
        if (pointer.Held is null && Window.GetWindow(this) is { IsActive: false })
        {
            return;
        }

        if (pointer.Move(CellAt(e), PointerModifiers()) is { } move)
        {
            Pointer?.Invoke(move);
        }
    }

    protected override void OnMouseLeave(MouseEventArgs e)
    {
        base.OnMouseLeave(e);
        if (pointer.Leave(PointerModifiers()) is { } leave)
        {
            Pointer?.Invoke(leave);
        }
    }

    protected override void OnLostMouseCapture(MouseEventArgs e)
    {
        base.OnLostMouseCapture(e);
        // Taken away (another window, Alt+Tab): no release will come, the button is no longer held
        if (pointer.Held is { } button)
        {
            Pointer?.Invoke(pointer.Release(button, CellAt(e), PointerModifiers()));
        }
    }

    protected override void OnMouseWheel(MouseWheelEventArgs e)
    {
        base.OnMouseWheel(e);
        if (pointer.Wheel(CellAt(e), e.Delta, 0, PointerModifiers()) is { } wheel)
        {
            Pointer?.Invoke(wheel);
        }

        e.Handled = true;
    }

    /// <summary>A horizontal wheel (WM_MOUSEHWHEEL, which WPF does not route) turned over the canvas: positive right.</summary>
    public void HorizontalWheel(int delta)
    {
        if (pointer.Wheel(CellAt(Mouse.GetPosition(this)), 0, delta, PointerModifiers()) is { } wheel)
        {
            Pointer?.Invoke(wheel);
        }
    }

    private (int Row, int Column) CellAt(MouseEventArgs e) => CellAt(e.GetPosition(this));

    private (int Row, int Column) CellAt(Point at) => PointerTracker.Cell(at.X, at.Y, cell.Width, cell.Height);

    private static string? ButtonId(MouseButton button) => button switch
    {
        MouseButton.Left => "left",
        MouseButton.Middle => "middle",
        MouseButton.Right => "right",
        _ => null,
    };

    private static IReadOnlyList<string> PointerModifiers()
    {
        var held = Keyboard.Modifiers;
        return PointerTracker.Modifiers(
            held.HasFlag(ModifierKeys.Control), held.HasFlag(ModifierKeys.Shift), held.HasFlag(ModifierKeys.Alt), held.HasFlag(ModifierKeys.Windows));
    }

    /// <summary>A key pressed in the canvas, before the TextBox sees it: it uses none but those that type text.</summary>
    private void Pressed(KeyEventArgs e)
    {
        // A key the input method took (composing, or a dead key): its text comes as TextInput
        if (e.Key is System.Windows.Input.Key.ImeProcessed or System.Windows.Input.Key.DeadCharProcessed)
        {
            return;
        }

        var key = Input.ActualKey(e);
        var modifiers = (WpfModifiers)Keyboard.Modifiers;
        if (key == System.Windows.Input.Key.Tab && (modifiers & ~WpfModifiers.Shift) == WpfModifiers.None)
        {
            // Tab and Shift+Tab move the focus.
            return;
        }

        var id = Input.KeyId(key, modifiers);
        if (id is null)
        {
            return;
        }

        e.Handled = true;
        if (id is "ctrl+v" or "shift+insert")
        {
            // Nothing for no text, an empty one, or a clipboard another process holds open
            if (ClipboardText.ToPaste() is { } text)
            {
                Paste?.Invoke(text);
            }
        }
        else if (isShortcut(id))
        {
            Shortcut?.Invoke(id);
        }
        else if (KeyIds.TypesText((int)key, modifiers))
        {
            // Its text comes as TextInput
            e.Handled = false;
        }
        else
        {
            Key?.Invoke(id, null);
        }
    }

    /// <summary>Typed, or committed by an input method: one key per character. The TextBox keeps none of it.</summary>
    private void Committed(TextCompositionEventArgs e)
    {
        Compose("");
        var keys = KeyIds.Committed(e.Text);
        foreach (var (id, text) in keys)
        {
            Key?.Invoke(id, text);
        }

        // An input method's commit is in the TextBox already, before the caret: left to the TextBox (which has
        // nothing to insert then), not handled, or a composition the same key starts after it would not come. The
        // TextBox is emptied once the input method's edit is over, but not of that composition. Typed text, or a
        // commit the TextBox does not hold, is handled: it never goes in.
        var end = input.SelectionStart + input.SelectionLength;
        var holds = e.Text.Length > 0 && end >= e.Text.Length && string.CompareOrdinal(input.Text, end - e.Text.Length, e.Text, 0, e.Text.Length) == 0;
        e.Handled = !holds;
        committed = holds ? end : input.Text.Length;
        if (committed > 0)
        {
            Dispatcher.BeginInvoke(System.Windows.Threading.DispatcherPriority.Input, Clear);
        }
    }

    /// <summary>The parts of a run from <paramref name="column"/> on <paramref name="row"/> in cells a control does not cover, with their first column.</summary>
    private IEnumerable<(int Column, string Text)> Stretches(int row, int column, string text)
    {
        if (covered.Count == 0)
        {
            yield return (column, text);
            yield break;
        }

        var elements = StringInfo.GetTextElementEnumerator(text);
        var start = -1;
        var builder = new System.Text.StringBuilder();
        for (var at = column; elements.MoveNext(); at++)
        {
            if (Draws(row, at))
            {
                if (start < 0)
                {
                    start = at;
                }

                builder.Append(elements.GetTextElement());
            }
            else if (start >= 0)
            {
                yield return (start, builder.ToString());
                builder.Clear();
                start = -1;
            }
        }

        if (start >= 0)
        {
            yield return (start, builder.ToString());
        }
    }

    /// <summary>Cells taken: one per text element (no double-width handling).</summary>
    private static int Length(string text) => new StringInfo(text).LengthInTextElements;

    private static bool Flag(JsonObject run, string name) => run[name] is JsonValue v && v.TryGetValue<bool>(out var b) && b;

    private static Brush? Color(JsonObject run, string name) => run[name] is JsonValue v && v.TryGetValue<string>(out var s) ? Theme.Brush(s) : null;
}
