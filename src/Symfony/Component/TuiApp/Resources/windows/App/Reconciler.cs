using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Media;
using System.Text.Json.Nodes;
using System.Windows;
using System.Windows.Automation;
using System.Windows.Controls;
using System.Windows.Controls.Primitives;
using System.Windows.Documents;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Threading;
using TuiAppHost.Core;

namespace TuiAppHost;

/// <summary>One node's native controls. Host is the node's box: padding, border, colours, hidden, grow.</summary>
public sealed class NodeView
{
    public NodeView(Node node, Border host)
    {
        Id = node.Id;
        Kind = node.Kind;
        Host = host;
    }

    public int Id { get; }

    public string Kind { get; }

    public Border Host { get; }

    /// <summary>The control that does the kind's work: TextBox, ListBox, TabControl, Expander…</summary>
    public FrameworkElement? Control { get; set; }

    /// <summary>Where the node's children go: a stack's panel, a disclosure's content.</summary>
    public WeightedStackPanel? Panel { get; set; }

    public TextBlock? Text { get; set; }

    public TextBlock? Secondary { get; set; }

    public TextBox? Field { get; set; }

    /// <summary>maxLength: the most code points typing and pastes may make the field's value; null for none.</summary>
    public int? MaxLength { get; set; }

    /// <summary>The value of a text field, password or number field outside an input method's composition: what was sent or set.</summary>
    public string Settled { get; set; } = "";

    /// <summary>An input method composes in the text field: what it holds past <see cref="Settled"/> is not the value yet.</summary>
    public bool Composing { get; set; }

    /// <summary>An input method's commit in the text field, not settled yet (cut to maxLength and sent): see Reconciler.Settle.</summary>
    public Commit? Unsettled { get; set; }

    /// <summary>
    /// What of a commit did not fit maxLength and is still in the text field, at <c>At</c> in it: a composition the
    /// same key started after it keeps the field as it is until it ends. Not in <see cref="Settled"/>.
    /// </summary>
    public (int At, string Text)? Overflow { get; set; }

    /// <summary>What the text field holds outside a composition: <see cref="Settled"/>, and the overflow of a commit.</summary>
    public string Shown => Overflow is { } o ? Settled.Insert(o.At, o.Text) : Settled;

    /// <summary>A number field's or a slider's min, max and step.</summary>
    public (double? Min, double? Max, double Step) Range { get; set; } = (null, null, 1);

    /// <summary>The value a slider sent or was set to: a move to the same step sends nothing.</summary>
    public double? Sent { get; set; }

    public ProgressBar? Bar { get; set; }

    public Button? Button { get; set; }

    public CanvasView? Canvas { get; set; }

    /// <summary>A canvas's layer above its surface, where its children, native controls, are placed.</summary>
    public System.Windows.Controls.Canvas? Overlay { get; set; }

    /// <summary>A canvas's keys: sent as key, not used, while it or one of its controls has the focus.</summary>
    public HashSet<string> Keys { get; set; } = new();

    /// <summary>The choices a popup shows, to rebuild its items only when they change.</summary>
    public string? Choices { get; set; }

    public string? FormShape { get; set; }

    /// <summary>Set while the host changes a control from props: its handlers send nothing then.</summary>
    public bool Quiet { get; set; }

    /// <summary>What takes the keyboard focus: a canvas's input (CanvasView.Entry), where input methods compose.</summary>
    public FrameworkElement FocusTarget => (FrameworkElement?)Field ?? Canvas?.Entry ?? Control ?? Host;
}

/// <summary>
/// An input method's commit, as the text field holds it when PreviewTextInput comes: <paramref name="Before"/> the
/// value it was made on, <paramref name="After"/> the field's text with it, <paramref name="End"/> where it ends
/// there (the caret), <paramref name="Text"/> what it committed (null when it is several commits).
/// </summary>
public sealed record Commit(string Before, string After, int End, string? Text);

/// <summary>Turns the node tree into WPF controls, patch by patch.</summary>
public sealed class Reconciler
{
    private readonly Dictionary<int, NodeView> views = new();
    private readonly Dictionary<DependencyObject, int> hosts = new();
    private readonly Action<int, string, JsonNode?, bool> send;
    private readonly Action<string, string?> key;
    private readonly Action<string> paste;
    private readonly Func<string, bool> isShortcut;
    private readonly Action<string> shortcut;
    private readonly Action<PointerMessage> pointer;

    /// <summary>The cursor keyword last sent for each canvas: kept, as the application sends it only when it changes.</summary>
    private readonly Dictionary<int, string> cursors = new();
    private CellMetrics cell;
    private Theme theme;

    /// <param name="send">node, event name, value, whether value is set</param>
    /// <param name="isShortcut">whether a key id is a menu shortcut</param>
    /// <param name="shortcut">chooses the menu item of a shortcut pressed in a canvas</param>
    /// <param name="pointer">what the mouse does on a canvas</param>
    public Reconciler(CellMetrics cell, Theme theme, Action<int, string, JsonNode?, bool> send, Action<string, string?> key, Action<string> paste, Func<string, bool> isShortcut, Action<string> shortcut, Action<PointerMessage> pointer)
    {
        this.pointer = pointer;
        this.cell = cell;
        this.theme = theme;
        this.send = send;
        this.key = key;
        this.paste = paste;
        this.isShortcut = isShortcut;
        this.shortcut = shortcut;
    }

    public IReadOnlyDictionary<int, NodeView> Views => views;

    /// <summary>The window's DPI, which placed controls are snapped to the pixels of.</summary>
    public Func<DpiScale> Dpi { get; init; } = () => new DpiScale(1, 1);

    /// <summary>
    /// The controls placed over canvases, placed again on the pixels of a new DPI: the one given, as DpiChanged has it
    /// (the window may still report the old one during the event).
    /// </summary>
    public void Replace(NodeTree tree, DpiScale dpi)
    {
        foreach (var node in tree.Walk())
        {
            if (node.Prop("place") is JsonArray && views.TryGetValue(node.Id, out var view))
            {
                Place(view, node, dpi);
            }
        }
    }

    public UIElement? Root { get; private set; }

    public void Apply(NodeTree tree, PatchResult result)
    {
        foreach (var id in result.Removed)
        {
            cursors.Remove(id);
            if (views.Remove(id, out var removed))
            {
                hosts.Remove(removed.Host);
                Detach(removed.Host);
            }
        }

        foreach (var id in result.Created)
        {
            var node = tree.Find(id)!;
            if (views.Remove(id, out var replaced))
            {
                hosts.Remove(replaced.Host);
                Detach(replaced.Host);
            }

            var view = Build(node);
            views[id] = view;
            hosts[view.Host] = id;
        }

        foreach (var id in result.Created.Concat(result.Updated))
        {
            if (tree.Find(id) is { } node && views.TryGetValue(id, out var view))
            {
                Update(view, node);
            }
        }

        // Children leave every changed container before any is filled: a moved node has one parent at a time.
        var changed = result.ChildrenChanged.Select(tree.Find).OfType<Node>().Where(n => views.ContainsKey(n.Id)).ToList();
        foreach (var parent in changed)
        {
            Clear(views[parent.Id]);
        }

        foreach (var parent in changed)
        {
            Fill(views[parent.Id], parent);
        }

        // The cells each canvas leaves undrawn: those of the controls shown over it, again as they move or go
        foreach (var (id, canvasView) in views)
        {
            if (canvasView.Canvas is not null && tree.Find(id) is { } canvasNode)
            {
                canvasView.Canvas.Covered = canvasNode.Children.Select(Covers).Where(c => c.HasValue).Select(c => c!.Value).ToList();
            }
        }

        if (result.RootChanged)
        {
            Root = tree.Root is { } root && views.TryGetValue(root.Id, out var rootView) ? rootView.Host : null;
            if (Root is not null)
            {
                Detach(Root);
            }
        }
    }

    /// <summary>The node whose control holds <paramref name="element"/>, by walking up the tree.</summary>
    public int? NodeOf(DependencyObject? element)
    {
        for (var current = element; current is not null; current = Parent(current))
        {
            if (current is Border border && hosts.TryGetValue(border, out var id))
            {
                return id;
            }
        }

        return null;
    }

    public void Retheme(Theme newTheme, NodeTree tree)
    {
        theme = newTheme;
        foreach (var node in tree.Walk())
        {
            if (views.TryGetValue(node.Id, out var view))
            {
                Update(view, node);
            }
        }
    }

    public void Recell(CellMetrics newCell) => cell = newCell;

    /// <summary>
    /// cursor: the pointer shown over the surface of canvas <paramref name="node"/>, set on its CanvasView alone. The
    /// controls placed over it are its siblings, not its children, and keep their own; past the canvas, WPF shows the
    /// pointer of what is there. Null, and nothing changes, when the node is not a canvas.
    /// </summary>
    public CursorShape? SetCursor(int node, string keyword)
    {
        if (!views.TryGetValue(node, out var view) || view.Canvas is null)
        {
            return null;
        }

        cursors[node] = keyword;
        var shape = CursorShapes.Of(keyword);
        view.Canvas.Cursor = Wpf(shape);
        return shape;
    }

    /// <summary>The WPF pointer of a shape, the property of <see cref="Cursors"/> of its name (Core.Tests checks they exist).</summary>
    public static Cursor Wpf(CursorShape shape) =>
        (Cursor)typeof(Cursors).GetProperty(shape.ToString())!.GetValue(null)!;

    private static DependencyObject? Parent(DependencyObject element) =>
        (element is Visual or System.Windows.Media.Media3D.Visual3D ? VisualTreeHelper.GetParent(element) : null)
        ?? LogicalTreeHelper.GetParent(element)
        ?? (element is FrameworkContentElement content ? content.Parent : null);

    private NodeView Build(Node node)
    {
        var host = new Border { SnapsToDevicePixels = true };
        var view = new NodeView(node, host);
        switch (node.Kind)
        {
            case "stack":
                view.Panel = new WeightedStackPanel();
                host.Child = view.Panel;
                break;
            case "label":
                view.Text = new TextBlock { TextWrapping = TextWrapping.Wrap };
                view.Control = view.Text;
                host.Child = view.Text;
                break;
            case "textfield":
                BuildTextField(view);
                break;
            case "textarea":
                BuildTextArea(view);
                break;
            case "list":
                BuildList(view);
                break;
            case "form":
                var grid = new Grid();
                grid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
                grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
                view.Control = grid;
                host.Child = grid;
                break;
            case "tabs":
                BuildTabs(view);
                break;
            case "disclosure":
                BuildDisclosure(view);
                break;
            case "progress":
                view.Text = new TextBlock { Margin = new Thickness(0, 0, 0, 2) };
                view.Bar = new ProgressBar { Minimum = 0, Maximum = 1, Height = Math.Max(8, cell.Height * 0.6) };
                view.Control = view.Bar;
                host.Child = new StackPanel { Children = { view.Text, view.Bar } };
                break;
            case "spinner":
                BuildSpinner(view);
                break;
            case "canvas":
                view.Canvas = new CanvasView(cell, theme, isShortcut);
                view.Canvas.Key += (id, text) => key(id, text);
                view.Canvas.Paste += text => paste(text);
                view.Canvas.Pointer += message => pointer(message with { Node = view.Id });
                view.Canvas.Shortcut += id => shortcut(id);
                if (cursors.TryGetValue(view.Id, out var keyword))
                {
                    view.Canvas.Cursor = Wpf(CursorShapes.Of(keyword));
                }

                view.Control = view.Canvas;
                // The controls placed over it come after it: drawn above it, and after it in the Tab order
                // Not clipped to the lines drawn: a control is cut only to its clip
                view.Overlay = new System.Windows.Controls.Canvas();
                host.Child = new Grid { Children = { view.Canvas, view.Overlay } };
                host.PreviewKeyDown += (_, e) => CanvasKey(view, e);
                break;
            case "button":
                var button = new Button { Padding = new Thickness(4, 0, 4, 0), MinHeight = 0, VerticalContentAlignment = VerticalAlignment.Center };
                button.Click += (_, _) => send(view.Id, "press", null, false);
                view.Control = button;
                host.Child = button;
                break;
            case "checkbox":
                BuildToggle(view, node.String("group") is not null);
                break;
            case "password":
                BuildPassword(view);
                break;
            case "number":
                BuildNumber(view);
                break;
            case "slider":
                BuildSlider(view);
                break;
            case "listbox":
                BuildListbox(view);
                break;
            case "popup":
                var popup = new ComboBox { Padding = new Thickness(4, 0, 4, 0), MinHeight = 0, VerticalContentAlignment = VerticalAlignment.Center };
                popup.SelectionChanged += (_, e) =>
                {
                    if (e.OriginalSource == popup && !view.Quiet && popup.SelectedIndex >= 0)
                    {
                        send(view.Id, "select", popup.SelectedIndex, true);
                    }
                };
                view.Control = popup;
                host.Child = popup;
                break;
            default:
                // Unknown kinds: an empty box.
                host.MinHeight = cell.Height;
                host.BorderThickness = new Thickness(1);
                break;
        }

        return view;
    }

    /// <summary>A check box, or with a group a radio button: the application unchecks the others of its group.</summary>
    private void BuildToggle(NodeView view, bool radio)
    {
        // No GroupName, and each in its own box: WPF does not uncheck radios behind the application's back
        ToggleButton toggle = radio ? new RadioButton() : new CheckBox();
        toggle.VerticalContentAlignment = VerticalAlignment.Center;
        toggle.MinHeight = 0;
        toggle.Checked += (_, _) => Toggled(view, true);
        toggle.Unchecked += (_, _) => Toggled(view, false);
        view.Control = toggle;
        view.Host.Child = toggle;
    }

    private void Toggled(NodeView view, bool state)
    {
        if (!view.Quiet)
        {
            send(view.Id, "toggle", state, true);
        }
    }

    /// <summary>The keys a canvas takes, like Tab, from it or a control placed over it.</summary>
    private void CanvasKey(NodeView view, KeyEventArgs e)
    {
        if (view.Keys.Count == 0 || e.Key is Key.ImeProcessed or Key.DeadCharProcessed)
        {
            return;
        }

        // While an input method composes in the canvas or in its focused control, its keys are the input method's
        if (view.Canvas!.IsComposing || (NodeOf(Keyboard.FocusedElement as DependencyObject) is { } focused && views.TryGetValue(focused, out var control) && control.Composing))
        {
            return;
        }

        if (Input.KeyId(Input.ActualKey(e), (WpfModifiers)Keyboard.Modifiers) is { } id && view.Keys.Contains(id))
        {
            key(id, null);
            e.Handled = true;
        }
    }

    private void BuildTextField(NodeView view)
    {
        var prompt = new TextBlock { VerticalAlignment = VerticalAlignment.Center, Margin = new Thickness(0, 0, 4, 0) };
        var field = new TextBox { VerticalContentAlignment = VerticalAlignment.Center, Padding = new Thickness(2) };
        DockPanel.SetDock(prompt, Dock.Left);
        view.Text = prompt;
        view.Field = field;
        view.Control = field;
        view.Host.Child = new DockPanel { LastChildFill = true, Children = { prompt, field } };
        Edits(view, field);
        field.KeyDown += (_, e) =>
        {
            if (Keyboard.Modifiers != ModifierKeys.None)
            {
                return;
            }

            if (e.Key == Key.Enter)
            {
                send(view.Id, "submit", field.Text, true);
                e.Handled = true;
            }
            else if (e.Key == Key.Escape)
            {
                send(view.Id, "cancel", null, false);
                e.Handled = true;
            }
        };
    }

    private void BuildTextArea(NodeView view)
    {
        var field = new TextBox
        {
            AcceptsReturn = true,
            AcceptsTab = false,
            TextWrapping = TextWrapping.Wrap,
            VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
            HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled,
            Padding = new Thickness(2),
        };
        view.Field = field;
        view.Control = field;
        view.Host.Child = field;
        Edits(view, field);
        field.PreviewKeyDown += (_, e) =>
        {
            // A line break is "\n", one character as the application counts it: AcceptsReturn would insert "\r\n"
            if (e.Key == Key.Enter && Keyboard.Modifiers is ModifierKeys.None or ModifierKeys.Shift)
            {
                e.Handled = true;
                if (TextLimit.Fit(field.Text, field.SelectionStart, field.SelectionLength, "\n", view.MaxLength).Length == 0)
                {
                    SystemSounds.Beep.Play();
                    return;
                }

                var at = field.SelectionStart;
                field.SelectedText = "\n";
                field.CaretIndex = at + 1;
                return;
            }

            if (e.Key == Key.Enter && Keyboard.Modifiers is ModifierKeys.Control or ModifierKeys.Windows)
            {
                send(view.Id, "submit", field.Text, true);
                e.Handled = true;
            }
        };
    }

    /// <summary>
    /// The edits of a text field, a text area or a number field.
    /// - change as the text changes, but not while an input method composes (the preedit is not the value
    ///   yet): WPF puts the text being composed in the TextBox after PreviewTextInputStart, and its commit
    ///   before PreviewTextInput. Typed keys go through PreviewTextInputStart and PreviewTextInput, before
    ///   their text is inserted.
    /// - maxLength, in code points where TextBox.MaxLength counts UTF-16 units: what is typed or pasted is cut
    ///   to the room left (in overtype mode, a key replaces the character after the caret). What an input
    ///   method composes is let in whole; its commit, in the field already when PreviewTextInput comes, is cut to
    ///   what fits once the input method's edit is over (Settle). One key may commit and start a new composition
    ///   (Korean 한 then ㄱ, Japanese typed on after a conversion): only the commit is settled.
    /// </summary>
    private void Edits(NodeView view, TextBox field)
    {
        field.AddHandler(TextCompositionManager.PreviewTextInputStartEvent, new TextCompositionEventHandler((_, _) => view.Composing = true), true);
        field.AddHandler(TextCompositionManager.PreviewTextInputEvent, new TextCompositionEventHandler((_, e) =>
        {
            view.Composing = false;
            if (view.Unsettled is { } earlier && field.Text == earlier.After)
            {
                // A key typed before the commit was settled (its text is not in the field yet): the commit first
                Settle(view, field);
            }
            else if (view.Unsettled is not null || field.Text != view.Shown)
            {
                // An input method's commit, which it composed in the field: settled once its edit is over, when
                // the field may be changed again. Two commits before then are settled as one edit.
                var end = field.SelectionStart + field.SelectionLength;
                if (view.Unsettled is { } first)
                {
                    view.Unsettled = first with { After = field.Text, End = end, Text = null };
                }
                else
                {
                    view.Unsettled = new Commit(view.Shown, field.Text, end, e.Text);
                    field.Dispatcher.BeginInvoke(DispatcherPriority.Input, () => Settle(view, field));
                }

                return;
            }

            if (view.Overflow is not null)
            {
                // A key typed once a composition that kept the overflow of a commit ended: the overflow goes first
                if (e.Text.Length > 0)
                {
                    TrimNow(view, field);
                }
                else
                {
                    Trim(view, field);
                }
            }

            var replaced = field.SelectionLength == 0 && Overtype(field) ? Overtyped(field.Text, field.SelectionStart) : field.SelectionLength;
            var fits = TextLimit.Fit(field.Text, field.SelectionStart, replaced, e.Text, view.MaxLength);
            if (e.Handled || fits.Length == e.Text.Length)
            {
                return;
            }

            e.Handled = true;
            SystemSounds.Beep.Play();
            if (fits.Length > 0)
            {
                field.Select(field.SelectionStart, replaced);
                field.SelectedText = fits;
                field.CaretIndex = field.SelectionStart + fits.Length;
                field.SelectionLength = 0;
            }
        }), true);
        field.TextChanged += (_, _) =>
        {
            // A composition taken back whole (Escape) ends without PreviewTextInput
            if (view.Composing && view.Unsettled is null && field.Text == view.Shown)
            {
                view.Composing = false;
                Trim(view, field);
            }

            if (view.Composing || view.Unsettled is not null || (view.Overflow is not null && field.Text == view.Shown))
            {
                return;
            }

            // An edit of a field that still showed the overflow of a commit: the field as it is
            view.Overflow = null;
            var changed = field.Text != view.Settled;
            view.Settled = field.Text;
            if (changed && !view.Quiet)
            {
                send(view.Id, "change", field.Text, true);
            }
        };
        field.LostKeyboardFocus += (_, _) =>
        {
            view.Composing = false;
            Trim(view, field);
        };
        DataObject.AddPastingHandler(field, (_, e) =>
        {
            if (e.SourceDataObject.GetData(DataFormats.UnicodeText) is not string pasted)
            {
                return;
            }

            // Line breaks as "\n", as Enter inserts them
            var text = field.AcceptsReturn ? pasted.Replace("\r\n", "\n").Replace('\r', '\n') : pasted;
            var fits = TextLimit.Fit(field.Text, field.SelectionStart, field.SelectionLength, text, view.MaxLength);
            if (fits == pasted)
            {
                return;
            }

            if (fits.Length == 0)
            {
                e.CancelCommand();
                SystemSounds.Beep.Play();
            }
            else
            {
                e.DataObject = new DataObject(DataFormats.UnicodeText, fits);
            }
        });
    }

    /// <summary>
    /// An input method's commit is in the field: what does not fit maxLength goes, and the value is sent. The commit
    /// is where it ended, the caret, not guessed from the text (repeated text would make it ambiguous). A composition
    /// the same key started after it (in the field now, after the commit's end) is not the value: it is neither sent
    /// nor cut. The field is not changed under it either (the input method would end it): what of the commit does not
    /// fit stays in the field, as <see cref="NodeView.Overflow"/>, until the composition ends.
    /// </summary>
    private void Settle(NodeView view, TextBox field)
    {
        if (view.Unsettled is not { } commit)
        {
            return;
        }

        view.Unsettled = null;
        var settled = view.Settled;
        var (overflowAt, overflow) = view.Overflow ?? (0, "");
        var (start, removed, inserted) = TextLimit.Splice(commit.Before, commit.After, commit.End, commit.Text);
        string value;
        int caret;
        bool cutAny;
        // What of the field is not the value after it, at At in commit.After: one run, or null when it cannot be kept
        (int At, int Length)? left = (0, 0);
        if (overflow.Length == 0 || start + removed <= overflowAt || start >= overflowAt + overflow.Length)
        {
            // Settled coordinates: the overflow is not in it
            var at = overflow.Length > 0 && start >= overflowAt ? start - overflow.Length : start;
            var fits = TextLimit.Fit(settled, at, removed, inserted, view.MaxLength);
            value = settled[..at] + fits + settled[(at + removed)..];
            caret = at + fits.Length;
            var cut = (At: start + fits.Length, Length: inserted.Length - fits.Length);
            cutAny = cut.Length > 0;
            var kept = (At: start + removed <= overflowAt ? overflowAt + inserted.Length - removed : overflowAt, overflow.Length);
            left = overflow.Length == 0 ? cut : cut.Length == 0 ? kept
                : kept.At + kept.Length == cut.At ? (kept.At, kept.Length + cut.Length)
                : cut.At + cut.Length == kept.At ? (cut.At, cut.Length + kept.Length)
                : null;
        }
        else
        {
            // An edit of the overflow: guessed from the texts, and the field set
            value = TextLimit.Keep(settled, commit.After, view.MaxLength, out caret);
            cutAny = value.Length - settled.Length < commit.After.Length - commit.Before.Length;
            left = null;
        }

        if (left is { Length: 0 })
        {
            view.Overflow = null;
        }
        else
        {
            var text = field.Text;
            var composing = text.Length > commit.After.Length
                && text.StartsWith(commit.After[..commit.End], StringComparison.Ordinal) && text.EndsWith(commit.After[commit.End..], StringComparison.Ordinal);
            if (left is { Length: > 0 } run && composing)
            {
                view.Overflow = (run.At, commit.After.Substring(run.At, run.Length));
            }
            else
            {
                view.Quiet = true;
                try
                {
                    view.Overflow = null;
                    view.Composing = false;
                    view.Settled = value;
                    field.Text = value;
                    field.CaretIndex = caret;
                }
                finally
                {
                    view.Quiet = false;
                }
            }
        }

        if (cutAny)
        {
            SystemSounds.Beep.Play();
        }

        view.Settled = value;
        // A composition the same key started and took back since (Escape)
        if (view.Composing && field.Text == view.Shown)
        {
            view.Composing = false;
            Trim(view, field);
        }

        if (value != settled)
        {
            send(view.Id, "change", value, true);
        }
    }

    /// <summary>The overflow of a commit out of the field, once no composition holds it: later, as the field may be changed then.</summary>
    private static void Trim(NodeView view, TextBox field)
    {
        if (view.Overflow is null)
        {
            return;
        }

        field.Dispatcher.BeginInvoke(DispatcherPriority.Input, () => TrimNow(view, field));
    }

    private static void TrimNow(NodeView view, TextBox field)
    {
        if (view.Composing || view.Unsettled is not null || view.Overflow is not { } overflow || field.Text != view.Shown)
        {
            return;
        }

        view.Quiet = true;
        try
        {
            view.Overflow = null;
            field.Text = view.Settled;
            field.CaretIndex = overflow.At;
        }
        finally
        {
            view.Quiet = false;
        }
    }

    /// <summary>
    /// The value of a text field, text area or number field from props. One the field has (what the user typed and
    /// sent, as acknowledged) leaves the field as it is: its caret, its selection, an input method's composition or a
    /// commit not settled yet. Another one replaces its text, caret at the end.
    /// </summary>
    private static void SetValue(NodeView view, TextBox field, string value)
    {
        if (value == view.Settled && (view.Composing || view.Unsettled is not null || field.Text == view.Shown))
        {
            return;
        }

        view.Unsettled = null;
        view.Composing = false;
        view.Overflow = null;
        view.Settled = value;
        field.Text = value;
        field.CaretIndex = value.Length;
    }

    /// <summary>Whether the field is in overtype mode (Insert): WPF keeps it in the field's TextEditor, which it does not make public.</summary>
    private static bool Overtype(TextBox field)
    {
        try
        {
            var editor = TextEditorProperty?.GetValue(field);
            return editor is not null && OvertypeProperty?.GetValue(editor) is true;
        }
        catch (System.Reflection.TargetInvocationException)
        {
            return false;
        }
    }

    private static readonly System.Reflection.PropertyInfo? TextEditorProperty =
        typeof(TextBoxBase).GetProperty("TextEditor", System.Reflection.BindingFlags.Instance | System.Reflection.BindingFlags.NonPublic);

    private static readonly System.Reflection.PropertyInfo? OvertypeProperty =
        TextEditorProperty?.PropertyType.GetProperty("_OvertypeMode", System.Reflection.BindingFlags.Instance | System.Reflection.BindingFlags.NonPublic);

    /// <summary>What a key typed in overtype mode replaces: the character after the caret, not a line break.</summary>
    private static int Overtyped(string text, int caret) =>
        caret < text.Length && text[caret] is not ('\n' or '\r') ? StringInfo.GetNextTextElementLength(text, caret) : 0;

    /// <summary>
    /// password: a PasswordBox. change, submit (Enter), cancel (Escape) and maxLength as a text field; it takes no
    /// input method. It says nothing of its selection: an edit that leaves it too long is found by comparing it
    /// with the value before, and cut.
    /// </summary>
    private void BuildPassword(NodeView view)
    {
        var box = new PasswordBox { VerticalContentAlignment = VerticalAlignment.Center, Padding = new Thickness(2) };
        view.Control = box;
        view.Host.Child = box;
        box.PasswordChanged += (_, _) =>
        {
            if (view.Quiet)
            {
                view.Settled = box.Password;
                return;
            }

            var before = view.Settled;
            var kept = TextLimit.Keep(before, box.Password, view.MaxLength, out var caret);
            if (kept != box.Password)
            {
                SystemSounds.Beep.Play();
                view.Quiet = true;
                try
                {
                    box.Password = kept;
                    try
                    {
                        PasswordSelect?.Invoke(box, new object[] { caret, 0 });
                    }
                    catch (System.Reflection.TargetInvocationException)
                    {
                        // The caret where Password put it
                    }
                }
                finally
                {
                    view.Quiet = false;
                }
            }

            view.Settled = kept;
            if (kept != before)
            {
                send(view.Id, "change", kept, true);
            }
        };
        box.KeyDown += (_, e) =>
        {
            if (Keyboard.Modifiers != ModifierKeys.None)
            {
                return;
            }

            if (e.Key == Key.Enter)
            {
                send(view.Id, "submit", box.Password, true);
                e.Handled = true;
            }
            else if (e.Key == Key.Escape)
            {
                send(view.Id, "cancel", null, false);
                e.Handled = true;
            }
        };
    }

    /// <summary>PasswordBox's own Select(start, length), not public: the caret after a cut edit.</summary>
    private static readonly System.Reflection.MethodInfo? PasswordSelect =
        typeof(PasswordBox).GetMethod("Select", System.Reflection.BindingFlags.Instance | System.Reflection.BindingFlags.NonPublic | System.Reflection.BindingFlags.Public, null, new[] { typeof(int), typeof(int) }, null);

    /// <summary>
    /// number: a text field with up and down arrows. Up and Down in it, and the arrows, go to the next step
    /// (Stepping), within min and max; what is typed is sent as it is, a number or not, and kept. submit (Enter),
    /// cancel (Escape).
    /// </summary>
    private void BuildNumber(NodeView view)
    {
        var field = new TextBox { VerticalContentAlignment = VerticalAlignment.Center, Padding = new Thickness(2) };
        var arrows = new Grid { Margin = new Thickness(1, 0, 0, 0) };
        arrows.RowDefinitions.Add(new RowDefinition());
        arrows.RowDefinitions.Add(new RowDefinition());
        var up = Arrow(view, field, 1);
        var down = Arrow(view, field, -1);
        Grid.SetRow(down, 1);
        arrows.Children.Add(up);
        arrows.Children.Add(down);
        DockPanel.SetDock(arrows, Dock.Right);
        view.Field = field;
        view.Control = field;
        view.Host.Child = new DockPanel { LastChildFill = true, Children = { arrows, field } };
        Edits(view, field);
        field.PreviewKeyDown += (_, e) =>
        {
            if (Keyboard.Modifiers != ModifierKeys.None)
            {
                return;
            }

            switch (e.Key)
            {
                case Key.Up:
                    Step(view, field, 1);
                    break;
                case Key.Down:
                    Step(view, field, -1);
                    break;
                case Key.Enter:
                    send(view.Id, "submit", field.Text, true);
                    break;
                case Key.Escape:
                    send(view.Id, "cancel", null, false);
                    break;
                default:
                    return;
            }

            e.Handled = true;
        };
    }

    /// <summary>An arrow of a number field: a step up (1) or down (-1), repeated while held.</summary>
    private RepeatButton Arrow(NodeView view, TextBox field, int direction)
    {
        var arrow = new RepeatButton
        {
            // The chevrons of Segoe Fluent Icons (Windows 11), else of Segoe MDL2 Assets (Windows 10)
            Content = new TextBlock { Text = direction > 0 ? "\uE70E" : "\uE70D", FontFamily = new FontFamily("Segoe Fluent Icons, Segoe MDL2 Assets"), FontSize = 7, VerticalAlignment = VerticalAlignment.Center },
            Width = 16,
            MinHeight = 0,
            Padding = new Thickness(0),
            Focusable = false,
            IsTabStop = false,
        };
        AutomationProperties.SetName(arrow, direction > 0 ? "Increase" : "Decrease");
        arrow.Click += (_, _) =>
        {
            field.Focus();
            Step(view, field, direction);
        };
        return arrow;
    }

    /// <summary>A number field to its next step that way, sent as change.</summary>
    private void Step(NodeView view, TextBox field, int direction)
    {
        var next = Stepping.Step(field.Text, direction, view.Range.Min, view.Range.Max, view.Range.Step);
        if (next == field.Text)
        {
            return;
        }

        view.Quiet = true;
        try
        {
            field.Text = next;
            field.CaretIndex = next.Length;
        }
        finally
        {
            view.Quiet = false;
        }

        send(view.Id, "change", next, true);
    }

    /// <summary>
    /// slider: from min to max on the steps from min. change with the value, a whole number as an integer, as it
    /// moves. The arrows move it a step, Page Up and Page Down a tenth of the range, Home and End to the bounds.
    /// </summary>
    private void BuildSlider(NodeView view)
    {
        var slider = new Slider { IsSnapToTickEnabled = true, IsMoveToPointEnabled = true, VerticalAlignment = VerticalAlignment.Center, MinHeight = 0 };
        view.Control = slider;
        view.Host.Child = slider;
        slider.ValueChanged += (_, _) =>
        {
            if (view.Quiet)
            {
                return;
            }

            var value = Stepping.Snap(slider.Value, slider.Minimum, slider.Maximum, view.Range.Step);
            if (value != slider.Value)
            {
                // Sent by the change this makes
                slider.Value = value;
                return;
            }

            if (value != view.Sent)
            {
                view.Sent = value;
                send(view.Id, "change", Stepping.Json(value), true);
            }
        };
        slider.PreviewKeyDown += (_, e) =>
        {
            if ((Keyboard.Modifiers & (ModifierKeys.Control | ModifierKeys.Alt | ModifierKeys.Windows)) != ModifierKeys.None)
            {
                return;
            }

            var step = view.Range.Step;
            var page = Math.Max(step, (slider.Maximum - slider.Minimum) / 10);
            double? moved = e.Key switch
            {
                Key.Right or Key.Up => slider.Value + step,
                Key.Left or Key.Down => slider.Value - step,
                Key.PageUp => slider.Value + page,
                Key.PageDown => slider.Value - page,
                Key.Home => slider.Minimum,
                Key.End => slider.Maximum,
                _ => null,
            };
            if (moved is { } to)
            {
                slider.Value = Stepping.Snap(to, slider.Minimum, slider.Maximum, step);
                e.Handled = true;
            }
        };
    }

    /// <summary>
    /// listbox: a ListBox of choices, several selected with multiple (Ctrl+click, Shift+click). select with the
    /// selected indexes when the user changes them. A single list keeps one row selected, like a select element.
    /// </summary>
    private void BuildListbox(NodeView view)
    {
        var list = new ListBox();
        ScrollViewer.SetHorizontalScrollBarVisibility(list, ScrollBarVisibility.Disabled);
        view.Control = list;
        view.Host.Child = list;
        list.SelectionChanged += (_, e) =>
        {
            if (e.OriginalSource != list || view.Quiet)
            {
                return;
            }

            if (list.SelectionMode == SelectionMode.Single && list.SelectedIndex < 0 && e.RemovedItems.Count > 0 && e.RemovedItems[0] is ListBoxItem unselected)
            {
                // Ctrl+click on the selected row
                view.Quiet = true;
                try
                {
                    unselected.IsSelected = true;
                }
                finally
                {
                    view.Quiet = false;
                }

                return;
            }

            var indexes = list.SelectedItems.OfType<object>().Select(item => list.Items.IndexOf(item)).Where(i => i >= 0).Order();
            send(view.Id, "select", new JsonArray(indexes.Select(i => (JsonNode?)i).ToArray()), true);
        };
    }

    private void UpdateListbox(NodeView view, Node node, bool placed)
    {
        var list = (ListBox)view.Control!;
        var choices = (node.Prop("choices") as JsonArray)?.Select(c => c is JsonValue v && v.TryGetValue<string>(out var text) ? text : "").ToList() ?? new List<string>();
        var shape = string.Join("\u0001", choices);
        if (shape != view.Choices)
        {
            view.Choices = shape;
            list.Items.Clear();
            foreach (var choice in choices)
            {
                list.Items.Add(new ListBoxItem { Content = choice });
            }
        }

        var multiple = node.Bool("multiple") == true;
        list.SelectionMode = multiple ? SelectionMode.Extended : SelectionMode.Single;
        var selected = (node.Prop("selected") as JsonArray)?.Select(v => v is JsonValue value && value.TryGetValue<int>(out var i) ? i : -1).Where(i => i >= 0 && i < choices.Count).ToList() ?? new List<int>();
        if (!multiple)
        {
            selected = selected.Take(1).ToList();
        }

        // Placed: a row a line of cells, in the canvas font, and no border nor the 1 px padding of the template's
        // border: its rows fill its place, nothing of it past its cells (the border was out of them, a margin of -1)
        CanvasFont(list, placed);
        if (placed)
        {
            list.BorderThickness = new Thickness(0);
        }
        else
        {
            list.ClearValue(Control.BorderThicknessProperty);
        }

        // The border at the root of its template, whatever its theme names it (Aero2: Bd, padded by 1)
        list.ApplyTemplate();
        if (VisualTreeHelper.GetChildrenCount(list) > 0 && VisualTreeHelper.GetChild(list, 0) is Border inner)
        {
            if (placed)
            {
                inner.Padding = new Thickness(0);
            }
            else
            {
                inner.ClearValue(Border.PaddingProperty);
            }
        }
        for (var i = 0; i < list.Items.Count; i++)
        {
            var item = (ListBoxItem)list.Items[i];
            item.Padding = placed ? new Thickness(2, 0, 2, 0) : new Thickness(4, 1, 4, 1);
            item.MinHeight = 0;
            if (placed)
            {
                item.Height = cell.Height;
            }
            else
            {
                item.ClearValue(FrameworkElement.HeightProperty);
            }

            if (!selected.Contains(i))
            {
                item.IsSelected = false;
            }
        }

        foreach (var i in selected)
        {
            ((ListBoxItem)list.Items[i]).IsSelected = true;
        }
    }

    private void BuildList(NodeView view)
    {
        var list = new ListBox { HorizontalContentAlignment = HorizontalAlignment.Stretch };
        ScrollViewer.SetHorizontalScrollBarVisibility(list, ScrollBarVisibility.Disabled);
        view.Control = list;
        view.Host.Child = list;
        list.MouseDoubleClick += (_, e) =>
        {
            if (ItemsControl.ContainerFromElement(list, (DependencyObject)e.OriginalSource) is ListBoxItem item)
            {
                var index = list.ItemContainerGenerator.IndexFromContainer(item);
                if (index >= 0 && !IsMultiple(list))
                {
                    send(view.Id, "select", index, true);
                }
            }
        };
        list.KeyDown += (_, e) =>
        {
            if (e.Key == Key.Enter && Keyboard.Modifiers == ModifierKeys.None && list.SelectedIndex >= 0 && !IsMultiple(list))
            {
                send(view.Id, "select", list.SelectedIndex, true);
                e.Handled = true;
            }
        };
    }

    private static bool IsMultiple(ListBox list) => list.Tag is true;

    private void BuildTabs(NodeView view)
    {
        var tabs = new TabControl { Padding = new Thickness(4) };
        view.Control = tabs;
        view.Host.Child = tabs;
        tabs.SelectionChanged += (_, e) =>
        {
            // SelectionChanged bubbles up from lists and combo boxes inside the tabs.
            if (e.OriginalSource == tabs && !view.Quiet && tabs.SelectedIndex >= 0)
            {
                send(view.Id, "tab", tabs.SelectedIndex, true);
            }
        };
    }

    private void BuildDisclosure(NodeView view)
    {
        view.Text = new TextBlock { FontWeight = FontWeights.SemiBold };
        view.Secondary = new TextBlock { TextWrapping = TextWrapping.Wrap };
        view.Panel = new WeightedStackPanel { Margin = new Thickness(18, 4, 0, 0) };
        var expander = new Expander
        {
            Header = new StackPanel { Children = { view.Text, view.Secondary } },
            Content = view.Panel,
        };
        view.Control = expander;
        view.Host.Child = expander;
        expander.Expanded += (_, e) =>
        {
            if (e.OriginalSource == expander && !view.Quiet)
            {
                send(view.Id, "toggle", true, true);
            }
        };
        expander.Collapsed += (_, e) =>
        {
            if (e.OriginalSource == expander && !view.Quiet)
            {
                send(view.Id, "toggle", false, true);
            }
        };
    }

    private void BuildSpinner(NodeView view)
    {
        view.Bar = new ProgressBar { Width = cell.Width * 8, Height = Math.Max(6, cell.Height * 0.4), IsIndeterminate = true, VerticalAlignment = VerticalAlignment.Center, Margin = new Thickness(0, 0, 8, 0) };
        view.Text = new TextBlock { VerticalAlignment = VerticalAlignment.Center, TextTrimming = TextTrimming.CharacterEllipsis };
        view.Button = new Button { Content = "Cancel", Padding = new Thickness(10, 2, 10, 2), Margin = new Thickness(8, 0, 0, 0), VerticalAlignment = VerticalAlignment.Center };
        DockPanel.SetDock(view.Bar, Dock.Left);
        DockPanel.SetDock(view.Button, Dock.Right);
        view.Button.Click += (_, _) => send(view.Id, "cancel", null, false);
        view.Control = view.Bar;
        view.Host.Child = new DockPanel { LastChildFill = true, Children = { view.Bar, view.Button, view.Text } };
    }

    private void Update(NodeView view, Node node)
    {
        view.Quiet = true;
        try
        {
            Common(view, node);
            Place(view, node);
            var placed = node.Prop("place") is JsonArray { Count: 4 };
            switch (view.Kind)
            {
                case "stack":
                    view.Panel!.Horizontal = node.String("direction") == "horizontal";
                    view.Panel.Gap = (node.Int("gap") ?? 0) * (view.Panel.Horizontal ? cell.Width : cell.Height);
                    break;
                case "label":
                    UpdateLabel(view, node);
                    break;
                case "textfield":
                    CanvasFont(view.Field!, placed);
                    view.Text!.Text = placed ? "" : node.String("prompt") ?? "";
                    view.Text.Visibility = string.IsNullOrEmpty(view.Text.Text) ? Visibility.Collapsed : Visibility.Visible;
                    SetValue(view, view.Field!, node.String("value") ?? "");
                    view.MaxLength = TextLimit.Of(node);
                    break;
                case "textarea":
                    SetValue(view, view.Field!, node.String("value") ?? "");
                    view.MaxLength = TextLimit.Of(node);
                    CanvasFont(view.Field!, placed);
                    // Placed: as high as its place
                    view.Field!.MinLines = placed ? 1 : Math.Max(1, node.Int("minLines") ?? 1);
                    view.Field.MaxLines = placed ? int.MaxValue : Math.Max(view.Field.MinLines, node.Int("maxLines") ?? int.MaxValue);
                    break;
                case "password":
                    var secret = (PasswordBox)view.Control!;
                    CanvasFont(secret, placed);
                    if (secret.Password != (node.String("value") ?? ""))
                    {
                        secret.Password = node.String("value") ?? "";
                    }

                    view.MaxLength = TextLimit.Of(node);
                    break;
                case "number":
                    CanvasFont(view.Field!, placed);
                    SetValue(view, view.Field!, node.String("value") ?? "");
                    view.Range = Stepping.Range(node);
                    break;
                case "slider":
                    UpdateSlider(view, node);
                    break;
                case "listbox":
                    UpdateListbox(view, node, placed);
                    break;
                case "button":
                    ((Button)view.Control!).Content = node.String("text") ?? "";
                    break;
                case "checkbox":
                    var radio = node.String("group") is not null;
                    if (radio != view.Control is RadioButton)
                    {
                        BuildToggle(view, radio);
                        Common(view, node);
                    }

                    var toggle = (ToggleButton)view.Control!;
                    toggle.Content = node.String("text") ?? "";
                    toggle.IsChecked = node.Bool("checked") == true;
                    break;
                case "popup":
                    var popup = (ComboBox)view.Control!;
                    var choices = (node.Prop("choices") as JsonArray)?.Select(c => c?.GetValue<string>() ?? "").ToList() ?? new List<string>();
                    var shape = string.Join("\u0001", choices);
                    if (shape != view.Choices)
                    {
                        view.Choices = shape;
                        popup.ItemsSource = choices;
                    }

                    var chosen = node.Int("selected");
                    popup.SelectedIndex = chosen is { } i && i >= 0 && i < choices.Count ? i : -1;
                    break;
                case "list":
                    UpdateList(view, node);
                    break;
                case "form":
                    UpdateForm(view, node);
                    break;
                case "tabs":
                    UpdateTabHeaders(view, node);
                    var tabs = (TabControl)view.Control!;
                    tabs.TabStripPlacement = node.String("position") switch
                    {
                        "bottom" => Dock.Bottom,
                        "left" => Dock.Left,
                        "right" => Dock.Right,
                        _ => Dock.Top,
                    };
                    var active = node.Int("active") ?? 0;
                    if (active >= 0 && active < tabs.Items.Count)
                    {
                        tabs.SelectedIndex = active;
                    }

                    break;
                case "disclosure":
                    view.Text!.Text = node.String("summary") ?? "";
                    view.Secondary!.Text = node.String("description") ?? "";
                    view.Secondary.Foreground = theme.Secondary;
                    view.Secondary.Visibility = string.IsNullOrEmpty(view.Secondary.Text) ? Visibility.Collapsed : Visibility.Visible;
                    ((Expander)view.Control!).IsExpanded = node.Bool("expanded") ?? false;
                    break;
                case "progress":
                    view.Text!.Text = node.String("text") ?? "";
                    view.Text.Visibility = string.IsNullOrEmpty(view.Text.Text) ? Visibility.Collapsed : Visibility.Visible;
                    var progress = node.Number("progress");
                    view.Bar!.IsIndeterminate = progress is null;
                    view.Bar.Value = Math.Clamp(progress ?? 0, 0, 1);
                    break;
                case "spinner":
                    var running = node.Bool("running") ?? true;
                    view.Bar!.IsIndeterminate = running;
                    view.Bar.Visibility = running ? Visibility.Visible : Visibility.Hidden;
                    view.Text!.Text = node.String("text") ?? "";
                    view.Button!.Visibility = node.Bool("cancellable") == true ? Visibility.Visible : Visibility.Collapsed;
                    break;
                case "canvas":
                    view.Canvas!.Update(node.Prop("lines") as JsonArray, node.Int("columns"), node.Prop("cursor") as JsonArray);
                    view.Canvas.Foreground = Theme.Brush(Style(node, "fg"));
                    view.Canvas.Background = Theme.Brush(Style(node, "bg"));
                    view.Canvas.Entry.Focusable = node.Bool("focusable") ?? true;
                    view.Keys = (node.Prop("keys") as JsonArray)?.Select(k => k?.GetValue<string>()).OfType<string>().ToHashSet() ?? new HashSet<string>();
                    break;
            }
        }
        finally
        {
            view.Quiet = false;
        }
    }

    /// <summary>The props every kind has: style, hidden, disabled, focusable, grow, padding, border, label.</summary>
    private void Common(NodeView view, Node node)
    {
        var host = view.Host;
        host.Visibility = node.Bool("hidden") == true ? Visibility.Collapsed : Visibility.Visible;
        host.IsEnabled = node.Bool("disabled") != true;
        WeightedStackPanel.SetGrow(host, Math.Max(0, node.Int("grow") ?? 0));
        host.Padding = node.Prop("padding") is JsonArray { Count: 4 } p
            ? new Thickness(p[3]!.GetValue<int>() * cell.Width, p[0]!.GetValue<int>() * cell.Height, p[1]!.GetValue<int>() * cell.Width, p[2]!.GetValue<int>() * cell.Height)
            : new Thickness(0);
        var bordered = node.Bool("border") == true;
        if (bordered || view.Kind is not ("stack" or "label" or "textfield" or "textarea" or "list" or "form" or "tabs" or "disclosure" or "progress" or "spinner" or "canvas" or "button" or "checkbox" or "popup" or "password" or "number" or "slider" or "listbox"))
        {
            host.BorderThickness = new Thickness(1);
            host.CornerRadius = new CornerRadius(4);
            host.BorderBrush = theme.Border;
        }
        else
        {
            host.BorderThickness = new Thickness(0);
            host.BorderBrush = null;
        }

        host.Background = Theme.Brush(Style(node, "bg"));
        var fg = Theme.Brush(Style(node, "fg"));
        if (fg is not null)
        {
            host.SetValue(TextElement.ForegroundProperty, fg);
        }
        else
        {
            host.ClearValue(TextElement.ForegroundProperty);
        }

        // The theme's styles set the text colour of a button's and a pop-up's chrome (see Theme.Apply), which an
        // inherited one does not beat: the application's is set on them, on a spinner's Cancel button too (a form's
        // pop-ups: UpdateForm, which may build them after this)
        if (view.Control is Button or ComboBox)
        {
            ChromeForeground((Control)view.Control, fg);
        }

        if (view.Button is not null)
        {
            ChromeForeground(view.Button, fg);
        }

        var style = node.Prop("style") as JsonObject;
        host.SetValue(TextElement.FontWeightProperty, Flag(style, "bold") ? FontWeights.Bold : FontWeights.Normal);
        host.SetValue(TextElement.FontStyleProperty, Flag(style, "italic") ? FontStyles.Italic : FontStyles.Normal);
        host.Opacity = Flag(style, "dim") ? 0.6 : 1;

        var focusable = node.Bool("focusable") != false;
        KeyboardNavigation.SetIsTabStop(view.FocusTarget, focusable);
        if (view.FocusTarget is Control control)
        {
            control.IsTabStop = focusable;
        }

        var label = node.String("label");
        if (label is not null)
        {
            AutomationProperties.SetName(view.FocusTarget, label);
        }
        else
        {
            view.FocusTarget.ClearValue(AutomationProperties.NameProperty);
        }
    }

    /// <summary>
    /// What is seen behind an element: the background of the nearest element around it that paints one,
    /// the surface of the window, not the theme's background, when it is in the content.
    /// </summary>
    private Brush Behind(DependencyObject element)
    {
        for (var parent = LogicalTreeHelper.GetParent(element) ?? VisualTreeHelper.GetParent(element); parent is not null; parent = LogicalTreeHelper.GetParent(parent) ?? (parent is Visual ? VisualTreeHelper.GetParent(parent) : null))
        {
            var brush = parent switch
            {
                CanvasView canvas => canvas.Background,
                Panel panel => panel.Background,
                Border border => border.Background,
                Control control => control.Background,
                _ => null,
            };
            if (brush is SolidColorBrush { Color.A: > 0 } or (not null and not SolidColorBrush))
            {
                return brush;
            }
        }

        return theme.Surface;
    }

    /// <summary>
    /// A child of a canvas: at place, [row, column, columns, rows] in cells of the canvas, cut to clip, in
    /// the same cells. Another node: in the flow of its parent.
    /// </summary>
    private void Place(NodeView view, Node node, DpiScale? dpi = null)
    {
        var host = view.Host;
        if (node.Prop("place") is not JsonArray { Count: 4 } place)
        {
            host.ClearValue(System.Windows.Controls.Canvas.LeftProperty);
            host.ClearValue(System.Windows.Controls.Canvas.TopProperty);
            host.ClearValue(FrameworkElement.WidthProperty);
            host.ClearValue(FrameworkElement.HeightProperty);
            host.ClearValue(UIElement.ClipProperty);
            return;
        }

        var p = place.Select(v => v?.GetValue<int>() ?? 0).ToArray();
        // Its box hides the lines under it, in the canvas's colour; hidden, it is collapsed, no box left
        host.Background ??= (node.Parent is { Kind: "canvas" } canvas ? Theme.Brush(Style(canvas, "bg")) : null) ?? Behind(host);
        // Its edges on the pixels nearest its cells', its size what is between them: layout rounding would round its
        // corner and its size each up, and its far edges past its cells (102 for 101.6 from 101.6, at 100%). At the
        // window's DPI, or the new one given while it changes
        var scale = dpi ?? Dpi();
        var (left, width) = Cells.Snap(p[1], p[2], cell.Width, scale.DpiScaleX);
        var (top, height) = Cells.Snap(p[0], p[3], cell.Height, scale.DpiScaleY);
        System.Windows.Controls.Canvas.SetLeft(host, left);
        System.Windows.Controls.Canvas.SetTop(host, top);
        host.Width = width;
        host.Height = height;
        if (node.Prop("clip") is JsonArray { Count: 4 } clip)
        {
            var c = clip.Select(v => v?.GetValue<int>() ?? 0).ToArray();
            // In the control's own coordinates
            host.Clip = new RectangleGeometry(new Rect((c[1] - p[1]) * cell.Width, (c[0] - p[0]) * cell.Height, Math.Max(0, c[2]) * cell.Width, Math.Max(0, c[3]) * cell.Height));
        }
        else
        {
            host.ClearValue(UIElement.ClipProperty);
        }

        // Nothing of it shown (clipped to nothing, off its place, or a place of no cells): out of the Tab order, as a
        // hidden one is (Common set it for the other cases)
        if (Covers(node) is null)
        {
            KeyboardNavigation.SetIsTabStop(view.FocusTarget, false);
            if (view.FocusTarget is Control control)
            {
                control.IsTabStop = false;
            }
        }
    }

    /// <summary>
    /// The cells of a canvas a child shown over it covers: its clip, cut to its place, else its place, as [row,
    /// column, columns, rows]; null when it covers none (hidden, not placed, or clipped to nothing).
    /// </summary>
    public static (int Row, int Column, int Columns, int Rows)? Covers(Node node)
    {
        if (node.Bool("hidden") == true || node.Prop("place") is not JsonArray { Count: 4 } place)
        {
            return null;
        }

        var p = place.Select(v => v?.GetValue<int>() ?? 0).ToArray();
        var (top, left, right, bottom) = (p[0], p[1], p[1] + Math.Max(0, p[2]), p[0] + Math.Max(0, p[3]));
        if (node.Prop("clip") is JsonArray { Count: 4 } clip)
        {
            var c = clip.Select(v => v?.GetValue<int>() ?? 0).ToArray();
            (top, left, right, bottom) = (Math.Max(top, c[0]), Math.Max(left, c[1]), Math.Min(right, c[1] + Math.Max(0, c[2])), Math.Min(bottom, c[0] + Math.Max(0, c[3])));
        }

        return right > left && bottom > top ? (top, left, right - left, bottom - top) : null;
    }

    /// <summary>A text control placed over a canvas draws in its font, from its cells' edges.</summary>
    private void CanvasFont(Control box, bool placed)
    {
        if (placed)
        {
            box.FontFamily = cell.Family;
            box.FontSize = cell.EmSize;
            box.Padding = new Thickness(0);
            box.BorderThickness = new Thickness(0);
        }
        else
        {
            box.ClearValue(Control.FontFamilyProperty);
            box.ClearValue(Control.FontSizeProperty);
            box.Padding = new Thickness(2);
            box.ClearValue(Control.BorderThicknessProperty);
        }
    }

    private void UpdateSlider(NodeView view, Node node)
    {
        var slider = (Slider)view.Control!;
        view.Range = Stepping.Range(node);
        var step = view.Range.Step;
        var low = view.Range.Min ?? 0;
        var high = Math.Max(low, view.Range.Max ?? 100);
        slider.Minimum = low;
        slider.Maximum = high;
        // Drags snap to the steps from min
        slider.TickFrequency = step;
        slider.SmallChange = step;
        slider.LargeChange = Math.Max(step, (high - low) / 10);
        var value = Stepping.Snap(node.Number("value") ?? low + (high - low) / 2, low, high, step);
        slider.Value = value;
        view.Sent = value;
    }

    private void UpdateLabel(NodeView view, Node node)
    {
        var text = view.Text!;
        var style = node.Prop("style") as JsonObject;
        var decorations = new TextDecorationCollection();
        if (Flag(style, "underline"))
        {
            decorations.Add(TextDecorations.Underline);
        }

        if (Flag(style, "strike"))
        {
            decorations.Add(TextDecorations.Strikethrough);
        }

        text.TextDecorations = decorations;
        text.TextAlignment = node.String("align") switch
        {
            "center" => TextAlignment.Center,
            "right" => TextAlignment.Right,
            _ => TextAlignment.Left,
        };
        text.Inlines.Clear();
        if (node.Prop("runs") is JsonArray runs)
        {
            foreach (var run in runs.OfType<JsonObject>())
            {
                text.Inlines.Add(Run(run));
            }
        }
        else
        {
            text.Text = node.String("text") ?? "";
        }
    }

    /// <summary>A run of the label kind as a WPF Run: colours, bold, dim, italic, underline, strikethrough, reverse.</summary>
    private Run Run(JsonObject run)
    {
        var result = new Run(run["t"]?.GetValue<string>() ?? "");
        var fg = Theme.Brush(run["fg"]?.GetValue<string>());
        var bg = Theme.Brush(run["bg"]?.GetValue<string>());
        if (Flag(run, "r"))
        {
            (fg, bg) = (bg ?? theme.Background, fg ?? theme.Text);
        }

        if (Flag(run, "d"))
        {
            var dim = (fg ?? theme.Text).Clone();
            dim.Opacity = 0.6;
            fg = dim;
        }

        if (fg is not null)
        {
            result.Foreground = fg;
        }

        if (bg is not null)
        {
            result.Background = bg;
        }

        if (Flag(run, "b"))
        {
            result.FontWeight = FontWeights.Bold;
        }

        if (Flag(run, "i"))
        {
            result.FontStyle = FontStyles.Italic;
        }

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
            result.TextDecorations = decorations;
        }

        return result;
    }

    private void UpdateList(NodeView view, Node node)
    {
        var list = (ListBox)view.Control!;
        var multiple = node.Bool("multiple") == true;
        list.Tag = multiple;
        list.SelectionMode = SelectionMode.Single;
        var checkedIndexes = (node.Prop("checked") as JsonArray)?.Select(v => v!.GetValue<int>()).ToHashSet() ?? new HashSet<int>();
        var items = (node.Prop("items") as JsonArray)?.OfType<JsonObject>().ToList() ?? new List<JsonObject>();
        list.Items.Clear();
        for (var i = 0; i < items.Count; i++)
        {
            var row = new StackPanel { Margin = new Thickness(2, 1, 2, 1) };
            row.Children.Add(new TextBlock { Text = items[i]["label"]?.GetValue<string>() ?? "", TextTrimming = TextTrimming.CharacterEllipsis });
            if (items[i]["description"]?.GetValue<string>() is { Length: > 0 } description)
            {
                row.Children.Add(new TextBlock { Text = description, Foreground = theme.Secondary, FontSize = SystemFonts.MessageFontSize * 0.92, TextTrimming = TextTrimming.CharacterEllipsis });
            }

            if (multiple)
            {
                var index = i;
                var box = new CheckBox { Content = row, IsChecked = checkedIndexes.Contains(i), VerticalContentAlignment = VerticalAlignment.Center };
                box.Checked += (_, _) => Checked(view, list);
                box.Unchecked += (_, _) => Checked(view, list);
                list.Items.Add(box);
            }
            else
            {
                list.Items.Add(row);
            }
        }

        var selected = node.Int("selected");
        list.SelectedIndex = selected is { } s && s >= 0 && s < items.Count ? s : -1;
        if (list.SelectedIndex >= 0)
        {
            list.ScrollIntoView(list.SelectedItem);
        }
    }

    private void Checked(NodeView view, ListBox list)
    {
        if (view.Quiet)
        {
            return;
        }

        var indexes = new JsonArray();
        for (var i = 0; i < list.Items.Count; i++)
        {
            if (list.Items[i] is CheckBox { IsChecked: true })
            {
                indexes.Add(i);
            }
        }

        send(view.Id, "select", indexes, true);
    }

    private void UpdateForm(NodeView view, Node node)
    {
        var grid = (Grid)view.Control!;
        var rows = (node.Prop("rows") as JsonArray)?.OfType<JsonObject>().ToList() ?? new List<JsonObject>();
        // Everything but the values: the same shape keeps the controls.
        var shape = string.Join("\u0001", rows.Select(r =>
        {
            var copy = (JsonObject)r.DeepClone();
            copy.Remove("value");
            return copy.ToJsonString();
        }));
        if (shape != view.FormShape)
        {
            view.FormShape = shape;
            grid.Children.Clear();
            grid.RowDefinitions.Clear();
            for (var i = 0; i < rows.Count; i++)
            {
                BuildFormRow(view, grid, i, rows[i]);
            }
        }

        // Same rows: values only, so a field being typed in keeps its caret.
        var fg = Theme.Brush(Style(node, "fg"));
        foreach (UIElement child in grid.Children)
        {
            // The application's text colour on the pop-ups, over the theme's style (see Common)
            if (child is ComboBox chrome)
            {
                ChromeForeground(chrome, fg);
            }

            if (child is FrameworkElement { Tag: string id } control && rows.FirstOrDefault(r => r["id"]?.GetValue<string>() == id) is { } row)
            {
                var value = row["value"]?.GetValue<string>() ?? "";
                if (control is ComboBox combo)
                {
                    combo.SelectedItem = value;
                }
                else if (control is TextBox box && !box.IsKeyboardFocusWithin)
                {
                    box.Text = value;
                    box.Uid = value;
                }
            }
        }
    }

    private void BuildFormRow(NodeView view, Grid grid, int index, JsonObject row)
    {
        var id = row["id"]?.GetValue<string>() ?? "";
        grid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        var label = new StackPanel { Margin = new Thickness(0, 2, 12, 2), VerticalAlignment = VerticalAlignment.Center };
        label.Children.Add(new TextBlock { Text = row["label"]?.GetValue<string>() ?? "" });
        if (row["description"]?.GetValue<string>() is { Length: > 0 } description)
        {
            label.Children.Add(new TextBlock { Text = description, Foreground = theme.Secondary, FontSize = SystemFonts.MessageFontSize * 0.92 });
        }

        Grid.SetRow(label, index);
        grid.Children.Add(label);

        FrameworkElement control;
        if (row["choices"] is JsonArray choices)
        {
            var combo = new ComboBox { ItemsSource = choices.Select(c => c!.GetValue<string>()).ToList(), MinWidth = 120, HorizontalAlignment = HorizontalAlignment.Left };
            combo.SelectionChanged += (_, _) =>
            {
                if (!view.Quiet && combo.SelectedItem is string value)
                {
                    send(view.Id, "setting", new JsonObject { ["id"] = id, ["value"] = value }, true);
                }
            };
            control = combo;
        }
        else
        {
            // A readOnly row, which the keyboard cannot change either, is shown, not edited
            var box = new TextBox { Padding = new Thickness(2), IsReadOnly = row["readOnly"] is JsonValue readOnly && readOnly.TryGetValue<bool>(out var isReadOnly) && isReadOnly };
            void Commit()
            {
                // Uid holds the last committed value.
                if (!view.Quiet && !box.IsReadOnly && box.Text != box.Uid)
                {
                    box.Uid = box.Text;
                    send(view.Id, "setting", new JsonObject { ["id"] = id, ["value"] = box.Text }, true);
                }
            }

            box.LostKeyboardFocus += (_, _) => Commit();
            box.KeyDown += (_, e) =>
            {
                if (e.Key == Key.Enter)
                {
                    Commit();
                    e.Handled = true;
                }
            };
            control = box;
        }

        control.Tag = id;
        control.Margin = new Thickness(0, 2, 0, 2);
        AutomationProperties.SetName(control, row["label"]?.GetValue<string>() ?? id);
        Grid.SetRow(control, index);
        Grid.SetColumn(control, 1);
        grid.Children.Add(control);
    }

    private void UpdateTabHeaders(NodeView view, Node node)
    {
        var tabs = (TabControl)view.Control!;
        var labels = (node.Prop("tabs") as JsonArray)?.Select(t => t?.GetValue<string>() ?? "").ToList() ?? new List<string>();
        var fg = Theme.Brush(Style(node, "fg"));
        for (var i = 0; i < tabs.Items.Count; i++)
        {
            var item = (TabItem)tabs.Items[i];
            item.Header = i < labels.Count ? labels[i] : $"Tab {i + 1}";
            // The application's text colour on the headers, over the theme's style (the pages inherit it from the tabs)
            ChromeForeground(item, fg);
        }
    }

    /// <summary>
    /// The application's text colour on a control whose style sets one, else the style's; on a pop-up, on the items
    /// of its drop-down too, which the theme's ComboBoxItem style colours (see Theme.Apply).
    /// </summary>
    private static void ChromeForeground(Control control, Brush? fg)
    {
        if (fg is not null)
        {
            control.Foreground = fg;
        }
        else
        {
            control.ClearValue(Control.ForegroundProperty);
        }

        if (control is ComboBox combo)
        {
            if (fg is not null)
            {
                // Not based on the theme's item style, which sets the text colour only (the stock template's default
                // style applies anyway) and which the pop-up finds another of before it is in the window. Made again only
                // when the colour changes: a new one makes the items of an open list again, which loses the highlighted one
                if (combo.ItemContainerStyle is not { Setters: [Setter { Value: Brush had } setter] } || setter.Property != Control.ForegroundProperty || !SameColour(had, fg))
                {
                    var items = new Style(typeof(ComboBoxItem));
                    items.Setters.Add(new Setter(Control.ForegroundProperty, fg));
                    items.Seal();
                    combo.ItemContainerStyle = items;
                }
            }
            else
            {
                combo.ClearValue(ItemsControl.ItemContainerStyleProperty);
            }
        }
    }

    /// <summary>Two brushes of one solid colour (Theme.Brush makes a new one for each update).</summary>
    private static bool SameColour(Brush a, Brush b) => ReferenceEquals(a, b) || (a is SolidColorBrush x && b is SolidColorBrush y && x.Color == y.Color && x.Opacity == y.Opacity);

    private void Clear(NodeView view)
    {
        if (view.Panel is not null)
        {
            view.Panel.Children.Clear();
        }
        else if (view.Control is TabControl tabs)
        {
            foreach (TabItem item in tabs.Items)
            {
                item.Content = null;
            }
        }
    }

    private void Fill(NodeView view, Node node)
    {
        var children = node.Children.Where(c => views.ContainsKey(c.Id)).Select(c => views[c.Id].Host).ToList();
        if (view.Overlay is not null)
        {
            // Only what left goes, and what moved: a control being typed in keeps its focus and composition
            Arrange(view.Overlay, children);
            return;
        }

        foreach (var child in children)
        {
            Detach(child);
        }

        if (view.Panel is not null)
        {
            foreach (var child in children)
            {
                view.Panel.Children.Add(child);
            }
        }
        else if (view.Control is TabControl tabs)
        {
            view.Quiet = true;
            try
            {
                while (tabs.Items.Count > children.Count)
                {
                    tabs.Items.RemoveAt(tabs.Items.Count - 1);
                }

                while (tabs.Items.Count < children.Count)
                {
                    tabs.Items.Add(new TabItem());
                }

                for (var i = 0; i < children.Count; i++)
                {
                    ((TabItem)tabs.Items[i]).Content = children[i];
                }

                UpdateTabHeaders(view, node);
                var active = node.Int("active") ?? 0;
                tabs.SelectedIndex = active >= 0 && active < children.Count ? active : children.Count > 0 ? 0 : -1;
            }
            finally
            {
                view.Quiet = false;
            }
        }
    }

    /// <summary>Makes a canvas's controls those given, in order, moving only those out of place.</summary>
    private static void Arrange(System.Windows.Controls.Canvas overlay, List<Border> children)
    {
        foreach (var gone in overlay.Children.OfType<UIElement>().Where(c => !children.Contains(c)).ToList())
        {
            overlay.Children.Remove(gone);
        }

        for (var i = 0; i < children.Count; i++)
        {
            if (i < overlay.Children.Count && overlay.Children[i] == children[i])
            {
                continue;
            }

            if (overlay.Children.Contains(children[i]))
            {
                overlay.Children.Remove(children[i]);
            }
            else
            {
                Detach(children[i]);
            }

            overlay.Children.Insert(i, children[i]);
        }
    }

    /// <summary>Takes an element out of whatever holds it: a panel, a tab, a border.</summary>
    private static void Detach(UIElement element)
    {
        switch (LogicalTreeHelper.GetParent(element) ?? VisualTreeHelper.GetParent(element))
        {
            case Panel panel:
                panel.Children.Remove(element);
                break;
            case ContentControl content when content.Content == element:
                content.Content = null;
                break;
            case Decorator decorator when decorator.Child == element:
                decorator.Child = null;
                break;
            case ContentPresenter presenter when presenter.Content == element:
                presenter.Content = null;
                break;
        }
    }

    private static string? Style(Node node, string name) => (node.Prop("style") as JsonObject)?[name] is JsonValue v && v.TryGetValue<string>(out var s) ? s : null;

    private static bool Flag(JsonObject? o, string name) => o?[name] is JsonValue v && v.TryGetValue<bool>(out var b) && b;
}
