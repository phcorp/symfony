using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json.Nodes;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Shell;
using System.Windows.Threading;
using Microsoft.Win32;
using TuiAppHost.Core;

namespace TuiAppHost;

/// <summary>
/// --feature-test (HOSTS.md): with tools/fake-app.php and FAKE_APP_FEATURES=1, or tools/feature-app.php,
/// checks notify, the taskbar progress and beep, the menus, the system appearance flipping and coming back,
/// files and URLs opened at launch and while running (a second launch, with singleInstance and a manifest),
/// and the close flow down to the force-quit question, which it answers as a user would: Wait (No), then
/// Force Quit (Yes). Each step waits up to 5 s for label node 2. Exit 0 once the application was killed and
/// the window closed without an error dialog; 1, saying why, on any failure or after 90 s.
/// TUI_APP_FEATURE_CAPTURE=&lt;dir&gt; writes window.png, alert-1.png and alert-2.png.
/// </summary>
public sealed class FeatureTest
{
    private const string OpenFile = "-feature-test.fake";
    private const string OpenUrl = "fake-tuiapp://feature?id=42";
    private const string PersonalizeKey = @"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize";
    private static readonly TimeSpan Overall = TimeSpan.FromSeconds(90);
    private static readonly TimeSpan LabelWait = TimeSpan.FromSeconds(5);

    private readonly HostWindow window;
    private readonly Application application;
    private readonly Manifest manifest;
    private readonly HostOptions options;
    private readonly Stopwatch clock = Stopwatch.StartNew();
    private readonly DispatcherTimer deadline = new() { Interval = Overall };
    private string stage = "waiting for the first patch";
    private bool started;
    private bool done;
    private int alerts;
    private int? pid;
    private object? originalLight;

    public FeatureTest(HostWindow window, Application application, Manifest manifest, HostOptions options)
    {
        this.window = window;
        this.application = application;
        this.manifest = manifest;
        this.options = options;
        deadline.Tick += (_, _) => Fail($"no result after {Overall.TotalSeconds:0} s, while {stage}");
        window.Patched += Patched;
        window.ForceQuitOffered += ForceQuitOffered;
        window.Closed += (_, _) => Closed();
    }

    public void Start()
    {
        // Which build runs where: an x64 host on Windows on Arm runs emulated
        Say($"started: a {System.Runtime.InteropServices.RuntimeInformation.ProcessArchitecture} process on {System.Runtime.InteropServices.RuntimeInformation.OSArchitecture} Windows");
        deadline.Start();
    }

    private void Patched()
    {
        if (started || !window.Views.Views.ContainsKey(2))
        {
            return;
        }

        started = true;
        pid = window.Process?.Id;
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
                Fail($"{stage}: {exception}");
            }
        });
    }

    private async Task Run()
    {
        // 1. notify, progress and beep came before "ready", or the files given at launch
        stage = "waiting for \"ready\"";
        if (window.LaunchPaths.Count > 0)
        {
            Say("open at launch: " + string.Join(" | ", window.LaunchPaths));
            await Label("open: " + string.Join(" | ", window.LaunchPaths));
        }
        else
        {
            await Label("ready");
        }

        stage = "checking notify, progress and beep";
        Check(window.Notifications == 1, $"expected 1 notify, got {window.Notifications}");
        Check(window.Tray is { Visible: true }, "notify showed no notification icon");
        Say("notify received and handed to the shell: " + TrayIconOnScreen());
        var taskbar = window.TaskbarItemInfo;
        Check(taskbar is { ProgressState: TaskbarItemProgressState.Normal } && Math.Abs(taskbar.ProgressValue - 0.5) < 1e-9,
            $"the taskbar progress is {taskbar?.ProgressState} {taskbar?.ProgressValue}, not Normal 0.5");
        Say("progress normal 0.5 set on the taskbar button (TaskbarItemInfo, ITaskbarList3)");
        Check(window.Beeps == 1, $"expected 1 beep, got {window.Beeps}");
        Say("beep received and played (SystemSounds.Beep, MessageBeep)");
        SaveWindow("window.png");

        // 2. File > Save through its shortcut, then File > Wrap lines as a click does
        stage = "menu";
        var binding = window.InputBindings.OfType<KeyBinding>().FirstOrDefault(b => b.Gesture is KeyGesture { Key: Key.S, Modifiers: ModifierKeys.Control });
        Check(binding is not null, "ctrl+s is bound to nothing");
        Check(binding!.Command.CanExecute(binding.CommandParameter), "the command of ctrl+s cannot run");
        binding.Command.Execute(binding.CommandParameter);
        Say("pressed ctrl+s: its key binding ran the menu command");
        await Label("menu: file.save");
        var wrap = Items(window.MenuBar).FirstOrDefault(item => item.Header as string == "Wrap lines");
        Check(wrap is not null, "no File > Wrap lines item in the menu bar");
        Check(wrap!.IsChecked, "File > Wrap lines is not checked");
        wrap.RaiseEvent(new RoutedEventArgs(MenuItem.ClickEvent, wrap));
        Say("chose File > Wrap lines (checked)");
        await Label("menu: file.wrap");

        // 3. The appearance flips, then comes back, as Settings > Personalization > Colors does
        stage = "appearance";
        if (manifest.Window.Theme != "system")
        {
            Say($"window.theme is {manifest.Window.Theme}: appearance changes are not sent; skipped");
        }
        else
        {
            var dark = Theme.For("system").Dark;
            SetAppsLight(dark);
            await Label(dark ? "appearance: light" : "appearance: dark");
            if (!dark)
            {
                await DarkColours();
            }

            RestoreAppearance();
            await Label(dark ? "appearance: dark" : "appearance: light");
            if (dark)
            {
                await DarkColours();
            }
        }

        // 4. A second launch hands a file and a URL over, as the file and URL handlers start it (-- "%1")
        stage = "open while running";
        if (!manifest.App.SingleInstance || options.ManifestPath is null)
        {
            Say("open while running: skipped, a second launch hands its files over only with singleInstance and a manifest");
        }
        else
        {
            await OpenWhileRunning();
        }

        // 5. The close button: the application refuses
        stage = "close, kept open";
        window.Close();
        Say("clicked the close button");
        await Label("close: kept open");
        await Task.Delay(500);
        Check(window.IsVisible, "the window closed while " + stage);
        Say("the application kept the window open (close-reply false)");

        // 6. The close button: no answer; 7. it hangs (ForceQuitOffered goes on)
        stage = "close, no answer";
        window.Close();
        Say("clicked the close button; the application does not answer");
    }

    private async Task OpenWhileRunning()
    {
        var directory = Directory.CreateDirectory(Path.Combine(Path.GetTempPath(), "tui-feature-" + Guid.NewGuid().ToString("N")[..8])).FullName;
        var file = Path.Combine(directory, OpenFile);
        File.WriteAllText(file, "x");
        try
        {
            // A relative name that looks like an option, after --: still a file
            var start = new ProcessStartInfo(Environment.ProcessPath!) { UseShellExecute = false, WorkingDirectory = directory };
            foreach (var argument in new[] { "--manifest", Path.GetFullPath(options.ManifestPath!), "--", OpenFile, OpenUrl })
            {
                start.ArgumentList.Add(argument);
            }

            using var second = Process.Start(start) ?? throw new InvalidOperationException("no second instance");
            Say($"launched a second instance: {Path.GetFileName(Environment.ProcessPath)} --manifest … -- {OpenFile} {OpenUrl}");
            await Label($"open: {file} | {OpenUrl}");
            await Task.Run(() => second.WaitForExit(10000));
            Check(second.HasExited && second.ExitCode == 0, second.HasExited ? $"the second launch exited {second.ExitCode}" : "the second launch is still running");
            Say("the second launch exited 0");
        }
        finally
        {
            File.Delete(file);
            Directory.Delete(directory);
        }
    }

    /// <summary>The force-quit question is up, modal: answered once it shows, as a user would.</summary>
    private void ForceQuitOffered()
    {
        alerts++;
        Say($"the force-quit dialog is coming: \"{manifest.App.Name} is not responding. Force quit?\"");
        var answer = alerts == 1 ? "Wait (No)" : "Force Quit (Yes)";
        var timer = new DispatcherTimer { Interval = TimeSpan.FromMilliseconds(500) };
        var number = alerts;
        timer.Tick += (_, _) =>
        {
            timer.Stop();
            var dialog = Dialog();
            if (dialog == IntPtr.Zero)
            {
                Fail("no force-quit dialog on the window");
                return;
            }

            Say($"the dialog is up: \"{Text(dialog)}\" with \"{Text(Win32.GetDlgItem(dialog, Win32.IdYes))}\" and \"{Text(Win32.GetDlgItem(dialog, Win32.IdNo))}\"");
            SaveDialog(dialog, $"alert-{number}.png");
            Say("clicking " + answer);
            if (number == 1)
            {
                Win32.PostMessage(Win32.GetDlgItem(dialog, Win32.IdNo), Win32.BmClick, IntPtr.Zero, IntPtr.Zero);
                After(500, CloseHung);
            }
            else
            {
                stage = "force quit";
                Win32.PostMessage(Win32.GetDlgItem(dialog, Win32.IdYes), Win32.BmClick, IntPtr.Zero, IntPtr.Zero);
            }
        };
        timer.Start();
    }

    private void CloseHung()
    {
        if (!window.IsVisible)
        {
            Fail("the window closed while " + stage);
            return;
        }

        Say("Wait kept the window open");
        stage = "close, hung";
        window.Close();
        Say("clicked the close button; the application hangs");
    }

    private void Closed()
    {
        RestoreAppearance();
        if (done)
        {
            return;
        }

        if (stage != "force quit")
        {
            Fail("the window closed while " + stage);
            return;
        }

        if (window.Errors > 0)
        {
            Fail("the force quit showed an error dialog");
            return;
        }

        if (pid is not { } id)
        {
            Fail("the application's process is unknown");
            return;
        }

        try
        {
            using var left = Process.GetProcessById(id);
            if (!left.WaitForExit(5000))
            {
                Fail($"process {id} is still there");
                return;
            }

            Say($"the application was killed (exit {left.ExitCode}); no error dialog");
        }
        catch (ArgumentException)
        {
            // Gone already
        }

        Say($"the window closed; process {id} is gone");
        Say("ok");
        done = true;
        application.Shutdown(0);
    }

    /// <summary>
    /// In the dark, the application's style.fg wins over the theme's styles on the chrome of a form's pop-up (its
    /// label, the choice it shows and the items of its drop-down), of a popup node (the same) and of a spinner's
    /// Cancel button: a form, a popup and a spinner added to the root as if the application sent them, the colour of
    /// the text they draw read, each drop-down opened for its items, then removed.
    /// </summary>
    private async Task DarkColours()
    {
        stage = "the application's text colour in the dark";
        const string red = "#d01020";
        const int formId = 990001, spinnerId = 990002, popupId = 990003;
        var root = window.Tree.Root;
        Check(root is { Kind: "stack" }, $"the root is {root?.Kind ?? "missing"}, not a stack to add a form and a spinner to");
        JsonObject Style() => new() { ["fg"] = red };
        var form = new JsonObject
        {
            ["style"] = Style(),
            ["rows"] = new JsonArray(new JsonObject { ["id"] = "size", ["label"] = "Size", ["choices"] = new JsonArray("Small", "Large"), ["value"] = "Large" }),
        };
        var spinner = new JsonObject { ["style"] = Style(), ["text"] = "Working", ["running"] = true, ["cancellable"] = true };
        var popup = new JsonObject { ["style"] = Style(), ["choices"] = new JsonArray("North", "South"), ["selected"] = 1 };
        var at = root!.Children.Count;
        Patch(new CreateOp(formId, "form", root.Id, at, form), new CreateOp(spinnerId, "spinner", root.Id, at + 1, spinner), new CreateOp(popupId, "popup", root.Id, at + 2, popup));
        try
        {
            window.UpdateLayout();
            await Task.Delay(300);
            SaveWindow("dark-colours.png");
            var formHost = window.Views.Views[formId].Host;
            var combo = Descendants(formHost).OfType<ComboBox>().FirstOrDefault();
            Check(combo is not null, "the form has no pop-up for its choices row");
            var popupBox = (ComboBox)window.Views.Views[popupId].Control!;
            var shown = new List<(string What, TextBlock? Text)>
            {
                ("the form's label Size", Descendants(formHost).OfType<TextBlock>().FirstOrDefault(t => t.Text == "Size")),
                ("the choice Large its pop-up shows", Descendants(combo!).OfType<TextBlock>().FirstOrDefault(t => t.Text == "Large")),
                ("the choice South popup 990003 shows", Descendants(popupBox).OfType<TextBlock>().FirstOrDefault(t => t.Text == "South")),
                ("the spinner's Cancel button", Descendants(window.Views.Views[spinnerId].Host).OfType<Button>().SelectMany(b => Descendants(b).OfType<TextBlock>()).FirstOrDefault(t => t.Text == "Cancel")),
            };
            shown.Add(("the item Small in the form's opened drop-down", await DropDownItem(combo!, "Small")));
            shown.Add(("the item North in popup 990003's opened drop-down", await DropDownItem(popupBox, "North")));
            var missing = shown.Where(s => s.Text is null).Select(s => s.What).ToList();
            Check(missing.Count == 0, $"no text drawn for {string.Join(", ", missing)}");
            var wrong = shown.Where(s => Hex(s.Text!.Foreground) != red).ToList();
            Check(wrong.Count == 0, $"style.fg {red} lost to the dark theme: {string.Join(", ", wrong.Select(w => $"{w.What} is drawn in {Hex(w.Text!.Foreground)}"))}");
            Say($"in the dark, style.fg {red} draws {string.Join(", ", shown.Select(s => s.What))}");

            // An update of another prop, the list open: the items stay what they were (a new item style would make
            // them again, and lose the one the mouse or the keys highlighted)
            popupBox.IsDropDownOpen = true;
            try
            {
                await Task.Delay(300);
                popupBox.UpdateLayout();
                List<DependencyObject?> Containers() => Enumerable.Range(0, popupBox.Items.Count).Select(i => (DependencyObject?)popupBox.ItemContainerGenerator.ContainerFromIndex(i)).ToList();
                var before = Containers();
                var lit = before.OfType<ComboBoxItem>().FirstOrDefault(c => c.IsHighlighted);
                Check(before.All(c => c is ComboBoxItem), "popup 990003's open list has no item containers");
                Patch(new UpdateOp(popupId, new JsonObject { ["label"] = "Direction" }));
                await Task.Delay(300);
                popupBox.UpdateLayout();
                var after = Containers();
                Check(before.Zip(after, ReferenceEquals).All(same => same), "an update of popup 990003's label, its list open, made its items again");
                Check(lit is null || lit.IsHighlighted, $"an update of popup 990003's label, its list open, lost its highlighted item {lit?.Content}");
                Say($"an update of popup 990003's label, its list open, kept its {after.Count} items{(lit is null ? "" : $" and the highlighted one, {lit.Content}")}");
            }
            finally
            {
                popupBox.IsDropDownOpen = false;
            }
        }
        finally
        {
            Patch(new RemoveOp(formId), new RemoveOp(spinnerId), new RemoveOp(popupId));
        }
    }

    /// <summary>The text an item of a pop-up's drop-down draws, the drop-down opened (then closed again).</summary>
    private async Task<TextBlock?> DropDownItem(ComboBox combo, string item)
    {
        combo.IsDropDownOpen = true;
        try
        {
            await Task.Delay(300);
            combo.UpdateLayout();
            var index = combo.Items.IndexOf(item);
            return combo.ItemContainerGenerator.ContainerFromIndex(index) is ComboBoxItem container
                ? Descendants(container).OfType<TextBlock>().FirstOrDefault(t => t.Text == item)
                : null;
        }
        finally
        {
            combo.IsDropDownOpen = false;
        }
    }

    private void Patch(params PatchOp[] ops)
    {
        var result = window.Tree.Apply(ops);
        window.Views.Apply(window.Tree, result);
    }

    private static string Hex(Brush? brush) => brush is SolidColorBrush b ? $"#{b.Color.R:x2}{b.Color.G:x2}{b.Color.B:x2}" : brush?.ToString() ?? "none";

    private static System.Collections.Generic.IEnumerable<DependencyObject> Descendants(DependencyObject parent)
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

    /// <summary>AppsUseLightTheme, then WM_SETTINGCHANGE "ImmersiveColorSet" to every window, as Settings does.</summary>
    private void SetAppsLight(bool light)
    {
        using var key = Registry.CurrentUser.CreateSubKey(PersonalizeKey);
        originalLight ??= key.GetValue("AppsUseLightTheme") ?? -1;
        key.SetValue("AppsUseLightTheme", light ? 1 : 0, RegistryValueKind.DWord);
        Broadcast();
        Say($"set AppsUseLightTheme to {(light ? 1 : 0)} and broadcast WM_SETTINGCHANGE ImmersiveColorSet");
    }

    private void RestoreAppearance()
    {
        if (originalLight is not { } original)
        {
            return;
        }

        originalLight = null;
        using var key = Registry.CurrentUser.CreateSubKey(PersonalizeKey);
        if (original is int value && value >= 0)
        {
            key.SetValue("AppsUseLightTheme", value, RegistryValueKind.DWord);
        }
        else
        {
            key.DeleteValue("AppsUseLightTheme", false);
        }

        Broadcast();
        Say("put AppsUseLightTheme back");
    }

    private static void Broadcast() =>
        Win32.SendMessageTimeout(new IntPtr(0xffff), Win32.SettingChange, IntPtr.Zero, "ImmersiveColorSet", 2 /* SMTO_ABORTIFHUNG */, 2000, out _);

    /// <summary>Where the shell shows the notification icon: it holds it only once notify reached it.</summary>
    private string TrayIconOnScreen()
    {
        var tray = window.Tray!;
        var fields = typeof(System.Windows.Forms.NotifyIcon).GetFields(BindingFlags.Instance | BindingFlags.NonPublic);
        var idField = fields.FirstOrDefault(f => (f.FieldType == typeof(int) || f.FieldType == typeof(uint)) && f.Name.TrimStart('_').Equals("id", StringComparison.OrdinalIgnoreCase));
        var id = idField?.GetValue(tray) is { } value ? Convert.ToUInt32(value) : (uint?)null;
        var owner = fields.Where(f => typeof(System.Windows.Forms.NativeWindow).IsAssignableFrom(f.FieldType)).Select(f => f.GetValue(tray)).OfType<System.Windows.Forms.NativeWindow>().FirstOrDefault()?.Handle;
        if (id is null || owner is null || owner == IntPtr.Zero)
        {
            return "its window is not known here (NotifyIcon fields: " + string.Join(", ", fields.Select(f => $"{f.Name}:{f.FieldType.Name}")) + ")";
        }

        var identifier = new Win32.NotifyIconIdentifier { Size = (uint)Marshal.SizeOf<Win32.NotifyIconIdentifier>(), Window = owner.Value, Id = id.Value };
        var result = Win32.Shell_NotifyIconGetRect(ref identifier, out var rect);
        Check(result == 0, $"the shell does not hold the notification icon (Shell_NotifyIconGetRect 0x{result:x8})");
        return $"Shell_NotifyIconGetRect finds its icon at {rect.Left},{rect.Top} (balloon shown with ShowBalloonTip)";
    }

    private IntPtr Dialog()
    {
        var owner = new WindowInteropHelper(window).Handle;
        var found = IntPtr.Zero;
        var name = new StringBuilder(64);
        Win32.EnumThreadWindows(Win32.GetCurrentThreadId(), (handle, _) =>
        {
            name.Clear();
            Win32.GetClassName(handle, name, name.Capacity);
            if (name.ToString() == "#32770" && Win32.IsWindowVisible(handle) && Win32.GetWindow(handle, 4 /* GW_OWNER */) == owner)
            {
                found = handle;
                return false;
            }

            return true;
        }, IntPtr.Zero);
        return found;
    }

    private static string Text(IntPtr handle)
    {
        var text = new StringBuilder(256);
        Win32.GetWindowText(handle, text, text.Capacity);
        if (Win32.GetDlgItem(handle, 0xffff) is var label && label != IntPtr.Zero)
        {
            // A message box's text is its static control's
            var message = new StringBuilder(512);
            Win32.GetWindowText(label, message, message.Capacity);
            return message.ToString();
        }

        return text.ToString().Replace("&", "");
    }

    private static System.Collections.Generic.IEnumerable<MenuItem> Items(ItemsControl menu)
    {
        foreach (var item in menu.Items.OfType<MenuItem>())
        {
            yield return item;
            foreach (var inner in Items(item))
            {
                yield return inner;
            }
        }
    }

    private static string? CaptureDirectory() =>
        Environment.GetEnvironmentVariable("TUI_APP_FEATURE_CAPTURE") is { Length: > 0 } directory ? Directory.CreateDirectory(directory).FullName : null;

    /// <summary>The window as the screen shows it (WindowCapture).</summary>
    private void SaveWindow(string name)
    {
        if (CaptureDirectory() is not { } directory)
        {
            return;
        }

        var (width, height) = WindowCapture.Save(new WindowInteropHelper(window).Handle, Path.Combine(directory, name));
        Say($"captured {name} ({width}x{height} px)");
    }

    /// <summary>A dialog as the screen shows it (WindowCapture).</summary>
    private void SaveDialog(IntPtr dialog, string name)
    {
        if (CaptureDirectory() is not { } directory)
        {
            return;
        }

        var (width, height) = WindowCapture.Save(dialog, Path.Combine(directory, name));
        Say($"captured {name} ({width}x{height} px)");
    }

    private void After(int milliseconds, Action action)
    {
        var timer = new DispatcherTimer { Interval = TimeSpan.FromMilliseconds(milliseconds) };
        timer.Tick += (_, _) =>
        {
            timer.Stop();
            if (!done)
            {
                action();
            }
        };
        timer.Start();
    }

    private string? Label() => window.Views.Views.TryGetValue(2, out var label) ? label.Text?.Text : null;

    private async Task Label(string expected)
    {
        var waited = Stopwatch.StartNew();
        while (Label() != expected)
        {
            if (waited.Elapsed > LabelWait)
            {
                Fail($"waited {LabelWait.TotalSeconds:0} s for node 2 to read \"{expected}\"; it reads \"{Label() ?? "(no label)"}\"");
                throw new Abort();
            }

            await Task.Delay(50);
        }

        Say($"node 2 reads \"{expected}\"");
    }

    private void Check(bool condition, string failure)
    {
        if (!condition)
        {
            Fail(failure);
            throw new Abort();
        }
    }

    private void Fail(string why)
    {
        RestoreAppearance();
        if (done)
        {
            return;
        }

        done = true;
        Console.Out.WriteLine($"feature-test: FAILED: {why}");
        Console.Out.Flush();
        window.Process?.Kill();
        application.Shutdown(1);
    }

    private void Say(string line)
    {
        Console.Out.WriteLine($"feature-test [{clock.Elapsed.TotalSeconds:0.00}s]: {line}");
        Console.Out.Flush();
    }

    /// <summary>Stops the steps after a failure, which was already reported.</summary>
    private sealed class Abort : Exception
    {
    }

    private static class Win32
    {
        public const int IdYes = 6;
        public const int IdNo = 7;
        public const uint BmClick = 0x00F5;
        public const uint SettingChange = 0x001A;

        public delegate bool EnumWindowsProc(IntPtr handle, IntPtr parameter);

        [StructLayout(LayoutKind.Sequential)]
        public struct Rect
        {
            public int Left, Top, Right, Bottom;
        }

        [StructLayout(LayoutKind.Sequential)]
        public struct NotifyIconIdentifier
        {
            public uint Size;
            public IntPtr Window;
            public uint Id;
            public Guid Item;
        }

        [DllImport("user32.dll")]
        public static extern bool EnumThreadWindows(uint thread, EnumWindowsProc callback, IntPtr parameter);

        [DllImport("kernel32.dll")]
        public static extern uint GetCurrentThreadId();

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        public static extern int GetClassName(IntPtr handle, StringBuilder name, int capacity);

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        public static extern int GetWindowText(IntPtr handle, StringBuilder text, int capacity);

        [DllImport("user32.dll")]
        public static extern bool IsWindowVisible(IntPtr handle);

        [DllImport("user32.dll")]
        public static extern IntPtr GetWindow(IntPtr handle, uint command);

        [DllImport("user32.dll")]
        public static extern IntPtr GetDlgItem(IntPtr dialog, int id);

        [DllImport("user32.dll")]
        public static extern bool PostMessage(IntPtr handle, uint message, IntPtr wParam, IntPtr lParam);

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        public static extern IntPtr SendMessageTimeout(IntPtr handle, uint message, IntPtr wParam, string lParam, uint flags, uint timeout, out IntPtr result);

        [DllImport("shell32.dll")]
        public static extern int Shell_NotifyIconGetRect(ref NotifyIconIdentifier identifier, out Rect rect);
    }
}
