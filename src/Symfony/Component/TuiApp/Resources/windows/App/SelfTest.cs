using System;
using System.Diagnostics;
using System.Linq;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Threading;

namespace TuiAppHost;

/// <summary>
/// --self-test (HOSTS.md): type "Ada" into textfield node 3 and submit it, wait for label node 2 to read
/// "Hello Ada!", check a native control exists for every node, close, and exit 0 once the application
/// sent bye and exited. Exit 1, saying why, on any failure or after 20 s.
/// </summary>
public sealed class SelfTest
{
    private static readonly TimeSpan Overall = TimeSpan.FromSeconds(20);
    private static readonly TimeSpan LabelWait = TimeSpan.FromSeconds(5);

    private readonly HostWindow window;
    private readonly Application application;
    private readonly Stopwatch clock = Stopwatch.StartNew();
    private readonly DispatcherTimer deadline = new() { Interval = Overall };
    private string step = "waiting for the first patch";
    private bool typed;
    private bool checkedTree;
    private bool done;

    public SelfTest(HostWindow window, Application application)
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
        Say("started");
        var keys = Input.CheckKeyTable();
        if (keys.Length > 0)
        {
            Fail("key table: " + keys);
            return;
        }

        Say("the key table matches System.Windows.Input.Key");
        deadline.Start();
    }

    private void Patched()
    {
        if (typed || !window.Views.Views.TryGetValue(3, out var field) || field.Field is null)
        {
            return;
        }

        typed = true;
        Say($"first patch drawn: {window.Tree.Nodes.Count} nodes, hello sent: {window.HelloSent}, rects sent: {window.RectsSent}");
        window.Dispatcher.BeginInvoke(DispatcherPriority.Background, () => Type(field.Field));
    }

    private void Type(TextBox box)
    {
        step = "typing into node 3";
        box.Focus();
        // Through the toolkit, as a user would: TextChanged sends change, Enter sends submit.
        box.Text = "Ada";
        box.CaretIndex = 3;
        var source = PresentationSource.FromVisual(box);
        if (source is null)
        {
            Fail("node 3 is not on screen");
            return;
        }

        box.RaiseEvent(new KeyEventArgs(Keyboard.PrimaryDevice, source, Environment.TickCount, Key.Enter) { RoutedEvent = Keyboard.KeyDownEvent });
        Say("typed \"Ada\" into node 3 and pressed Enter");
        step = "waiting for node 2 to read \"Hello Ada!\"";
        var started = clock.Elapsed;
        var poll = new DispatcherTimer { Interval = TimeSpan.FromMilliseconds(50) };
        poll.Tick += (_, _) =>
        {
            var text = window.Views.Views.TryGetValue(2, out var label) ? label.Text?.Text : null;
            if (text == "Hello Ada!")
            {
                poll.Stop();
                Say($"node 2 reads \"{text}\" after {(clock.Elapsed - started).TotalMilliseconds:0} ms");
                CheckTree();
            }
            else if (clock.Elapsed - started > LabelWait)
            {
                poll.Stop();
                Fail($"node 2 reads \"{text}\", not \"Hello Ada!\", after {LabelWait.TotalSeconds:0} s");
            }
        };
        poll.Start();
    }

    private void CheckTree()
    {
        step = "checking the native controls";
        var missing = window.Tree.Walk().Where(node =>
            !window.Views.Views.TryGetValue(node.Id, out var view)
            || (node.Kind != "stack" && view.Control is null)
            || (node.Kind == "stack" && view.Panel is null)
            || (node != window.Tree.Root && System.Windows.LogicalTreeHelper.GetParent(view.Host) is null && System.Windows.Media.VisualTreeHelper.GetParent(view.Host) is null)).ToList();
        if (missing.Count > 0)
        {
            Fail("no native control for " + string.Join(", ", missing.Select(n => $"{n.Id} ({n.Kind})")));
            return;
        }

        var controls = window.Tree.Walk().Select(node =>
        {
            var view = window.Views.Views[node.Id];
            var control = (FrameworkElement?)view.Field ?? view.Canvas ?? view.Control ?? view.Panel;
            return $"{node.Id} {node.Kind}={control!.GetType().Name}";
        });
        Say("native controls: " + string.Join(", ", controls));
        if (window.RectsSent == 0)
        {
            Fail("no rects were sent");
            return;
        }

        checkedTree = true;
        step = "closing: waiting for close-reply, bye and the exit";
        Say("closing the window");
        window.Close();
    }

    private void Closed()
    {
        if (done)
        {
            return;
        }

        if (checkedTree && window.Flow.ByeReceived && window.Flow.HasExited)
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
        Console.Out.WriteLine($"self-test: FAIL: {why}");
        Console.Out.Flush();
        application.Shutdown(1);
    }

    private void Say(string line)
    {
        Console.Out.WriteLine($"self-test: [{clock.Elapsed.TotalSeconds:0.00}s] {line}");
        Console.Out.Flush();
    }
}
