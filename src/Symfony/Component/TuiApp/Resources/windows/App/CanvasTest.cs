using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Text.Json.Nodes;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Automation;
using System.Windows.Automation.Peers;
using System.Windows.Automation.Provider;
using System.Windows.Controls;
using System.Windows.Controls.Primitives;
using System.Windows.Input;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Threading;
using TuiAppHost.Core;

namespace TuiAppHost;

/// <summary>
/// --canvas-test (HOSTS.md): with tools/fake-app.php and FAKE_APP_CANVAS=1, checks the native controls
/// placed over canvas node 21, drives them as a user would, hands Tab to the application, composes and
/// commits text through the input method path of the canvas, widens the window and waits for the rects,
/// then closes. Each step waits up to 5 s for label node 2 to read what the application got. Exit 0 once
/// the application sent bye and exited; 1, saying why, on any failure or after 60 s (as on macOS and Linux).
/// </summary>
public sealed class CanvasTest
{
    private static readonly TimeSpan Overall = TimeSpan.FromSeconds(60);
    private static readonly TimeSpan LabelWait = TimeSpan.FromSeconds(5);
    private static readonly int[] Controls = { 30, 31, 32, 33, 34, 35 };

    /// <summary>patch-native-inputs, sent to a host that lists their kinds: password, number, slider, listbox.</summary>
    private static readonly int[] Inputs = { 36, 37, 38, 39 };

    private static bool NativeInputs => new[] { "password", "number", "slider", "listbox" }.All(HostWindow.Capabilities.Contains);

    private readonly HostWindow window;
    private readonly Application application;
    private readonly Stopwatch clock = Stopwatch.StartNew();
    private readonly DispatcherTimer deadline = new() { Interval = Overall };
    private string step = "waiting for the controls of canvas node 21";
    private bool started;
    private bool checkedAll;
    private bool done;

    public CanvasTest(HostWindow window, Application application)
    {
        this.window = window;
        this.application = application;
        deadline.Tick += (_, _) => Fail($"timed out after {Overall.TotalSeconds:0} s, {step}");
        window.Patched += Patched;
        window.Failed += Fail;
        window.Closed += (_, _) => Closed();
    }

    public void Start()
    {
        // Which build runs where: an x64 host on Windows on Arm runs emulated
        Say($"started: a {System.Runtime.InteropServices.RuntimeInformation.ProcessArchitecture} process on {System.Runtime.InteropServices.RuntimeInformation.OSArchitecture} Windows");
        // Before the window shows: no layout made again under the application's first patches
        PutBackLeftScale();
        deadline.Start();
    }

    private void Patched()
    {
        if (started || !Controls.All(id => window.Views.Views.ContainsKey(id)) || !window.Views.Views.ContainsKey(21))
        {
            return;
        }

        started = true;
        window.Dispatcher.BeginInvoke(DispatcherPriority.Background, async () =>
        {
            try
            {
                await Run();
            }
            catch (Abort)
            {
            }
            catch (Exception exception)
            {
                Fail($"{step}: {exception}");
            }
        });
    }

    /// <summary>
    /// The display's scale step from before the DPI step's change, while it is changed: Windows applies a scale only once
    /// saved in the profile, so a run killed meanwhile leaves the scale raised, also after a restart; the next run puts it
    /// back from this file before anything else (or delete HKCU\Control Panel\Desktop\PerMonitorSettings\&lt;monitor&gt;\DpiValue).
    /// </summary>
    private static readonly string ScaleMarker = Path.Combine(Path.GetTempPath(), "tuiapp-canvas-scale-step.txt");

    private async Task Run()
    {

        // 1. The controls, placed and clipped
        step = "waiting for \"canvas ready\"";
        await Label("canvas ready");
        Check(HostWindow.Capabilities.Contains("canvas-controls"), "hello does not list canvas-controls");
        window.Activate();
        window.UpdateLayout();
        var canvas = window.Views.Views[21];
        var surface = (Grid)canvas.Host.Child;
        Check(surface.Children.IndexOf(canvas.Overlay!) > surface.Children.IndexOf(canvas.Canvas!), "the controls are not above the canvas surface");
        var cell = window.Cell;
        var placed = NativeInputs ? Controls.Concat(Inputs).ToArray() : Controls;
        Check(placed.All(window.Views.Views.ContainsKey), "the native inputs 36 to 39 did not come");
        foreach (var id in placed)
        {
            step = $"checking where node {id} is";
            var view = window.Views.Views[id];
            Check(view.Control is not null && VisualTreeHelper.GetParent(view.Host) == canvas.Overlay, $"node {id} has no native control over the canvas");
            var place = Ints(window.Tree.Find(id)!.Prop("place"));
            // The overlay is the surface's sibling, at its corner: not its ancestor
            var bounds = view.Host.TransformToVisual(canvas.Canvas!).TransformBounds(new Rect(view.Host.RenderSize));
            var expected = new Rect(place[1] * cell.Width, place[0] * cell.Height, place[2] * cell.Width, place[3] * cell.Height);
            Check(Near(bounds, expected), $"node {id} is at {bounds}, not at {expected}");
            // What a click at the middle of what is shown of it reaches: its control
            var shown = window.Tree.Find(id)!.Prop("clip") is JsonArray ? Ints(window.Tree.Find(id)!.Prop("clip")) : place;
            var middle = canvas.Canvas!.TranslatePoint(new Point((shown[1] + shown[2] / 2.0) * cell.Width, (shown[0] + shown[3] / 2.0) * cell.Height), canvas.Host);
            var hit = VisualTreeHelper.HitTest(canvas.Host, middle)?.VisualHit;
            Check(window.Views.NodeOf(hit) == id, $"a click at the middle of node {id} reaches {(hit is null ? "nothing" : $"node {window.Views.NodeOf(hit)?.ToString() ?? "none"} ({hit.GetType().Name})")}");
        }

        var clip = (window.Views.Views[35].Host.Clip as RectangleGeometry)?.Rect;
        Check(clip is { } c && Near(c, new Rect(0, 0, 30 * cell.Width, cell.Height)), $"node 35 is clipped to {clip?.ToString() ?? "nothing"}, not to its first row");
        Say("the controls are over the canvas at their place, node 35 clipped to its first row: " + string.Join(", ", placed.Select(id => $"{id}={window.Views.Views[id].Control!.GetType().Name}")));
        PlacedControlsFit(canvas);
        ListBoxOfAnotherTemplate();

        // 2. The controls, as a user acts on them
        var field = window.Views.Views[30].Field!;
        step = "typing into node 30";
        field.Focus();
        // It reads "Ada" from the props: typed again from empty, as a user would
        field.Text = "";
        field.Text = "Ada";
        field.CaretIndex = 3;
        await Label("event 30 change \"Ada\"");

        // 3. maxLength, in code points where a TextBox counts UTF-16 units: a paste is cut to the room left,
        // a key at the limit is refused, and a value at it can still be edited
        step = "pasting past the maxLength of node 30";
        Check(window.Views.Views[30].MaxLength == 40, $"node 30 has maxLength {window.Views.Views[30].MaxLength?.ToString() ?? "none"}, not 40");
        const string digits = "0123456789012345678901234567890123456789";
        field.CaretIndex = field.Text.Length;
        await SetClipboard(digits);
        ApplicationCommands.Paste.Execute(null, field);
        await Label($"event 30 change \"Ada{digits[..37]}\"");
        step = "typing z into node 30, full";
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, field, "z"));
        await Quiet($"event 30 change \"Ada{digits[..37]}\"");
        Check(field.Text == "Ada" + digits[..37], $"node 30 holds \"{field.Text}\" after a z typed at its limit");
        step = "typing a b c d at the end of node 35";
        var memo = window.Views.Views[35].Field!;
        Check(window.Views.Views[35].MaxLength == 8, $"node 35 has maxLength {window.Views.Views[35].MaxLength?.ToString() ?? "none"}, not 8");
        memo.Focus();
        memo.CaretIndex = memo.Text.Length;
        foreach (var letter in new[] { "a", "b", "c", "d" })
        {
            TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, memo, letter));
        }

        await Label("event 35 change \"Notesabc\"");
        await Quiet("event 35 change \"Notesabc\"");
        // A line break goes out as "\n", one character, where a TextBox that accepts Enter inserts "\r\n"
        step = "pressing Enter in node 35";
        memo.SelectAll();
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, memo, "x"));
        var memoSource = PresentationSource.FromVisual(memo) ?? throw new InvalidOperationException("node 35 is not on screen");
        InputManager.Current.ProcessInput(new KeyEventArgs(Keyboard.PrimaryDevice, memoSource, Environment.TickCount, Key.Enter) { RoutedEvent = Keyboard.PreviewKeyDownEvent });
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, memo, "y"));
        await Label("event 35 change \"x\\ny\"");
        Say("maxLength: 40 digits pasted into 30 cut to 37, z refused; a b c d typed into 35 cut to abc; Enter in 35 sent \\n");
        await OverLimit(field, digits);

        step = "clicking node 31";
        Provider<IToggleProvider>(window.Views.Views[31], PatternInterface.Toggle).Toggle();
        await Label("event 31 toggle false");

        step = "clicking node 32";
        Provider<ISelectionItemProvider>(window.Views.Views[32], PatternInterface.SelectionItem).Select();
        await Label("event 32 toggle true");

        step = "choosing in node 33";
        ((ComboBox)window.Views.Views[33].Control!).SelectedIndex = 2;
        await Label("event 33 select 2");

        step = "pressing node 34";
        Provider<IInvokeProvider>(window.Views.Views[34], PatternInterface.Invoke).Invoke();
        await Label("event 34 press");

        if (NativeInputs)
        {
            await Password();
            await Number();
            await Slider();
            await Listbox();
            await CaptureInputs();
        }

        await FocusChain(canvas);

        // 4. Tab, handed to the application, which moves the focus; not while an input method composes in node 30
        step = "pressing Tab while composing in node 30";
        field.Focus();
        Check(field.IsKeyboardFocused, "node 30 does not take the keyboard focus (is the window active?)");
        var source = PresentationSource.FromVisual(field) ?? throw new InvalidOperationException("node 30 is not on screen");
        var labelBefore = Text();
        field.Select(field.Text.Length, 0);
        field.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new Composing(field, "に")) { RoutedEvent = TextCompositionManager.PreviewTextInputStartEvent });
        field.SelectedText = "に";
        var composingTab = new KeyEventArgs(Keyboard.PrimaryDevice, source, Environment.TickCount, Key.Tab) { RoutedEvent = Keyboard.PreviewKeyDownEvent };
        InputManager.Current.ProcessInput(composingTab);
        await Task.Delay(300);
        Check(!composingTab.Handled && Text() == labelBefore, $"Tab while composing in node 30 went to the application: node 2 reads \"{Text()}\"");
        // A real input method takes the key (ImeProcessed); this one went on to WPF, which moved the focus
        field.Focus();
        field.Select(field.Text.Length - 1, 1);
        field.SelectedText = "";
        Say("Tab while composing in node 30 was the input method's: nothing sent");
        step = "pressing Tab in node 30";
        InputManager.Current.ProcessInput(new KeyEventArgs(Keyboard.PrimaryDevice, source, Environment.TickCount, Key.Tab) { RoutedEvent = Keyboard.PreviewKeyDownEvent });
        InputManager.Current.ProcessInput(new KeyEventArgs(Keyboard.PrimaryDevice, source, Environment.TickCount, Key.Tab) { RoutedEvent = Keyboard.PreviewKeyUpEvent });
        Check(field.IsKeyboardFocused, $"Tab moved the native focus to {Keyboard.FocusedElement}");
        await Label("key tab");
        step = "waiting for the application to focus node 34";
        var button = window.Views.Views[34].Control!;
        await Until(() => button.IsKeyboardFocused, "node 34 did not take the focus");
        Say("Tab went to the application, which focused node 34");

        // 5. The input method of the canvas: composing, then committing. The canvas's focus is its Entry, a
        // TextBox over the cursor cell, where the input method composes and opens its windows
        step = "composing in the canvas";
        var surfaceView = canvas.Canvas!;
        var entry = surfaceView.Entry;
        entry.Focus();
        Check(entry.IsKeyboardFocused, "the canvas does not take the keyboard focus");
        var (row, column) = surfaceView.PreeditCell;
        var at = entry.TransformToVisual(surfaceView).Transform(new Point(0, 0));
        Check(Near(new Rect(at, new Size(cell.Width, entry.ActualHeight)), new Rect(column * cell.Width, row * cell.Height, cell.Width, cell.Height)), $"the canvas's entry is at {at}, {entry.ActualHeight} high, not over the cell {row},{column}");
        Say($"the canvas's entry is over the cursor cell {row},{column}, where an input method asks for the composition's place");
        // A dead key: the composition says it, nothing is in the TextBox
        entry.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new Composing(entry, "´")) { RoutedEvent = TextCompositionManager.PreviewTextInputStartEvent });
        Check(surfaceView.Preedit == "´", $"the canvas shows \"{surfaceView.Preedit}\" as composed, not \"´\"");
        // An input method's text store: the composition in the TextBox
        entry.Text = "にほ";
        Check(surfaceView.Preedit == "にほ", $"the canvas shows \"{surfaceView.Preedit}\" as composed, not \"にほ\"");
        var canvasTab = new KeyEventArgs(Keyboard.PrimaryDevice, PresentationSource.FromVisual(entry)!, Environment.TickCount, Key.Tab) { RoutedEvent = Keyboard.PreviewKeyDownEvent };
        InputManager.Current.ProcessInput(canvasTab);
        Check(!canvasTab.Handled, "Tab while composing in the canvas went to the application");
        // A real input method takes the key; this one went on to WPF, which moved the focus, and the composition ended
        entry.Focus();
        entry.Text = "にほ";
        Check(surfaceView.Preedit == "にほ", $"the canvas shows \"{surfaceView.Preedit}\" as composed, not \"にほ\"");
        await Task.Delay(300);
        Check(Text() == "key tab", $"what is composed reached the application: node 2 reads \"{Text()}\"");
        step = "committing in the canvas";
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, entry, "é"));
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, entry, "日本"));
        await Label("typed é日本");
        await Until(() => entry.Text.Length == 0, $"the canvas's entry keeps \"{entry.Text}\" after the commit");
        Check(surfaceView.Preedit.Length == 0, "the composed text is still shown after the commit");
        // A plain key: KeyDown sends nothing, its TextInput types it, once
        step = "typing a in the canvas";
        var surfaceSource = PresentationSource.FromVisual(entry) ?? throw new InvalidOperationException("the canvas is not on screen");
        InputManager.Current.ProcessInput(new KeyEventArgs(Keyboard.PrimaryDevice, surfaceSource, Environment.TickCount, Key.A) { RoutedEvent = Keyboard.PreviewKeyDownEvent });
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, entry, "a"));
        InputManager.Current.ProcessInput(new KeyEventArgs(Keyboard.PrimaryDevice, surfaceSource, Environment.TickCount, Key.A) { RoutedEvent = Keyboard.PreviewKeyUpEvent });
        await Label("typed é日本a");
        await Task.Delay(300);
        Check(Text() == "typed é日本a", $"the key went out twice, or more came: node 2 reads \"{Text()}\"");
        Check(entry.Text.Length == 0, $"the canvas's entry keeps \"{entry.Text}\"");
        // One key commits and starts a new composition (Korean, Japanese typed on after a conversion): as WPF's text
        // store does, the commit in the TextBox, PreviewTextInput, then PreviewTextInputStart and the composition
        // after it, before the edit is over
        step = "committing 漢 and composing じ after it in the canvas";
        entry.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new Composing(entry, "かん")) { RoutedEvent = TextCompositionManager.PreviewTextInputStartEvent });
        entry.Text = "かん";
        entry.Text = "漢";
        entry.Select(1, 0);
        entry.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new TextComposition(InputManager.Current, entry, "漢")) { RoutedEvent = TextCompositionManager.PreviewTextInputEvent });
        entry.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new Composing(entry, "じ")) { RoutedEvent = TextCompositionManager.PreviewTextInputStartEvent });
        entry.SelectedText = "じ";
        entry.Select(2, 0);
        Check(surfaceView.Preedit == "じ", $"the canvas shows \"{surfaceView.Preedit}\" as composed after the commit of 漢, not \"じ\"");
        await Label("typed é日本a漢");
        await Task.Delay(300);
        Check(Text() == "typed é日本a漢", $"the commit went out twice, or the composition: node 2 reads \"{Text()}\"");
        Check(entry.Text == "漢じ" && surfaceView.Preedit == "じ", $"the composition after the commit is gone: the canvas's entry has \"{entry.Text}\", the canvas shows \"{surfaceView.Preedit}\"");
        // The input method opens its candidate list at the composition in the TextBox: at the cell, not after the commit
        window.UpdateLayout();
        var (preeditRow, preeditColumn) = surfaceView.PreeditCell;
        var origin = surfaceView.CompositionOrigin;
        Check(Math.Abs(origin.X - preeditColumn * cell.Width) <= 1.5 && Math.Abs(origin.Y - preeditRow * cell.Height) <= 1.5, $"the composition after the commit of 漢 starts at {origin} in the canvas's entry, not at its cell ({preeditColumn * cell.Width}, {preeditRow * cell.Height})");
        Say($"the composition after the commit starts at {origin.X:0.#}, {origin.Y:0.#}: the cell's corner");
        // Taken back (Escape): the commit goes too
        entry.Text = "漢";
        await Until(() => entry.Text.Length == 0, $"the canvas's entry keeps \"{entry.Text}\" once the composition was taken back");
        Check(surfaceView.Preedit.Length == 0, $"the canvas shows \"{surfaceView.Preedit}\" once the composition was taken back");
        Say("a commit and a composition the same key started: the commit sent once, the composition kept and shown alone, then the TextBox emptied");
        Capture("canvas.png");
        await AppColours();
        await MouseOnCanvas(canvas);
        await CursorOverCanvas(canvas);
        await ContextMenuOnCanvas(canvas);

        // 7. Rects after a resize
        step = "widening the window";
        var before = Width(canvas);
        window.Width += 10 * cell.Width;
        window.UpdateLayout();
        var after = Width(canvas);
        Check(after > before, $"node 21 is {after} cells wide after the window widened, {before} before");
        await Label($"rects {after}");
        Say($"node 21 went from {before} to {after} cells, and the rects said so");

        await DpiChangeWhileOpen(canvas);

        // 7. Close
        checkedAll = true;
        step = "closing: waiting for close-reply, bye and the exit";
        Say("closing the window");
        window.Close();
    }

    /// <summary>
    /// A value past maxLength: once 35 reads "Notesabc", the application lowers node 30's to 30 (it has 40
    /// characters, which the update keeps: the user typed them). A selection may be replaced as long as the value
    /// gets no longer, and a key at the end is refused. Then an input method composes with room for one
    /// character: the composition is kept whole, and sent to no one, and its commit is cut to what fits.
    /// </summary>
    private async Task OverLimit(TextBox field, string digits)
    {
        step = "waiting for the maxLength of node 30 to go down to 30";
        await Until(() => window.Views.Views[30].MaxLength == 30, $"node 30 has maxLength {window.Views.Views[30].MaxLength?.ToString() ?? "none"}, not 30");
        var full = "Ada" + digits[..37];
        Check(field.Text == full, $"the update of node 30's maxLength put back \"{field.Text}\", not what was typed");
        Check(window.Tree.Find(30)!.String("value") == full, "node 30's value prop is not what was typed");
        Say("node 30 kept what was typed when its maxLength went down to 30: the user's value is its prop");
        field.Focus();
        var kept = "Ada" + digits[..32];
        step = "pasting XYZ over the last 5 of the 40 characters of node 30";
        field.Select(field.Text.Length - 5, 5);
        await SetClipboard("XYZ");
        ApplicationCommands.Paste.Execute(null, field);
        await Label($"event 30 change \"{kept}XYZ\"");
        step = "pasting LMNOP over the last 2 of the 38 characters of node 30";
        field.Select(field.Text.Length - 2, 2);
        await SetClipboard("LMNOP");
        ApplicationCommands.Paste.Execute(null, field);
        await Label($"event 30 change \"{kept}XLM\"");
        step = "typing past the maxLength of node 30, its value past it";
        field.Select(field.Text.Length, 0);
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, field, "z"));
        await Quiet($"event 30 change \"{kept}XLM\"");
        Check(field.Text == kept + "XLM", $"typing z at the end of node 30 (38 characters, maxLength 30) made it \"{field.Text}\"");
        Say("node 30, past its maxLength: XYZ replaced 5 characters, LM of LMNOP 2, a typed z nothing");

        step = "deleting the last 9 characters of node 30";
        field.Select(field.Text.Length - 9, 9);
        System.Windows.Documents.EditingCommands.Backspace.Execute(null, field);
        var room = "Ada" + digits[..26];
        await Label($"event 30 change \"{room}\"");
        // An update of another prop keeps the caret and the selection
        field.Select(3, 4);
        Update(30, new JsonObject { ["label"] = "Name" });
        Check(field.Text == room && field.SelectionStart == 3 && field.SelectionLength == 4, $"an update of node 30's label made it \"{field.Text}\", selected from {field.SelectionStart}, {field.SelectionLength} long");

        // As WPF's text store does for an input method: PreviewTextInputStart, the composition put in the
        // TextBox, then its commit in place of it, then PreviewTextInput with the commit
        step = "composing in node 30 with room for one character";
        field.Select(field.Text.Length, 0);
        field.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new Composing(field, "に")) { RoutedEvent = TextCompositionManager.PreviewTextInputStartEvent });
        field.SelectedText = "にほん";
        field.Select(field.Text.Length, 0);
        // The application updates another prop: the composition stays
        Update(30, new JsonObject { ["label"] = "Name" });
        await Quiet($"event 30 change \"{room}\"");
        Check(field.Text == room + "にほん", $"the composition にほん in node 30 is not whole (an update of its label came): it has \"{field.Text}\"");
        Say("node 30 shows the composition にほん whole past its maxLength, and sent nothing");
        step = "committing 日本 in node 30 with room for one character";
        field.Select(room.Length, 3);
        field.SelectedText = "日本";
        field.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new TextComposition(InputManager.Current, field, "日本")) { RoutedEvent = TextCompositionManager.PreviewTextInputEvent });
        await Label($"event 30 change \"{room}日\"");
        await Quiet($"event 30 change \"{room}日\"");
        Check(field.Text == room + "日", $"after committing 日本, node 30 has \"{field.Text}\"");
        Say("node 30 took 日 of the committed 日本: 30 characters");

        // A commit longer than the room in UTF-16 units, in code points: 29 x and two emoji (33 units) over all of it
        step = "committing over the whole of node 30";
        field.SelectAll();
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, field, new string('x', 29) + "😀😀"));
        await Label($"event 30 change \"{new string('x', 29)}😀\"");
        Say("a commit of 29 x and two emoji over the whole of node 30 cut to one emoji: 30 code points, 31 UTF-16 units");
        await TypeOn(field);
        await Overtype(field);
    }

    /// <summary>
    /// One key commits and starts a new composition (Korean 한 then ㄱ, Japanese typed on after a conversion), in node
    /// 30 (maxLength 30) with room for one character, between the x and the y of 28 x and a y: the commit, yx, is cut
    /// where it is, to y (its common ends with the text before would put it after the y); the new composition is not
    /// sent, cut, or wiped by an update of another prop, and the x cut stays until it ends.
    /// </summary>
    private async Task TypeOn(TextBox field)
    {
        step = "setting node 30 to 28 x and a y";
        var xs = new string('x', 28);
        field.Focus();
        field.Text = xs + "y";
        await Label($"event 30 change \"{xs}y\"");
        step = "committing yx between the x and the y of node 30 and composing か after it";
        field.Select(28, 0);
        field.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new Composing(field, "yx")) { RoutedEvent = TextCompositionManager.PreviewTextInputStartEvent });
        field.SelectedText = "yx";
        field.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new TextComposition(InputManager.Current, field, "yx")) { RoutedEvent = TextCompositionManager.PreviewTextInputEvent });
        field.RaiseEvent(new TextCompositionEventArgs(Keyboard.PrimaryDevice, new Composing(field, "か")) { RoutedEvent = TextCompositionManager.PreviewTextInputStartEvent });
        field.Select(30, 0);
        field.SelectedText = "か";
        field.Select(31, 0);
        // Before the commit is settled, and after
        Update(30, new JsonObject { ["label"] = "Name" });
        await Label($"event 30 change \"{xs}yy\"");
        Update(30, new JsonObject { ["label"] = "Name" });
        await Quiet($"event 30 change \"{xs}yy\"");
        Check(field.Text == xs + "yxかy", $"node 30 has \"{field.Text}\" while composing か after the commit (28 x, y, the x cut, か, y expected)");
        Check(window.Tree.Find(30)!.String("value") == xs + "yy", $"node 30's value prop is \"{window.Tree.Find(30)!.String("value")}\"");
        step = "taking back the composition か in node 30";
        field.Select(30, 1);
        field.SelectedText = "";
        await Until(() => field.Text == xs + "yy", $"node 30 has \"{field.Text}\" once the composition was taken back, not 28 x and yy");
        Check(field.CaretIndex == 29, $"the caret of node 30 is at {field.CaretIndex}, not after the y kept of the commit (29)");
        await Quiet($"event 30 change \"{xs}yy\"");
        Say("node 30: a commit and a composition the same key started: yx cut where it was to y and sent, the composition kept through updates of its label, the x cut gone with it");
    }

    /// <summary>Overtype (Insert) in node 30, full: a key replaces the character after the caret, which makes its room.</summary>
    private async Task Overtype(TextBox field)
    {
        step = "setting node 30 to 28 x and yy, full";
        var xs = new string('x', 28);
        if (field.Text != xs + "yy")
        {
            field.Text = xs + "yy";
            await Label($"event 30 change \"{xs}yy\"");
        }

        step = "typing Q in overtype mode at the start of node 30, full";
        System.Windows.Documents.EditingCommands.ToggleInsert.Execute(null, field);
        try
        {
            field.Select(0, 0);
            TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, field, "Q"));
            await Label($"event 30 change \"Q{xs[1..]}yy\"");
            Check(field.Text == "Q" + xs[1..] + "yy", $"node 30 has \"{field.Text}\" after Q typed over its first x");
        }
        finally
        {
            System.Windows.Documents.EditingCommands.ToggleInsert.Execute(null, field);
        }

        Say("node 30, full, in overtype mode: Q typed over its first x");
    }

    /// <summary>
    /// The focus chain: button 34 moved above the others and popup 33 clipped to nothing, as a patch would, Tab
    /// (WPF's own move, as the canvas hands the Tab key to the application) goes from the canvas through its controls
    /// in node order, skipping 33, then out of the canvas; Shift+Tab the other way. The canvas leaves the cells of the
    /// controls undrawn, and draws again those 34 left. Then they are put back.
    /// </summary>
    private async Task FocusChain(NodeView canvas)
    {
        step = "walking the focus chain of canvas 21";
        var surface = canvas.Canvas!;
        Check(!surface.Draws(7, 12) && surface.Draws(1, 3), "the canvas draws under button 34 at its place, or not where nothing is");
        var lines = window.Tree.Find(21)!.Prop("lines")?.DeepClone();
        var rule = new JsonArray(Enumerable.Range(0, 12).Select(_ => (JsonNode)new JsonArray(new JsonObject { ["t"] = new string('─', 60) })).ToArray());
        Update(21, new JsonObject { ["lines"] = rule });
        Capture("covered.png");
        Update(34, new JsonObject { ["place"] = new JsonArray(1, 0, 8, 1) });
        Update(33, new JsonObject { ["clip"] = new JsonArray(5, 10, 0, 1) });
        window.UpdateLayout();
        // Drawn on the screen before the capture
        await Task.Delay(300);
        Check(surface.Draws(7, 12) && !surface.Draws(1, 3) && surface.Draws(5, 12), "the canvas does not draw the cells button 34 left, or draws under its new place, or not under 33 clipped to nothing");
        Capture("covered-moved.png");
        var expected = NativeInputs ? new[] { 30, 31, 32, 34, 35, 36, 37, 38, 39 } : new[] { 30, 31, 32, 34, 35 };
        try
        {
            var forward = Walk(canvas.Canvas!.Entry, FocusNavigationDirection.Next, out var after);
            Check(forward.SequenceEqual(expected) && after is > 0 and not 21 and not (>= 30 and <= 39), $"Tab from the canvas went through nodes {string.Join(" ", forward)}, then {(after == 0 ? "the menu bar" : after?.ToString() ?? "nothing")}, not {string.Join(" ", expected)}, then out of the canvas to the next node (around to the first, the canvas being the last; not the menu bar)");
            var backward = Walk(window.Views.Views[expected[^1]].FocusTarget, FocusNavigationDirection.Previous, out var before);
            Check(backward.SequenceEqual(expected.Reverse().Skip(1)) && before == 21, $"Shift+Tab from node {expected[^1]} went through nodes {string.Join(" ", backward)}, then {before?.ToString() ?? "nothing"}, not the other way, then to the canvas");
            Say($"Tab from the canvas went through nodes {string.Join(" ", forward)} in node order, skipping 33 clipped to nothing, then out to node {after} (around to the first); Shift+Tab back to the canvas");
        }
        finally
        {
            Update(34, new JsonObject { ["place"] = new JsonArray(7, 10, 8, 1) });
            Update(33, new JsonObject { ["clip"] = null });
            Update(21, new JsonObject { ["lines"] = lines });
        }

        Check(!surface.Draws(7, 12) && surface.Draws(1, 3), "the canvas did not leave button 34's cells undrawn once it was back");
        Say("the canvas leaves the cells of its controls undrawn, and drew again those button 34 left");

        // A place of no columns, no clip: nothing of it is shown either, skipped too; back in once it has its columns
        step = "walking the focus chain of canvas 21 with checkbox 32 placed on no columns";
        Update(32, new JsonObject { ["place"] = new JsonArray(4, 10, 0, 1) });
        List<int> narrow;
        try
        {
            narrow = Walk(canvas.Canvas!.Entry, FocusNavigationDirection.Next, out _);
        }
        finally
        {
            Update(32, new JsonObject { ["place"] = new JsonArray(4, 10, 9, 1) });
        }

        var back = Walk(canvas.Canvas!.Entry, FocusNavigationDirection.Next, out _);
        Check(!narrow.Contains(32) && narrow.Contains(31) && back.Contains(32), $"Tab from the canvas went through nodes {string.Join(" ", narrow)} with 32 placed on no columns (32 skipped expected), then {string.Join(" ", back)} once it had 9 again");
        Say($"Tab skipped checkbox 32 placed on no columns ({string.Join(" ", narrow)}), and went to it again once it had its 9 columns");
    }

    /// <summary>Moves the focus from an element that way until it leaves the canvas's controls: the nodes it went through, once each, and where it went.</summary>
    private List<int> Walk(FrameworkElement from, FocusNavigationDirection direction, out int? left)
    {
        from.Focus();
        var walked = new List<int>();
        left = null;
        for (var i = 0; i < 40; i++)
        {
            if (Keyboard.FocusedElement is not UIElement focused || !focused.MoveFocus(new TraversalRequest(direction)))
            {
                // Nowhere: -1
                left = -1;
                break;
            }

            var id = window.Views.NodeOf(Keyboard.FocusedElement as DependencyObject);
            if (id is not (>= 30 and <= 39))
            {
                // 0: out of the nodes, to the menu bar
                left = id ?? 0;
                break;
            }

            // A list box takes Tab item by item
            if (walked.Count == 0 || walked[^1] != id)
            {
                walked.Add(id.Value);
            }
        }

        return walked;
    }

    /// <summary>A patch from the application, as if it sent it: the node's props updated, its control too.</summary>
    /// <summary>
    /// 6. The mouse on canvas 21, through Windows (the cursor moved and the buttons and the wheel sent as input, WPF
    /// hit-testing where they go, as for a user; a routed event raised by hand would carry the cursor's real position,
    /// not its own): a click, a drag, the wheel, a move, then a click on text field 30, which is the field's.
    /// </summary>
    private async Task MouseOnCanvas(NodeView canvas)
    {
        step = "clicking in the canvas";
        var surface = canvas.Canvas!;
        var cell = window.Cell;
        var sent = new List<PointerMessage>();
        surface.Pointer += sent.Add;
        try
        {
            Point Screen(double row, double column) => surface.PointToScreen(new Point((column + 0.5) * cell.Width, (row + 0.5) * cell.Height));
            async Task To(double row, double column)
            {
                // Shown in the window: a window smaller than its content (at 125% on a 1024x768 screen) cuts the canvas's
                // last rows off
                var inWindow = surface.TranslatePoint(new Point((column + 0.5) * cell.Width, (row + 0.5) * cell.Height), window);
                var under = window.Views.NodeOf(window.InputHitTest(inWindow) as DependencyObject);
                Check(under is 21 || (under is { } node && (Controls.Contains(node) || Inputs.Contains(node))), $"cell ({row}, {column}) of node 21 is not shown in the window ({(under is null ? "nothing" : $"node {under}")} is there, at {inWindow.X:0.#}, {inWindow.Y:0.#} of a {window.ActualWidth:0.#}x{window.ActualHeight:0.#} window): the window is too small for its content at this scale");
                var at = Screen(row, column);
                int x = (int)Math.Round(at.X), y = (int)Math.Round(at.Y);
                Check(Native.SetCursorPos(x, y), $"the cursor could not be moved to cell ({row}, {column}) of node 21");
                // SetCursorPos keeps the cursor on the screens: a cell off them would be clicked at its edge
                Check(Native.CursorPosition() == (x, y), $"the cursor is at {Native.CursorPosition()}, not at cell ({row}, {column}) of node 21, {x}, {y}: off the screen?");
                await Task.Delay(150);
            }

            async Task Input(uint flags, int data = 0)
            {
                Native.MouseEvent(flags, data);
                await Task.Delay(150);
            }

            string Of(IEnumerable<PointerMessage> messages) => string.Join(", ", messages.Select(m => $"{m.Action} {m.Row} {m.Column} {m.Button ?? "-"}{(m.Action == "wheel" ? $" {m.Rows.Value} {m.Columns.Value}" : "")}"));

            await FitOnScreen(canvas);
            Check(window.IsActive, "the window is not the active one: the mouse cannot be tested");
            // As the mouse finds it: InputHitTest leaves out what is not hit-test visible (the input method's TextBox)
            var hit = canvas.Host.InputHitTest(surface.TranslatePoint(new Point(1.5 * cell.Width, 0.5 * cell.Height), canvas.Host));
            Check(hit == surface, $"cell (0, 1) of node 21 is not its surface for the mouse, but {hit?.GetType().Name ?? "nothing"}");
            await To(0, 1);
            var before = sent.Count;
            await Input(Native.LeftDown);
            await Input(Native.LeftUp);
            await Label("pointer release 0 1 left");
            Check(Of(sent.Skip(before)) == "press 0 1 left, release 0 1 left", $"a click on cell (0, 1) sent {Of(sent.Skip(before))}");
            Check(surface.Entry.IsKeyboardFocused, "the click did not focus the canvas");
            Say("a click on cell (0, 1) sent press and release there, and focused the canvas");

            step = "dragging in the canvas";
            before = sent.Count;
            await Input(Native.LeftDown);
            await To(1, 3);
            await To(1, 3.3);
            await Input(Native.LeftUp);
            await Label("pointer release 1 3 left");
            Check(Of(sent.Skip(before)) == "press 0 1 left, move 1 3 left, release 1 3 left", $"a drag from (0, 1) to (1, 3) sent {Of(sent.Skip(before))}");
            Say($"a drag sent one move for its cell: {Of(sent.Skip(before))}");

            step = "turning the wheel over the canvas";
            before = sent.Count;
            await Input(Native.Wheel, -3 * PointerTracker.Notch);
            await Label("pointer wheel 1 3 3 0");
            await Input(Native.HorizontalWheel, PointerTracker.Notch);
            await Label("pointer wheel 1 3 0 1");
            Check(Of(sent.Skip(before)) == "wheel 1 3 - 3 0, wheel 1 3 - 0 1", $"3 notches down, then 1 right, sent {Of(sent.Skip(before))}");
            Say("3 notches of the wheel down: 3 rows; one of a horizontal wheel to the right: a column");

            step = "moving the mouse over the canvas";
            before = sent.Count;
            await To(2, 4);
            await Label("pointer move 2 4");
            Check(Of(sent.Skip(before)) == "move 2 4 -", $"a move to (2, 4) sent {Of(sent.Skip(before))}");
            Say("a move with no button held, sent once for its cell");

            step = "clicking text field 30 over the canvas";
            var place = Ints(window.Tree.Find(30)!.Prop("place"));
            var field = window.Views.Views[30].Field!;
            before = sent.Count;
            var over = canvas.Host.InputHitTest(canvas.Canvas!.TranslatePoint(new Point((place[1] + place[2] / 2.0) * cell.Width, (place[0] + 0.5) * cell.Height), canvas.Host)) as DependencyObject;
            Check(window.Views.NodeOf(over) == 30, $"the middle of text field 30 is {over?.GetType().Name ?? "nothing"} for the mouse");
            await To(place[0], place[1] + place[2] / 2.0);
            await Input(Native.LeftDown);
            await Input(Native.LeftUp);
            await Until(() => field.IsKeyboardFocused, "the click on text field 30 did not focus it");
            await Task.Delay(300);
            // Leaving the canvas for the field: a leave at the cell last sent, (2, 4), and nothing else
            var after = sent.Skip(before).Where(m => m.Action != "leave").ToList();
            Check(after.Count == 0, $"a click on text field 30 sent {Of(after)}");
            Say($"a click on text field 30 focused it and sent no pointer ({Of(sent.Skip(before))})");

            // A drag from the canvas onto text field 30 placed on it, released there: the release is the canvas's
            // (its implicit grab), then leave, as the pointer is over the field with no button held
            step = "dragging from the canvas onto text field 30 and releasing there";
            await To(2, 4);
            before = sent.Count;
            await Input(Native.LeftDown);
            await To(2, 8);
            await To(place[0], place[1] + 5);
            await Input(Native.LeftUp);
            var expected = $"press 2 4 left, move 2 8 left, move {place[0]} {place[1] + 5} left, release {place[0]} {place[1] + 5} left, leave {place[0]} {place[1] + 5} -";
            await Until(() => sent.Skip(before).Any(m => m.Action == "leave"), $"a release over text field 30 sent no leave: {Of(sent.Skip(before))}");
            await Task.Delay(300);
            Check(Of(sent.Skip(before)) == expected, $"a drag onto text field 30 released there sent {Of(sent.Skip(before))}, not {expected}");
            before = sent.Count;
            await To(place[0], place[1] + 12);
            await To(place[0], place[1] + 2);
            await Input(Native.Wheel, -PointerTracker.Notch);
            await Task.Delay(300);
            Check(sent.Count == before, $"over text field 30 with no button held, the canvas sent {Of(sent.Skip(before))}");
            Say($"a drag onto text field 30 released there: {Of(sent.Skip(sent.Count - 5))}; then over the field, moves and the wheel sent nothing");

            // A drag from the canvas released off the window: the canvas has the mouse until the release (its
            // implicit grab), which it sends at the cell past its edges, then leave, the pointer being off it
            step = "dragging from the canvas off the window and releasing there";
            // Found first: trying points moves the cursor, which the canvas sees
            var outside = OffWindow();
            Check(outside is not null, "no point of the screen off the window to drag to (the window covers it)");
            await To(2, 4);
            await Task.Delay(150);
            var (offX, offY) = outside!.Value;
            var handle = new WindowInteropHelper(window).Handle;
            Check(Native.TopWindowAt(offX, offY) != handle, $"the point {offX}, {offY} to drag to is on the window (its frame is {Native.WindowRect(handle)?.ToString() ?? "unknown"})");
            var offCell = surface.PointFromScreen(new Point(offX, offY));
            var (offRow, offColumn) = PointerTracker.Cell(offCell.X, offCell.Y, cell.Width, cell.Height);
            before = sent.Count;
            await Input(Native.LeftDown);
            // Let go of also on a failure: Windows would keep it held for whatever runs next
            var held = true;
            try
            {
                Check(Native.SetCursorPos(offX, offY), $"the cursor could not be moved off the window, to {offX}, {offY}");
                await Task.Delay(150);
                Check(Native.CursorPosition() is { } cursor && cursor.X == offX && cursor.Y == offY, $"the cursor is at {Native.CursorPosition()?.ToString() ?? "nowhere"}, not off the window at {offX}, {offY}");
                Check(surface.IsMouseCaptured, "the canvas lost the mouse while the button was held off the window");
                await Input(Native.LeftUp);
                held = false;
            }
            finally
            {
                if (held)
                {
                    Native.MouseEvent(Native.LeftUp);
                }
            }

            expected = $"press 2 4 left, move {offRow} {offColumn} left, release {offRow} {offColumn} left, leave {offRow} {offColumn} -";
            await Until(() => sent.Skip(before).Any(m => m.Action == "leave"), $"a release off the window sent no leave: {Of(sent.Skip(before))}, not {expected}");
            await Task.Delay(300);
            Check(Of(sent.Skip(before)) == expected, $"a drag off the window released there sent {Of(sent.Skip(before))}, not {expected}");
            Check(!surface.IsMouseCaptured, "the canvas keeps the mouse after a release off the window");
            var client = window.PointToScreen(new Point(0, 0));
            Say($"a drag released off the window, at {offX}, {offY} (its frame {Native.WindowRect(handle)}, {client.Y - Native.WindowRect(handle)!.Value.Top:0} pixels above the client area): {Of(sent.Skip(before))}");
        }
        finally
        {
            surface.Pointer -= sent.Add;
        }
    }

    /// <summary>
    /// The window within the screen's work area, at its left, room left on its right for a point off it: the mouse
    /// steps click cells and a point past the frame, which SetCursorPos would otherwise keep at the screen's edge (at
    /// 125% in a 1024x768 VM, the window opens wider than the screen, its canvas starting left of it).
    /// </summary>
    private async Task FitOnScreen(NodeView canvas)
    {
        var area = SystemParameters.WorkArea;
        const double room = 80;
        var width = Math.Min(window.ActualWidth, area.Width - room);
        // As high as it is (the canvas is low in it): a window taller than the work area ends at its bottom, its title
        // bar past the top of the screen
        var top = Math.Min(area.Top, area.Bottom - window.ActualHeight);
        if (window.Left == area.Left && window.Top == top && width == window.ActualWidth)
        {
            return;
        }

        Say($"the window moved from {window.Left:0.#}, {window.Top:0.#} ({window.ActualWidth:0.#}x{window.ActualHeight:0.#}) into the work area {area}: {width:0.#} wide at {area.Left:0.#}, {top:0.#}");
        var columns = Width(canvas);
        window.Left = area.Left;
        window.Top = top;
        window.Width = width;
        window.UpdateLayout();
        // A narrower canvas: the application shows its rects, after which the label is the mouse's again
        if (Width(canvas) != columns)
        {
            await Label($"rects {Width(canvas)}");
        }

        await Task.Delay(300);
    }

    /// <summary>
    /// A point of the screen 40 pixels off the window's frame (GetWindowRect: its title bar and borders, which reach
    /// further past the client area as the scale grows), right of it, else left, above or below it, at its middle: one
    /// the cursor can be moved to (SetCursorPos keeps it on the screens); null when there is none.
    /// </summary>
    private (int X, int Y)? OffWindow()
    {
        var frame = Native.WindowRect(new WindowInteropHelper(window).Handle);
        if (frame is not { } edges)
        {
            return null;
        }

        const int margin = 40;
        int left = edges.Left - margin, top = edges.Top - margin, right = edges.Right + margin, bottom = edges.Bottom + margin;
        int middleX = (left + right) / 2, middleY = (top + bottom) / 2;
        var original = Native.CursorPosition();
        try
        {
            foreach (var (x, y) in new[] { (right, middleY), (left, middleY), (middleX, top), (middleX, bottom) })
            {
                if (Native.SetCursorPos(x, y) && Native.CursorPosition() is { } at && at.X == x && at.Y == y)
                {
                    return (x, y);
                }
            }

            return null;
        }
        finally
        {
            if (original is { } back)
            {
                Native.SetCursorPos(back.X, back.Y);
            }
        }
    }

    /// <summary>
    /// 6b. cursor messages, handled as if the application sent them: the CanvasView of canvas 21 alone takes the
    /// pointer, which Windows shows over its surface, not over text field 30 placed on it nor past the canvas; none
    /// hides it, a keyword with no pointer of its own is the arrow, and a node that is not a canvas is ignored.
    /// </summary>
    private async Task CursorOverCanvas(NodeView canvas)
    {
        step = "sending cursor pointer for canvas 21";
        var surface = canvas.Canvas!;
        var cell = window.Cell;
        var field = window.Views.Views[30].Field!;
        var place = Ints(window.Tree.Find(30)!.Prop("place"));
        void Send(int node, string shape) => window.Handle(MessageCodec.Parse($"{{\"type\":\"cursor\",\"node\":{node},\"shape\":\"{shape}\"}}"));
        async Task To(Point inSurface)
        {
            var at = surface.PointToScreen(inSurface);
            Check(Native.SetCursorPos((int)Math.Round(at.X), (int)Math.Round(at.Y)), $"the cursor could not be moved to {inSurface} in node 21");
            await Task.Delay(200);
        }

        string Name(IntPtr shown) =>
            shown == IntPtr.Zero ? "hidden"
            : shown == Native.SystemCursor(Native.IdcHand) ? "hand"
            : shown == Native.SystemCursor(Native.IdcIBeam) ? "I-beam"
            : shown == Native.SystemCursor(Native.IdcArrow) ? "arrow"
            : $"0x{shown.ToInt64():x}";

        var onCanvas = new Point(1.5 * cell.Width, 0.5 * cell.Height);
        var onField = new Point((place[1] + place[2] / 2.0) * cell.Width, (place[0] + 0.5) * cell.Height);
        // Button 34's place, put back at the end, also on a failure
        var buttonPlace = window.Tree.Find(34)!.Prop("place")!.DeepClone();
        try
        {
            Check(surface.Cursor is null, $"canvas 21 has the pointer {surface.Cursor} before any cursor message");
            var fieldCursor = field.Cursor;
            await To(onCanvas);
            Send(21, "pointer");
            Check(surface.Cursor == Cursors.Hand, $"cursor pointer gave canvas 21 the pointer {surface.Cursor?.ToString() ?? "none"}, not Hand");
            Check(field.Cursor == fieldCursor && canvas.Overlay!.Cursor is null && canvas.Host.Cursor is null, $"cursor pointer changed the pointer of text field 30 ({field.Cursor}), of the canvas's overlay ({canvas.Overlay!.Cursor}) or of its host ({canvas.Host.Cursor})");
            await Task.Delay(200);
            // Set while the mouse rests on the canvas: shown without a move
            Check(Name(Native.ShownCursor()) == "hand", $"over cell (0, 1) of canvas 21, Windows has the {Name(Native.ShownCursor())} pointer after cursor pointer, not the hand");
            Say("cursor pointer: canvas 21's surface has Cursors.Hand, and Windows has the hand over it, the mouse resting there");

            step = "moving the mouse over text field 30 with cursor pointer";
            await To(onField);
            Check(Name(Native.ShownCursor()) == "I-beam", $"over text field 30 on canvas 21, Windows has the {Name(Native.ShownCursor())} pointer, not the field's I-beam");
            // Button 34, placed under the 3 rows the canvas draws, moved onto its row 1 as a patch would: a control that
            // takes the pointer of what it is in, unlike a text field
            Update(34, new JsonObject { ["place"] = new JsonArray(1, 40, 8, 1) });
            window.UpdateLayout();
            var onButton = new Point(44 * cell.Width, 1.5 * cell.Height);
            Check(window.Views.NodeOf(window.InputHitTest(surface.TranslatePoint(onButton, window)) as DependencyObject) == 34, "the middle of button 34 moved to row 1 is not the button for the mouse");
            await To(onButton);
            Check(Name(Native.ShownCursor()) == "arrow", $"over button 34 on canvas 21, Windows has the {Name(Native.ShownCursor())} pointer, not the button's arrow");
            Update(34, new JsonObject { ["place"] = buttonPlace.DeepClone() });
            window.UpdateLayout();
            await To(onCanvas);
            Check(Name(Native.ShownCursor()) == "hand", $"back over cell (0, 1), Windows has the {Name(Native.ShownCursor())} pointer, not the hand");
            // Past the canvas: just under the canvas's element, on the screen and in the window
            var below = new Point(4, surface.ActualHeight + cell.Height / 2);
            var screen = surface.PointToScreen(below);
            var area = SystemParameters.WorkArea;
            Check(screen.Y < area.Bottom * PresentationSource.FromVisual(window).CompositionTarget.TransformToDevice.M22 - 2, $"the point under canvas 21, {screen}, is past the bottom of the screen");
            var there = window.InputHitTest(surface.TranslatePoint(below, window)) as DependencyObject;
            Check(there is not null && window.Views.NodeOf(there) != 21, $"under canvas 21 is {there?.GetType().Name ?? "nothing"} of node {window.Views.NodeOf(there)?.ToString() ?? "none"}");
            await To(below);
            Check(Name(Native.ShownCursor()) != "hand", $"past canvas 21 (over {there?.GetType().Name ?? "nothing"} of node {window.Views.NodeOf(there)}), Windows still has the hand");
            Say($"the hand over the canvas only: the I-beam over text field 30 and the arrow over button 34 placed on it, the {Name(Native.ShownCursor())} pointer over {there?.GetType().Name ?? "nothing"} of node {window.Views.NodeOf(there)?.ToString() ?? "none"} past it");

            step = "sending cursor none, zoom-in, then for nodes that are not canvases";
            await To(onCanvas);
            Send(21, "none");
            Check(surface.Cursor == Cursors.None, $"cursor none gave canvas 21 the pointer {surface.Cursor}, not None");
            await Task.Delay(200);
            Check(Native.ShownCursor() == IntPtr.Zero, $"cursor none: Windows has the {Name(Native.ShownCursor())} pointer over canvas 21");
            Send(21, "zoom-in");
            Check(surface.Cursor == Cursors.Arrow, $"cursor zoom-in gave canvas 21 the pointer {surface.Cursor}, not the arrow");
            await Task.Delay(200);
            Check(Name(Native.ShownCursor()) == "arrow", $"cursor zoom-in: Windows has the {Name(Native.ShownCursor())} pointer over canvas 21");
            Send(21, "text");
            Send(30, "pointer");
            Send(99999, "pointer");
            Check(surface.Cursor == Cursors.IBeam && field.Cursor == fieldCursor, $"cursor for text field 30 or an unknown node changed something: canvas 21 has {surface.Cursor}, field 30 {field.Cursor}");
            Say("cursor none hides the pointer, zoom-in is the arrow, and cursor for text field 30 or node 99999 is ignored");
            Send(21, "default");
            Check(surface.Cursor == Cursors.Arrow, $"cursor default gave canvas 21 the pointer {surface.Cursor}");
        }
        finally
        {
            if (!JsonNode.DeepEquals(window.Tree.Find(34)?.Prop("place"), buttonPlace))
            {
                Update(34, new JsonObject { ["place"] = buttonPlace.DeepClone() });
            }

            // Left over text field 30, as the mouse step leaves it, also on a failure: not over the canvas, which
            // would send a move as the next run's window opens under it
            var rest = surface.PointToScreen(onField);
            Native.SetCursorPos((int)Math.Round(rest.X), (int)Math.Round(rest.Y));
        }
    }

    /// <summary>The text vm/canvas.ps1 finds on the guest's clipboard once the test is over.</summary>
    public const string ClipboardText = "tuiapp canvas clipboard: ok_1";

    /// <summary>
    /// 6c. contextmenu and clipboard, handled as if the application sent them. A right click on canvas 21, no menu
    /// coming: its press and release, and no menu of the canvas's own. The clipboard message puts its text there. A
    /// context menu at cell (1, 3) of the canvas: its items as sent (a separator, one disabled, an underscore shown as
    /// it is), at the cell; a click on an item sends contextmenu-select with its id; on the paste one, paste with the
    /// clipboard's text; closed, nothing.
    /// </summary>
    private async Task ContextMenuOnCanvas(NodeView canvas)
    {
        step = "right-clicking canvas 21 with no menu coming";
        var surface = canvas.Canvas!;
        var cell = window.Cell;
        Check(HostWindow.Capabilities.Contains("contextmenu") && HostWindow.Capabilities.Contains("clipboard"), "hello does not list contextmenu and clipboard");
        var sent = new List<Message>();
        window.Sent += sent.Add;
        try
        {
            string Of(IEnumerable<Message> messages) => string.Join(", ", messages.Select(m => m switch
            {
                ContextMenuSelectMessage select => $"contextmenu-select {select.Id}",
                PasteMessage paste => $"paste \"{paste.Text}\"",
                PointerMessage pointer => $"pointer {pointer.Action} {pointer.Row} {pointer.Column} {pointer.Button ?? "-"}",
                KeyMessage key => $"key {key.Key}{(key.Text.IsSet && key.Text.Value is { } typed ? $" \"{typed}\"" : "")}",
                _ => m.Type,
            }));
            async Task ClickAt(Point screen, uint down, uint up)
            {
                Check(Native.SetCursorPos((int)Math.Round(screen.X), (int)Math.Round(screen.Y)), $"the cursor could not be moved to {screen}");
                await Task.Delay(150);
                Native.MouseEvent(down);
                await Task.Delay(100);
                Native.MouseEvent(up);
                await Task.Delay(300);
            }

            // A right click, which the application answers with no menu: no menu of the canvas's own either
            var before = sent.Count;
            await ClickAt(surface.PointToScreen(new Point(5.5 * cell.Width, 2.5 * cell.Height)), Native.RightDown, Native.RightUp);
            await Until(() => sent.Skip(before).OfType<PointerMessage>().Any(p => p.Action == "release"), $"a right click on canvas 21 sent {Of(sent.Skip(before))}");
            await Task.Delay(300);
            Check(OpenMenus().Count == 0, $"a right click on canvas 21, which sent no contextmenu, opened {OpenMenus().Count} menu(s) of the host's own");
            var clicks = sent.Skip(before).OfType<PointerMessage>().Where(p => p.Action is "press" or "release").ToList();
            Check(clicks.Count == 2 && clicks.All(p => p.Button == "right" && p.Row == 2 && p.Column == 5), $"a right click on cell (2, 5) sent {Of(sent.Skip(before))}");
            Say($"a right click on cell (2, 5) sent {Of(clicks)} and opened no menu of the host's own");
            // The menu key and Shift+F10, the canvas focused: not the editing menu of the TextBox it takes text with
            Check(surface.Entry.IsKeyboardFocused, "the right click did not leave the canvas focused");
            foreach (var (name, press) in new (string, Action)[] { ("the menu key", () => Native.KeyPress(0x5D /* VK_APPS */)), ("Shift+F10", () => Native.KeyChord(0x10 /* VK_SHIFT */, 0x79 /* VK_F10 */)) })
            {
                before = sent.Count;
                press();
                await Task.Delay(500);
                var opened = OpenMenus();
                foreach (var stray in opened)
                {
                    stray.IsOpen = false;
                }

                Check(opened.Count == 0, $"{name} on canvas 21 opened {opened.Count} menu(s) of the host's own: {string.Join(" | ", opened.SelectMany(m => m.Items.OfType<MenuItem>()).Select(i => i.Header))}");
                Say($"{name} on canvas 21 opened no menu of the host's own; the application got: {(sent.Count > before ? Of(sent.Skip(before)) : "nothing")}");
            }

            step = "the clipboard message";
            window.Handle(new ClipboardMessage(ClipboardText));
            await Until(() => window.Copied.Contains(ClipboardText), "the clipboard message's text was not put on the clipboard");
            Check(Clipboard.GetText() == ClipboardText, $"the clipboard has \"{Clipboard.GetText()}\", not \"{ClipboardText}\"");
            Say($"the clipboard message put \"{ClipboardText}\" on the clipboard");

            // Two clipboard messages, the first while another thread holds the clipboard open: it waits to try again,
            // the second gets there once the clipboard is free; the first's next try must not put its older text back
            step = "two clipboard messages, the first while the clipboard is held";
            using (var held = new System.Threading.ManualResetEventSlim())
            using (var release = new System.Threading.ManualResetEventSlim())
            {
                var holding = false;
                var holder = new System.Threading.Thread(() =>
                {
                    holding = Native.OpenClipboard(IntPtr.Zero);
                    held.Set();
                    release.Wait(5000);
                    if (holding)
                    {
                        Native.CloseClipboard();
                    }
                });
                holder.Start();
                // The thread let go and joined before the events go, also when a check fails
                var copies = window.Copied.Count;
                try
                {
                    held.Wait(5000);
                    Check(holding, "the test could not hold the clipboard open");
                    window.Handle(new ClipboardMessage("older clipboard text"));
                }
                finally
                {
                    release.Set();
                    holder.Join(5000);
                }

                window.Handle(new ClipboardMessage(ClipboardText));
                await Task.Delay(1500);
                var text = Clipboard.GetText();
                var copied = window.Copied.Skip(copies).ToList();
                Check(text == ClipboardText && !copied.Contains("older clipboard text"), $"after clipboard \"older clipboard text\" (clipboard held) then \"{ClipboardText}\", the clipboard has \"{text}\"; put there: {string.Join(", ", copied.Select(c => $"\"{c}\""))}");
                Say($"clipboard \"older clipboard text\" sent while the clipboard was held, then \"{ClipboardText}\": the newer stays, the older was dropped");
            }

            step = "a context menu at cell (1, 3) of canvas 21";
            var items = new ContextMenuItem[]
            {
                new("open", "Open_Link", false, default, default),
                // An opaque id, passed on as it came
                new("1:2", "Copy Link Address", false, default, default),
                new(default, default, true, default, default),
                new("paste", "Paste", false, default, "paste"),
                new("undo", "Nothing to undo", false, false, default),
            };
            var message = new ContextMenuMessage(21, 1, 3, items);
            var menu = await Show(message);
            Check(menu.PlacementTarget == surface, $"the context menu is placed on {menu.PlacementTarget?.GetType().Name ?? "nothing"}, not canvas 21's surface");
            var entries = menu.Items.Cast<object>().ToList();
            Check(entries.Count == 5 && entries[2] is Separator && entries.Where((_, i) => i != 2).All(e => e is MenuItem), $"the context menu has {string.Join(", ", entries.Select(e => e.GetType().Name))}, not 2 items, a separator and 2 items");
            // As drawn: the runs of the TextBlocks (an AccessText's TextBlock holds its text in runs, not in Text)
            var shown = entries.OfType<MenuItem>().Select(e => string.Concat(Descendants(e).OfType<TextBlock>().Select(t => new System.Windows.Documents.TextRange(t.ContentStart, t.ContentEnd).Text))).ToList();
            Check(shown.SequenceEqual(new[] { "Open_Link", "Copy Link Address", "Paste", "Nothing to undo" }), $"the context menu shows {string.Join(" | ", shown)}");
            Check(Descendants((MenuItem)entries[0]).OfType<AccessText>().All(a => a.AccessKey == '\0'), "the underscore of Open_Link made an access key");
            var enabled = entries.OfType<MenuItem>().Select(e => e.IsEnabled).ToList();
            Check(enabled.SequenceEqual(new[] { true, true, true, false }), $"the context menu's items are enabled {string.Join(" ", enabled)}, not true true true false");
            var corner = surface.PointToScreen(new Point(3 * cell.Width, 1 * cell.Height));
            var at = menu.PointToScreen(new Point(0, 0));
            Check(Math.Abs(at.X - corner.X) <= 12 && Math.Abs(at.Y - corner.Y) <= 12, $"the context menu is at {at}, not at cell (1, 3) of canvas 21, {corner}");
            Capture("contextmenu.png");
            Say($"a context menu at cell (1, 3), {at.X:0}, {at.Y:0}: {string.Join(" | ", shown)}, a separator, the last one disabled");

            step = "clicking Copy Link Address in the context menu";
            before = sent.Count;
            var copy = (MenuItem)entries[1];
            await ClickAt(copy.PointToScreen(new Point(copy.ActualWidth / 2, copy.ActualHeight / 2)), Native.LeftDown, Native.LeftUp);
            await Until(() => sent.Skip(before).OfType<ContextMenuSelectMessage>().Any(), $"a click on Copy Link Address sent {Of(sent.Skip(before))}");
            await Task.Delay(200);
            Check(Of(sent.Skip(before).Where(m => m is not PointerMessage and not RectsMessage)) == "contextmenu-select 1:2" && !menu.IsOpen, $"a click on Copy Link Address sent {Of(sent.Skip(before))}{(menu.IsOpen ? ", the menu still open" : "")}");
            Say("a click on Copy Link Address sent contextmenu-select 1:2, its id as it came, and closed the menu");

            step = "clicking Paste in the context menu";
            menu = await Show(message);
            before = sent.Count;
            var paste = (MenuItem)menu.Items[3];
            await ClickAt(paste.PointToScreen(new Point(paste.ActualWidth / 2, paste.ActualHeight / 2)), Native.LeftDown, Native.LeftUp);
            await Until(() => sent.Skip(before).OfType<PasteMessage>().Any(), $"a click on Paste sent {Of(sent.Skip(before))}");
            await Task.Delay(200);
            Check(Of(sent.Skip(before).Where(m => m is not PointerMessage)) == $"paste \"{ClipboardText}\"", $"a click on Paste sent {Of(sent.Skip(before))}, not the clipboard's text alone");
            Say($"a click on Paste sent paste \"{ClipboardText}\", no contextmenu-select");

            // An empty clipboard: Paste sends nothing, as for no text (then the text put back, which vm/canvas.ps1 reads)
            step = "clicking Paste in the context menu, the clipboard emptied";
            window.Handle(new ClipboardMessage(""));
            await Until(() => window.Copied.Count > 0 && window.Copied[^1] == "", "the clipboard message of \"\" was not put on the clipboard");
            menu = await Show(message);
            before = sent.Count;
            paste = (MenuItem)menu.Items[3];
            await ClickAt(paste.PointToScreen(new Point(paste.ActualWidth / 2, paste.ActualHeight / 2)), Native.LeftDown, Native.LeftUp);
            await Task.Delay(500);
            Check(!menu.IsOpen && sent.Skip(before).All(m => m is PointerMessage), $"a click on Paste, the clipboard empty, sent {Of(sent.Skip(before))}{(menu.IsOpen ? ", the menu still open" : "")}");
            Say("a click on Paste, the clipboard emptied by clipboard \"\", sent nothing");
            // Ctrl+V in the canvas, the clipboard empty: nothing either
            surface.Entry.Focus();
            await Task.Delay(200);
            Check(surface.Entry.IsKeyboardFocused, "the canvas does not have the focus for Ctrl+V");
            before = sent.Count;
            Native.KeyChord(0x11 /* VK_CONTROL */, 0x56 /* V */);
            await Task.Delay(500);
            // No paste, nor the key (moves of the mouse and rects aside)
            Check(!sent.Skip(before).Any(m => m is PasteMessage or KeyMessage), $"Ctrl+V in the canvas, the clipboard empty, sent {Of(sent.Skip(before))}");
            Say("Ctrl+V in the canvas, the clipboard empty, sent nothing");

            // Ctrl+V while another thread holds the clipboard open (CLIPBRD_E_CANT_OPEN): nothing sent, the host goes on
            step = "Ctrl+V in the canvas while the clipboard is held";
            using (var heldOpen = new System.Threading.ManualResetEventSlim())
            using (var letGo = new System.Threading.ManualResetEventSlim())
            {
                var holds = false;
                var holder = new System.Threading.Thread(() =>
                {
                    holds = Native.OpenClipboard(IntPtr.Zero);
                    heldOpen.Set();
                    letGo.Wait(10000);
                    if (holds)
                    {
                        Native.CloseClipboard();
                    }
                });
                holder.Start();
                // The thread let go and joined before the events go, also when a check fails
                try
                {
                    heldOpen.Wait(5000);
                    Check(holds, "the test could not hold the clipboard open");
                    before = sent.Count;
                    Native.KeyChord(0x11 /* VK_CONTROL */, 0x56 /* V */);
                    await Task.Delay(2500);
                }
                finally
                {
                    letGo.Set();
                    holder.Join(5000);
                }

                Check(!sent.Skip(before).Any(m => m is PasteMessage or KeyMessage), $"Ctrl+V in the canvas, the clipboard held, sent {Of(sent.Skip(before))}");
                Say("Ctrl+V in the canvas while another thread held the clipboard open sent nothing, and the host went on");
            }
            window.Handle(new ClipboardMessage(ClipboardText));
            await Until(() => window.Copied.Count > 0 && window.Copied[^1] == ClipboardText, "the clipboard message's text was not put back on the clipboard");

            // A second menu while one is shown replaces it
            step = "a second context menu while the first is shown";
            var first = await Show(message);
            window.Handle(new ContextMenuMessage(21, 2, 6, new ContextMenuItem[] { new("second", "Second menu", false, default, default) }));
            await Until(() => window.CanvasMenu != first && window.CanvasMenu is { IsOpen: true, IsLoaded: true }, "a second contextmenu opened no menu");
            await Task.Delay(300);
            var open = OpenMenus();
            Check(!first.IsOpen && open.Count == 1 && open[0] == window.CanvasMenu && window.CanvasMenu!.Items.Count == 1, $"a second contextmenu left the first {(first.IsOpen ? "open" : "closed")}, {open.Count} menu(s) open: {string.Join(" / ", open.Select(m => string.Join(" | ", m.Items.OfType<MenuItem>().Select(i => i.Header))))}");
            Say("a second contextmenu while the first was shown replaced it: one menu open, the second's");
            window.CanvasMenu!.IsOpen = false;
            await Task.Delay(200);

            step = "closing the context menu";
            menu = await Show(message);
            before = sent.Count;
            menu.IsOpen = false;
            await Task.Delay(400);
            Check(sent.Skip(before).All(m => m is PointerMessage), $"closing the context menu sent {Of(sent.Skip(before))}");
            Say("closed with no item chosen, the context menu sent nothing");
        }
        finally
        {
            window.Sent -= sent.Add;
            if (window.CanvasMenu is { IsOpen: true } open)
            {
                open.IsOpen = false;
            }

            foreach (var stray in OpenMenus())
            {
                stray.IsOpen = false;
            }

            // Left over text field 30, as the cursor step leaves it, also on a failure: not over the canvas, which would
            // send a move as the next run's window opens under it
            var rest = window.Views.Views[30].Host.PointToScreen(new Point(4, 4));
            Native.SetCursorPos((int)Math.Round(rest.X), (int)Math.Round(rest.Y));
        }
    }

    /// <summary>
    /// Every control placed over canvas 21 fits its place: each element drawn for nodes 30 to 39 (the control and
    /// the parts of its template: a text field's and a text area's box, a check box's and a radio button's mark and
    /// label, a pop-up's box and arrow, a number's arrows, a slider's track and thumb, a list box's items), its bounds
    /// within the cells of its place, give or take half a DIP (what is drawn of it: a list's rows scrolled out of its box
    /// are cut by it; a panel or border that draws nothing is not counted, what it holds is).
    /// </summary>
    private void PlacedControlsFit(NodeView canvas)
    {
        step = "checking the controls placed over canvas 21 fit their places";
        window.UpdateLayout();
        var cell = window.Cell;
        var surface = canvas.Canvas!;
        var ids = NativeInputs ? Controls.Concat(Inputs).ToArray() : Controls;
        // Their edges on the window's pixels: snapped with its DPI, not a default one read before they were in it
        var dpi = VisualTreeHelper.GetDpi(window);
        static bool Whole(double value) => Math.Abs(value - Math.Round(value)) < 0.01;
        var offGrid = ids.Select(id => window.Views.Views[id].Host).Select(host => (Host: host, Left: System.Windows.Controls.Canvas.GetLeft(host), Top: System.Windows.Controls.Canvas.GetTop(host)))
            .Where(h => !(Whole(h.Left * dpi.DpiScaleX) && Whole(h.Top * dpi.DpiScaleY) && Whole((h.Left + h.Host.Width) * dpi.DpiScaleX) && Whole((h.Top + h.Host.Height) * dpi.DpiScaleY)))
            .Select(h => $"node {window.Views.NodeOf(h.Host)} at {h.Left:0.###}, {h.Top:0.###} {h.Host.Width:0.###}x{h.Host.Height:0.###}").ToList();
        Check(offGrid.Count == 0, $"{offGrid.Count} placed control(s) not on the pixels of the window's DPI {dpi.DpiScaleX}: {string.Join("; ", offGrid.Take(10))}");
        var overflows = new List<string>();
        foreach (var id in ids)
        {
            var view = window.Views.Views[id];
            var place = Ints(window.Tree.Find(id)!.Prop("place"));
            var cells = new Rect(place[1] * cell.Width, place[0] * cell.Height, place[2] * cell.Width, place[3] * cell.Height);
            foreach (var element in new[] { (DependencyObject)view.Host }.Concat(Descendants(view.Host)).OfType<FrameworkElement>())
            {
                if (!element.IsVisible || element.ActualWidth <= 0 || element.ActualHeight <= 0 || DrawsNothing(element))
                {
                    continue;
                }

                // What is drawn of it: cut by the ancestors that clip (a scroll viewer's content, a clip)
                var bounds = element.TransformToVisual(surface).TransformBounds(new Rect(element.RenderSize));
                for (var above = VisualTreeHelper.GetParent(element) as UIElement; above is not null && bounds != Rect.Empty; above = above == view.Host ? null : VisualTreeHelper.GetParent(above) as UIElement)
                {
                    if (above is ScrollContentPresenter || above.ClipToBounds)
                    {
                        bounds.Intersect(above.TransformToVisual(surface).TransformBounds(new Rect(above.RenderSize)));
                    }

                    if (above.Clip is { } clip)
                    {
                        bounds.Intersect(above.TransformToVisual(surface).TransformBounds(clip.Bounds));
                    }
                }

                if (bounds.IsEmpty || bounds.Width <= 0 || bounds.Height <= 0)
                {
                    continue;
                }

                if (bounds.Left < cells.Left - 0.5 || bounds.Top < cells.Top - 0.5 || bounds.Right > cells.Right + 0.5 || bounds.Bottom > cells.Bottom + 0.5)
                {
                    overflows.Add($"node {id} {element.GetType().Name}{(string.IsNullOrEmpty(element.Name) ? "" : $" {element.Name}")} at {Rounded(bounds)}, its cells {Rounded(cells)}");
                }
            }
        }

        Check(overflows.Count == 0, $"{overflows.Count} element(s) past their place: {string.Join("; ", overflows.Take(12))}");

        Say($"every element of nodes {string.Join(", ", ids)} is within its place, give or take half a DIP, its edges on the pixels of the window's DPI {dpi.DpiScaleX}");
    }

    /// <summary>The scale a run killed during its DPI step left, put back (ScaleMarker), before the window shows.</summary>
    private void PutBackLeftScale()
    {
        string? left = null;
        try
        {
            left = File.Exists(ScaleMarker) ? File.ReadAllText(ScaleMarker).Trim() : null;
        }
        catch (IOException)
        {
        }

        if (left is null || !int.TryParse(left, out var original))
        {
            return;
        }

        var done = Native.SetScaleStepNow(original);
        File.Delete(ScaleMarker);
        Say($"put back the display's scale a run killed during its change of DPI left: step {original} ({(done ? "set" : "could not be set")})");
    }

    /// <summary>
    /// The display's scale raised a step while the window is open, as Settings does: once the window's DpiChanged came,
    /// the controls placed over the canvas are on the pixels of the new DPI and still fit their places. Then the scale
    /// put back. Skipped when Windows sends no DpiChanged. The change is saved in the profile (Windows applies no other),
    /// and ScaleMarker lets the next run put back the scale of a run killed meanwhile.
    /// </summary>
    private async Task DpiChangeWhileOpen(NodeView canvas)
    {
        step = "raising the display's scale while the window is open";
        if (Native.ScaleStep() is not { } original)
        {
            Say("the display's scale step cannot be read: no change of DPI while open");
            return;
        }

        var changes = new List<DpiScale>();
        void Changed(object? sender, DpiChangedEventArgs e) => changes.Add(e.NewDpi);
        window.DpiChanged += Changed;
        var before = VisualTreeHelper.GetDpi(window);
        try
        {
            // Written first: a run killed from here on leaves its step for the next one to put back
            File.WriteAllText(ScaleMarker, original.ToString(System.Globalization.CultureInfo.InvariantCulture));
            Check(Native.SetScaleStepNow(original + 1), "the display's scale could not be set");
            var waited = Stopwatch.StartNew();
            while (changes.Count == 0 && waited.ElapsedMilliseconds < 8000)
            {
                await Task.Delay(100);
            }

            if (changes.Count == 0)
            {
                Say($"the display's scale raised a step from {original}: no DpiChanged within 8 s, nothing to check");
                return;
            }

            await Task.Delay(800);
            window.UpdateLayout();
            Say($"the display's scale raised a step: DpiChanged from {before.DpiScaleX} to {changes[^1].DpiScaleX}");
            PlacedControlsFit(canvas);
        }
        finally
        {
            changes.Clear();
            Native.SetScaleStepNow(original);
            File.Delete(ScaleMarker);
            var waited = Stopwatch.StartNew();
            while (changes.Count == 0 && waited.ElapsedMilliseconds < 8000)
            {
                await Task.Delay(100);
            }

            window.DpiChanged -= Changed;
            await Task.Delay(500);
        }

        Check(VisualTreeHelper.GetDpi(window).DpiScaleX == before.DpiScaleX, $"the scale put back, the window is at DPI {VisualTreeHelper.GetDpi(window).DpiScaleX}, not {before.DpiScaleX}");
        Say($"the scale put back: DPI {before.DpiScaleX} again");
    }

    /// <summary>
    /// List box 39 with a template of another theme, its border not named Bd and padded by 1: placed, it still has no
    /// border nor padding, its first row at the top of its place. Then its own template back.
    /// </summary>
    private void ListBoxOfAnotherTemplate()
    {
        step = "giving list box 39 a template whose border is not named Bd";
        var list = (ListBox)window.Views.Views[39].Control!;
        const string xaml = "<ControlTemplate TargetType=\"ListBox\" xmlns=\"http://schemas.microsoft.com/winfx/2006/xaml/presentation\">"
            + "<Border Background=\"{TemplateBinding Background}\" BorderBrush=\"{TemplateBinding BorderBrush}\" BorderThickness=\"{TemplateBinding BorderThickness}\" Padding=\"1\">"
            + "<ScrollViewer Focusable=\"False\"><ItemsPresenter/></ScrollViewer></Border></ControlTemplate>";
        list.Template = (ControlTemplate)System.Windows.Markup.XamlReader.Parse(xaml);
        try
        {
            Update(39, new JsonObject());
            window.UpdateLayout();
            var root = VisualTreeHelper.GetChildrenCount(list) > 0 ? VisualTreeHelper.GetChild(list, 0) as Border : null;
            Check(root is not null, "list box 39's template has no border at its root");
            var place = Ints(window.Tree.Find(39)!.Prop("place"));
            var first = (ListBoxItem)list.ItemContainerGenerator.ContainerFromIndex(0);
            var top = first.TransformToVisual(window.Views.Views[21].Canvas!).TransformBounds(new Rect(first.RenderSize)).Top;
            var expected = place[0] * window.Cell.Height;
            Check(root!.Padding == new Thickness(0) && root.BorderThickness == new Thickness(0) && Math.Abs(top - expected) <= 0.5,
                $"with a template of another theme, list box 39's border has padding {root.Padding} and thickness {root.BorderThickness}, its first row at {top:0.#}, not {expected:0.#}");
            Say("with a template whose border is not named Bd, list box 39 placed has no border nor padding, its first row at the top of its place");
        }
        finally
        {
            list.ClearValue(Control.TemplateProperty);
            Update(39, new JsonObject());
        }
    }

    /// <summary>
    /// A panel or a border that draws nothing of its own (no background, no border), as the Canvas of Aero2's slider
    /// track, 6 DIPs past the track each side, that only holds its selection range: what it holds is checked.
    /// </summary>
    private static bool DrawsNothing(FrameworkElement element) => element switch
    {
        Panel panel => panel.Background is null,
        Border border => border.Background is null && (border.BorderBrush is null || border.BorderThickness == new Thickness(0)),
        _ => false,
    };

    private static string Rounded(Rect r) => $"({r.Left:0.#}, {r.Top:0.#}) {r.Width:0.#}x{r.Height:0.#}";

    /// <summary>A contextmenu handled as if the application sent it, the pointer away from its cell: its menu, open.</summary>
    private async Task<ContextMenu> Show(ContextMenuMessage message)
    {
        var rest = window.Views.Views[30].Host.PointToScreen(new Point(4, 4));
        Native.SetCursorPos((int)Math.Round(rest.X), (int)Math.Round(rest.Y));
        await Task.Delay(150);
        window.Handle(message);
        await Until(() => window.CanvasMenu is { IsOpen: true, IsLoaded: true }, "the contextmenu message opened no menu");
        await Task.Delay(300);
        return window.CanvasMenu!;
    }

    /// <summary>The context menus open in this process (each in a pop-up window of its own).</summary>
    private static List<ContextMenu> OpenMenus() =>
        PresentationSource.CurrentSources.OfType<HwndSource>().Where(source => source.RootVisual is not null)
            .SelectMany(source => Descendants(source.RootVisual!).OfType<ContextMenu>().Where(menu => menu.IsOpen)).ToList();

    private static IEnumerable<DependencyObject> Descendants(DependencyObject parent)
    {
        for (var i = 0; i < VisualTreeHelper.GetChildrenCount(parent); i++)
        {
            var child = VisualTreeHelper.GetChild(parent, i);
            yield return child;
            foreach (var below in Descendants(child))
            {
                yield return below;
            }
        }
    }

    private async Task AppColours()
    {
        // The application's style.fg on a button, a pop-up and tab headers wins over the theme's (in the dark, the
        // stock chrome's styles set their text colour); then put back
        step = "giving button 34, popup 33 and tabs 13 a style.fg";
        const string red = "#d01020";
        var ids = new[] { 34, 33, 13 };
        foreach (var id in ids)
        {
            Update(id, new JsonObject { ["style"] = new JsonObject { ["fg"] = red } });
        }

        window.UpdateLayout();
        await Task.Delay(300);
        Capture("colours.png");
        static string Of(Control control) => control.Foreground is SolidColorBrush b ? $"#{b.Color.R:x2}{b.Color.G:x2}{b.Color.B:x2}" : control.Foreground?.ToString() ?? "none";
        try
        {
            var tabs = (TabControl)window.Views.Views[13].Control!;
            var shown = new List<(string What, string Colour)>
            {
                ("button 34", Of((Control)window.Views.Views[34].Control!)),
                ("popup 33", Of((Control)window.Views.Views[33].Control!)),
            };
            shown.AddRange(tabs.Items.OfType<TabItem>().Select((item, i) => ($"tab header {i} of 13", Of(item))));
            var wrong = shown.Where(s => s.Colour != red).ToList();
            Check(wrong.Count == 0, $"style.fg {red} lost to the theme ({(Theme.SystemIsDark() ? "dark" : "light")}): {string.Join(", ", wrong.Select(w => $"{w.What} is {w.Colour}"))}");
            Say($"style.fg {red} on button 34, popup 33 and the {tabs.Items.Count} tab headers of 13");
        }
        finally
        {
            foreach (var id in ids)
            {
                Update(id, new JsonObject { ["style"] = null });
            }
        }

        Check(Of((Control)window.Views.Views[34].Control!) != red, $"button 34 kept {red} once its style.fg went");
    }

    private void Update(int id, JsonObject props)
    {
        var result = window.Tree.Apply(new PatchOp[] { new UpdateOp(id, props) });
        window.Views.Apply(window.Tree, result);
    }

    /// <summary>The password field 36: typed, then pasted past its maxLength 8. Its value is never printed.</summary>
    private async Task Password()
    {
        step = "typing a password into node 36";
        var secret = (PasswordBox)window.Views.Views[36].Control!;
        secret.Focus();
        Check(secret.IsKeyboardFocused, "the password field 36 does not take the focus");
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, secret, "s3cret"));
        await Label("event 36 change (6 characters)");
        step = "pasting past the maxLength of node 36";
        await SetClipboard("0123456789");
        ApplicationCommands.Paste.Execute(null, secret);
        await Label("event 36 change (8 characters)");
        Check(secret.Password.Length == 8, $"node 36 has {secret.Password.Length} characters, not 8");
        Say("node 36 is a PasswordBox, cut to its maxLength 8");
        // The caret after a cut: PasswordBox's own Select(int, int), not public, puts it after what was kept
        step = "typing past the maxLength of node 36 after its second character, then Backspace";
        var select = typeof(PasswordBox).GetMethod("Select", System.Reflection.BindingFlags.Instance | System.Reflection.BindingFlags.NonPublic | System.Reflection.BindingFlags.Public, null, new[] { typeof(int), typeof(int) }, null);
        Check(select is not null, "PasswordBox has no Select(int, int)");
        Say($"PasswordBox has {(select!.IsPublic ? "public" : "non-public")} {select.ReturnType.Name} {select.Name}(int, int)");
        var full = secret.Password;
        select.Invoke(secret, new object[] { 2, 0 });
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, secret, "Z"));
        await Quiet("event 36 change (8 characters)");
        Check(secret.Password == full, "typing Z into node 36, full, changed it");
        System.Windows.Documents.EditingCommands.Backspace.Execute(null, secret);
        await Label("event 36 change (7 characters)");
        Check(secret.Password == full[..1] + full[2..], "Backspace after Z was refused in node 36 did not delete its second character: the caret was not put back where the cut left it");
        Say("node 36: Z refused after its second character, the caret put back there by Select(int, int): Backspace deleted that character");
    }

    /// <summary>The number field 37 (1, 0 to 10 by 0.5): Up, Down twice, its up arrow, then x typed.</summary>
    private async Task Number()
    {
        step = "stepping node 37 with Up and Down";
        var view = window.Views.Views[37];
        var number = view.Field!;
        number.Focus();
        Check(number.IsKeyboardFocused, "the number field 37 does not take the focus");
        Press(number, Key.Up);
        await Label("event 37 change \"1.5\"");
        Press(number, Key.Down);
        Press(number, Key.Down);
        await Label("event 37 change \"0.5\"");
        step = "clicking the up arrow of node 37";
        var up = ((DockPanel)view.Host.Child).Children.OfType<Grid>().Single().Children.OfType<RepeatButton>().First();
        (UIElementAutomationPeer.CreatePeerForElement(up)?.GetPattern(PatternInterface.Invoke) as IInvokeProvider ?? throw new InvalidOperationException("the arrow of node 37 has no Invoke pattern")).Invoke();
        await Label("event 37 change \"1\"");
        step = "typing an invalid number into node 37";
        number.Select(number.Text.Length, 0);
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, number, "x"));
        await Label("event 37 change \"1x\"");
        await Quiet("event 37 change \"1x\"");
        Check(number.Text == "1x" && window.Tree.Find(37)!.String("value") == "1x", $"node 37 shows \"{number.Text}\", not the 1x typed");
        Say("node 37 stepped to 1.5 with Up, 0.5 with Down twice, 1 with its up arrow, and sent and kept 1x as typed");
        // Past max, then off the steps: sent and kept as typed, also when Enter or the focus leaving commits it
        step = "typing 12, past the max of node 37, then Enter";
        number.SelectAll();
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, number, "12"));
        await Label("event 37 change \"12\"");
        Press(number, Key.Enter);
        await Label("event 37 submit \"12\"");
        Check(number.Text == "12", $"Enter made node 37 \"{number.Text}\", not the 12 typed");
        step = "typing 2.3, off the steps of node 37, then leaving it";
        number.SelectAll();
        TextCompositionManager.StartComposition(new TextComposition(InputManager.Current, number, "2.3"));
        await Label("event 37 change \"2.3\"");
        window.Views.Views[38].Control!.Focus();
        await Quiet("event 37 change \"2.3\"");
        Check(number.Text == "2.3" && window.Tree.Find(37)!.String("value") == "2.3", $"the focus leaving made node 37 \"{number.Text}\", not the 2.3 typed");
        Say("node 37 sent and kept 12, past its max, on Enter, and 2.3, off its steps, when the focus left");
    }

    /// <summary>The slider 38 (50, by 5): Right, then dragged to 73.</summary>
    private async Task Slider()
    {
        step = "moving the slider 38 with Right";
        var slider = (Slider)window.Views.Views[38].Control!;
        slider.Focus();
        Check(slider.IsKeyboardFocused, "the slider 38 does not take the focus");
        Press(slider, Key.Right);
        await Label("event 38 change 55");
        step = "dragging the slider 38 to 73";
        slider.Value = 73;
        await Label("event 38 change 75");
        Check(slider.Value == 75, $"the slider 38 is at {slider.Value}, not 75");
        Say("node 38 went to 55 with Right, and to 75, its step, when dragged to 73");
    }

    /// <summary>The list box 39 (multiple, row 0 selected): rows 1 and 2 selected as Ctrl+clicks do, then Down.</summary>
    private async Task Listbox()
    {
        step = "selecting rows 1 and 2 of the list box 39";
        var list = (ListBox)window.Views.Views[39].Control!;
        Check(list.SelectionMode == SelectionMode.Extended && list.Items.Count == 4 && list.SelectedItems.Count == 1 && list.SelectedIndex == 0,
            $"node 39 has {list.Items.Count} rows, {list.SelectionMode} selection, rows {string.Join(",", list.SelectedItems.OfType<object>().Select(list.Items.IndexOf))} selected");
        // A click on row 1, then a Ctrl+click on row 2
        var second = (ListBoxItem)list.Items[1];
        var third = (ListBoxItem)list.Items[2];
        list.SelectedItem = second;
        third.IsSelected = true;
        await Label("event 39 select [1,2]");
        step = "moving down in the list box 39";
        third.Focus();
        Check(third.IsKeyboardFocused, "row 2 of the list box 39 does not take the focus");
        Press(third, Key.Down);
        await Label("event 39 select [3]");
        Say("node 39 sent [1,2], then [3] for Down");
    }

    /// <summary>
    /// inputs.png: nodes 36 to 39 as the screen shows them. Below the canvas's 12th row, they are past the bottom
    /// of a window that fits a small screen (a VM's 1024x768), and Windows neither makes a window higher than the
    /// screen nor draws what is off it: the root's other children but label node 2 are collapsed for this
    /// capture, and shown again after it.
    /// </summary>
    private async Task CaptureInputs()
    {
        if (Environment.GetEnvironmentVariable("TUI_APP_CANVAS_CAPTURE") is not { Length: > 0 })
        {
            return;
        }

        var list = window.Views.Views[39].Host;
        var bottom = list.TransformToAncestor(window).Transform(new Point(0, list.ActualHeight)).Y;
        var collapsed = new List<(UIElement Host, Visibility Was)>();
        if (bottom > ((FrameworkElement)window.Content).ActualHeight)
        {
            foreach (var child in window.Tree.Root!.Children.Where(c => c.Id is not (2 or 21) && window.Views.Views.ContainsKey(c.Id)))
            {
                var host = window.Views.Views[child.Id].Host;
                collapsed.Add((host, host.Visibility));
                host.Visibility = Visibility.Collapsed;
            }

            window.UpdateLayout();
            await Task.Delay(300);
            Say($"nodes 36 to 39 are past the window's bottom: {collapsed.Count} other nodes collapsed for the capture");
        }

        try
        {
            Capture("inputs.png");
        }
        finally
        {
            foreach (var (host, was) in collapsed)
            {
                host.Visibility = was;
            }

            window.UpdateLayout();
            await Task.Delay(300);
        }
    }

    /// <summary>
    /// Text on the clipboard, tried again for up to 10 s while another process has it open (CLIPBRD_E_CANT_OPEN):
    /// a VM's clipboard agent reads each change to share it.
    /// </summary>
    private async Task SetClipboard(string text)
    {
        var waited = Stopwatch.StartNew();
        for (var attempt = 1; ; attempt++)
        {
            try
            {
                Clipboard.SetText(text);
                if (attempt > 1)
                {
                    Say($"the clipboard was busy: set at attempt {attempt}, after {waited.ElapsedMilliseconds} ms");
                }

                return;
            }
            catch (System.Runtime.InteropServices.COMException) when (waited.ElapsedMilliseconds < 10000)
            {
                // Setting it again makes the agent read it again: the text may be there already, only the flush failed
                // Waited for with the dispatcher running: the agent reading it may be waiting for this thread to render it
                await Task.Delay(300);
                try
                {
                    if (Clipboard.ContainsText() && Clipboard.GetText() == text)
                    {
                        Say($"the clipboard was busy: its text there at attempt {attempt}, after {waited.ElapsedMilliseconds} ms");
                        return;
                    }
                }
                catch (System.Runtime.InteropServices.COMException)
                {
                }
            }
        }
    }

    /// <summary>A key pressed and released, through the input manager as from the keyboard, to the focused element.</summary>
    private static void Press(Visual focused, Key key)
    {
        var source = PresentationSource.FromVisual(focused) ?? throw new InvalidOperationException($"{focused} is not on screen");
        InputManager.Current.ProcessInput(new KeyEventArgs(Keyboard.PrimaryDevice, source, Environment.TickCount, key) { RoutedEvent = Keyboard.PreviewKeyDownEvent });
        InputManager.Current.ProcessInput(new KeyEventArgs(Keyboard.PrimaryDevice, source, Environment.TickCount, key) { RoutedEvent = Keyboard.PreviewKeyUpEvent });
    }

    /// <summary>Its width in cells, as the rects count it.</summary>
    private int Width(NodeView view)
    {
        var root = (FrameworkElement)window.Views.Root!;
        var bounds = view.Host.TransformToAncestor(root).TransformBounds(new Rect(view.Host.RenderSize));
        return Cells.Rect(bounds.X, bounds.Y, bounds.Width, bounds.Height, window.Cell.Width, window.Cell.Height)[2];
    }

    private static T Provider<T>(NodeView view, PatternInterface pattern) where T : class =>
        UIElementAutomationPeer.CreatePeerForElement(view.Control!)?.GetPattern(pattern) as T
        ?? throw new InvalidOperationException($"node {view.Id} has no {pattern} pattern");

    private static int[] Ints(JsonNode? node) => (node as JsonArray)?.Select(v => v?.GetValue<int>() ?? 0).ToArray() is { Length: 4 } values ? values : new int[4];

    private static bool Near(Rect a, Rect b) =>
        Math.Abs(a.X - b.X) <= 1 && Math.Abs(a.Y - b.Y) <= 1 && Math.Abs(a.Width - b.Width) <= 1 && Math.Abs(a.Height - b.Height) <= 1;

    private string? Text() => window.Views.Views.TryGetValue(2, out var label) ? label.Text?.Text : null;

    private async Task Label(string expected)
    {
        var waited = Stopwatch.StartNew();
        while (Text() != expected)
        {
            if (waited.Elapsed > LabelWait)
            {
                Fail($"{step}: node 2 reads \"{Text()}\", not \"{expected}\", after {LabelWait.TotalSeconds:0} s");
                throw new Abort();
            }

            await Task.Delay(50);
        }

        Say($"node 2 reads \"{expected}\" after {waited.ElapsedMilliseconds} ms");
    }

    /// <summary>Nothing more came: node 2 still reads it after 300 ms.</summary>
    private async Task Quiet(string expected)
    {
        await Task.Delay(300);
        Check(Text() == expected, $"{step}: node 2 reads \"{Text()}\", more than \"{expected}\" came");
    }

    private async Task Until(Func<bool> condition, string failure)
    {
        var waited = Stopwatch.StartNew();
        while (!condition())
        {
            if (waited.Elapsed > LabelWait)
            {
                Fail(failure);
                throw new Abort();
            }

            await Task.Delay(50);
        }
    }

    private void Check(bool condition, string failure)
    {
        if (!condition)
        {
            Fail(failure);
            throw new Abort();
        }
    }

    /// <summary>TUI_APP_CANVAS_CAPTURE=&lt;dir&gt;: a PNG of the window as the screen shows it (WindowCapture).</summary>
    private void Capture(string name)
    {
        if (Environment.GetEnvironmentVariable("TUI_APP_CANVAS_CAPTURE") is not { Length: > 0 } directory)
        {
            return;
        }

        window.UpdateLayout();
        // Drawn on the screen first
        window.Dispatcher.Invoke(DispatcherPriority.Render, () => { });
        Directory.CreateDirectory(directory);
        var path = Path.Combine(directory, name);
        var (width, height) = WindowCapture.Save(new WindowInteropHelper(window).Handle, path);
        Say($"captured {path} ({width}x{height} px)");
    }

    private void Closed()
    {
        if (done)
        {
            return;
        }

        if (checkedAll && window.Flow.ByeReceived && window.Flow.HasExited)
        {
            done = true;
            Say($"PASS in {clock.Elapsed.TotalSeconds:0.0} s");
            application.Shutdown(0);
            return;
        }

        Fail($"the window closed {step}" + (window.Flow.ByeReceived ? "" : " (no bye)"));
    }

    private void Fail(string why)
    {
        if (done)
        {
            return;
        }

        done = true;
        Console.Out.WriteLine($"canvas-test: FAIL: {why}");
        Console.Out.Flush();
        application.Shutdown(1);
    }

    private void Say(string line)
    {
        Console.Out.WriteLine($"canvas-test: [{clock.Elapsed.TotalSeconds:0.00}s] {line}");
        Console.Out.Flush();
    }

    /// <summary>Stops the steps after a failure, which was already reported.</summary>
    private sealed class Abort : Exception
    {
    }

    /// <summary>What an input method sends while composing: the text so far, nothing committed.</summary>
    private sealed class Composing : TextComposition
    {
        public Composing(IInputElement target, string composing)
            : base(InputManager.Current, target, "")
        {
            CompositionText = composing;
        }
    }
}
