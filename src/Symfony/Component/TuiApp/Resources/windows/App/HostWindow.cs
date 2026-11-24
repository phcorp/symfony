using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.IO;
using System.Linq;
using System.Media;
using System.Text.Json.Nodes;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Documents;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Shell;
using System.Windows.Threading;
using TuiAppHost.Core;

namespace TuiAppHost;

/// <summary>One window running one application: the socket, the process, the node tree and the close flow.</summary>
public sealed class HostWindow : Window
{
    private static readonly TimeSpan ResizeThrottle = TimeSpan.FromMilliseconds(50);
    private static readonly RoutedCommand MenuCommand = new("TuiAppMenu", typeof(HostWindow));

    private readonly Manifest manifest;
    private readonly string manifestDirectory;
    private readonly HostOptions options;
    private readonly Log log;
    private readonly NodeTree tree = new();
    private readonly CloseFlow flow = new();
    private readonly DispatcherTimer closeTimer = new();
    private readonly DispatcherTimer resizeTimer = new() { Interval = ResizeThrottle };
    private readonly Dictionary<string, MenuItemDef> shortcuts = new();
    private readonly Menu menuBar = new();
    private readonly Border content = new() { ClipToBounds = true };
    private readonly DockPanel layout = new() { LastChildFill = true };
    private readonly string token = LaunchPlan.NewToken();
    private readonly CancellationTokenSource stopping = new();
    private CellMetrics cell;
    private Theme theme;
    private Reconciler reconciler;
    private AppConnection? connection;
    private AppProcess? process;
    private MenuMessage? appMenu;
    private (int Columns, int Rows) sentSize;
    private string? sentRects;
    private bool reallyClosing;
    private bool appFocusing;
    private int? focusedNode;
    private System.Windows.Forms.NotifyIcon? tray;
    private readonly List<string> pendingOpen = new();

    public HostWindow(Manifest manifest, string manifestDirectory, HostOptions options, Log log)
    {
        this.manifest = manifest;
        this.manifestDirectory = manifestDirectory;
        this.options = options;
        this.log = log;
        cell = CellMetrics.For(manifest.Window.FontFamily, manifest.Window.FontSize);
        theme = Theme.For(manifest.Window.Theme);
        reconciler = NewReconciler();

        Title = manifest.Window.Title;
        Width = manifest.Window.Width;
        Height = manifest.Window.Height;
        MinWidth = manifest.Window.MinWidth ?? 0;
        MinHeight = manifest.Window.MinHeight ?? 0;
        ResizeMode = manifest.Window.Resizable ? ResizeMode.CanResize : ResizeMode.CanMinimize;
        WindowStartupLocation = WindowStartupLocation.CenterScreen;
        UseLayoutRounding = true;
        TaskbarItemInfo = new TaskbarItemInfo();
        AllowDrop = true;
        SetIcon();

        DockPanel.SetDock(menuBar, Dock.Top);
        // Out of the Tab order, as a native menu bar is (Alt and F10 reach it): Tab past the last control wraps to
        // the first one in the content, as on macOS and Linux
        KeyboardNavigation.SetTabNavigation(menuBar, KeyboardNavigationMode.None);
        KeyboardNavigation.SetIsTabStop(menuBar, false);
        layout.Children.Add(menuBar);
        content.Padding = new Thickness(cell.Width, cell.Height / 2, cell.Width, cell.Height / 2);
        layout.Children.Add(content);
        Content = layout;
        BuildMenu();
        ApplyTheme();

        closeTimer.Tick += (_, _) => CloseTimedOut();
        resizeTimer.Tick += (_, _) => SendResize();
        content.SizeChanged += (_, _) =>
        {
            if (!resizeTimer.IsEnabled)
            {
                resizeTimer.Start();
            }
        };
        content.LayoutUpdated += (_, _) => Dispatcher.BeginInvoke(DispatcherPriority.Background, () => SendRects(force: false));
        CommandBindings.Add(new CommandBinding(MenuCommand, (_, e) => SelectMenu((string)e.Parameter)));
        KeyDown += UnhandledKey;
        AddHandler(Keyboard.GotKeyboardFocusEvent, new KeyboardFocusChangedEventHandler(FocusChanged), true);
        DragOver += (_, e) =>
        {
            e.Effects = e.Data.GetDataPresent(DataFormats.FileDrop) ? DragDropEffects.Copy : DragDropEffects.None;
            e.Handled = true;
        };
        Drop += (_, e) =>
        {
            if (e.Data.GetData(DataFormats.FileDrop) is string[] paths)
            {
                Open(paths);
            }

            e.Handled = true;
        };
        // Placed controls on the pixels of the new DPI (another monitor, another scale)
        DpiChanged += (_, e) => reconciler.Replace(tree, e.NewDpi);
        SourceInitialized += (_, _) =>
        {
            RestoreGeometry();
            Native.DarkTitleBar(this, theme.Dark);
            System.Windows.Interop.HwndSource.FromHwnd(new System.Windows.Interop.WindowInteropHelper(this).Handle)?.AddHook(HorizontalWheel);
        };
        Microsoft.Win32.SystemEvents.UserPreferenceChanged += PreferenceChanged;
        Closed += (_, _) => Microsoft.Win32.SystemEvents.UserPreferenceChanged -= PreferenceChanged;
        ContentRendered += async (_, _) => await StartAsync();
        LaunchPaths = HostOptions.OpenPaths(options.Paths, Environment.CurrentDirectory);
        Open(LaunchPaths);
    }

    /// <summary>
    /// WM_MOUSEHWHEEL, which WPF does not route as an event: to the canvas under the mouse, or the one holding it (a
    /// button pressed on it).
    /// </summary>
    private IntPtr HorizontalWheel(IntPtr hwnd, int message, IntPtr wParam, IntPtr lParam, ref bool handled)
    {
        const int WM_MOUSEHWHEEL = 0x020E;
        if (message != WM_MOUSEHWHEEL)
        {
            return IntPtr.Zero;
        }

        // A ContentElement (a run of a label) has no visual parent: its logical one
        for (var element = Mouse.Captured as DependencyObject ?? Mouse.DirectlyOver as DependencyObject; element is not null; element = element is Visual ? VisualTreeHelper.GetParent(element) ?? LogicalTreeHelper.GetParent(element) : LogicalTreeHelper.GetParent(element))
        {
            if (element is CanvasView canvas)
            {
                // The high word of wParam, signed: positive to the right
                canvas.HorizontalWheel((short)((wParam.ToInt64() >> 16) & 0xffff));
                handled = true;
                break;
            }

            // A control over the canvas, or any other, scrolls itself
            if (element is Control)
            {
                break;
            }
        }

        return IntPtr.Zero;
    }

    /// <summary>What this host does beyond the core protocol, in hello.</summary>
    public static readonly string[] Capabilities = { "menu", "notify", "progress", "beep", "appearance", "open", "canvas-controls", "password", "number", "slider", "listbox", "pointer", "contextmenu", "clipboard" };

    /// <summary>Hooks for --self-test.</summary>
    public event Action? Patched;

    public event Action<string>? Failed;

    public NodeTree Tree => tree;

    public Reconciler Views => reconciler;

    public CloseFlow Flow => flow;

    public bool HelloSent { get; private set; }

    public int RectsSent { get; private set; }

    public CellMetrics Cell => cell;

    /// <summary>Hooks for --feature-test.</summary>
    public AppProcess? Process => process;

    public Menu MenuBar => menuBar;

    public int Notifications { get; private set; }

    public int Beeps { get; private set; }

    /// <summary>The context menu the application last sent, while it is shown (or was: closed, it stays here).</summary>
    public ContextMenu? CanvasMenu { get; private set; }

    /// <summary>The texts the application put on the clipboard (the clipboard message), once they are there.</summary>
    public List<string> Copied { get; } = new();

    /// <summary>The clipboard messages so far: a try of an older one stops once a newer one came.</summary>
    private int clipboardGeneration;

    public System.Windows.Forms.NotifyIcon? Tray => tray;

    /// <summary>Raised before the force-quit question, which is modal: the handler answers it later.</summary>
    public event Action? ForceQuitOffered;

    /// <summary>The error dialogs shown, which a force quit must not show.</summary>
    public int Errors { get; private set; }

    /// <summary>The paths given at launch, once made absolute: what the first open holds.</summary>
    public IReadOnlyList<string> LaunchPaths { get; private set; } = Array.Empty<string>();

    /// <summary>Files or URLs for the application: sent now, or after hello.</summary>
    public void Open(IEnumerable<string> paths)
    {
        var list = paths.ToList();
        if (list.Count == 0)
        {
            return;
        }

        if (HelloSent)
        {
            Send(new OpenMessage(list));
        }
        else
        {
            pendingOpen.AddRange(list);
        }
    }

    private Reconciler NewReconciler() => new(
        cell, theme,
        (node, name, value, set) =>
        {
            // The control shows it, and the application takes it: an update of the node's other props keeps it
            if (set)
            {
                tree.Acknowledge(node, name, value);
            }

            Send(new EventMessage(node, name, set ? new Opt<JsonNode?>(true, value) : default));
        },
        (key, text) => Send(new KeyMessage(key, new Opt<string?>(true, text))),
        text => Send(new PasteMessage(text)),
        IsShortcut,
        SelectMenu,
        Send)
    {
        // The window's: a control placed before it is in the window would read a default one
        Dpi = () => VisualTreeHelper.GetDpi(this),
    };

    private async Task StartAsync()
    {
        if (connection is not null)
        {
            return;
        }

        try
        {
            connection = AppConnection.Listen();
            var inherited = Environment.GetEnvironmentVariables().Cast<System.Collections.DictionaryEntry>()
                .ToDictionary(e => (string)e.Key, e => (string?)e.Value ?? "", StringComparer.OrdinalIgnoreCase);
            LaunchPlan plan;
            if (options.DevCommand is { } dev)
            {
                plan = LaunchPlan.Development(dev[0], dev.Skip(1).ToList(), Environment.CurrentDirectory, inherited, connection.Address, token, File.Exists);
            }
            else
            {
                string? php = null;
                if (manifest.Launch.Mode == "system")
                {
                    php = LaunchPlan.FindPhp(manifest, manifestDirectory, inherited, File.Exists);
                    var version = php is null ? null : await Task.Run(() => AppProcess.PhpVersion(php));
                    if (php is null || version is null || !LaunchPlan.Satisfies(version, manifest.Launch.PhpMinVersion))
                    {
                        var needed = manifest.Launch.PhpMinVersion is { } min ? $"PHP {min} or later" : "PHP";
                        var found = php is null ? "No php.exe was found on PATH." : version is null ? $"{php} does not run." : $"{php} is PHP {version}.";
                        Fatal($"{manifest.App.Name} needs {needed}.\n\n{found}\n\nInstall PHP for Windows from https://windows.php.net/download/ and add its folder to PATH.");
                        return;
                    }
                }

                plan = LaunchPlan.For(manifest, manifestDirectory, php, inherited, connection.Address, token, File.Exists);
            }

            process = AppProcess.Start(plan, log);
            process.Exited += code => Dispatcher.BeginInvoke(() => AppExited(code));
            log.Write("info", $"listening on {connection.Address}");
            if (!await connection.AcceptAsync(token, AppConnection.AcceptTime, stopping.Token))
            {
                foreach (var refused in connection.Refused)
                {
                    log.Write("warning", "refused a connection: " + refused);
                }

                if (process.HasExited || flow.HasExited)
                {
                    // AppExited shows why.
                    return;
                }

                Fatal($"{manifest.App.Name} did not connect within {AppConnection.AcceptTime.TotalSeconds:0} seconds.\n\n{process.LastErrors}".TrimEnd());
                return;
            }

            flow.Connected();
            var scale = VisualTreeHelper.GetDpi(this).DpiScaleX;
            sentSize = Size();
            Send(new HelloMessage(1, "windows", sentSize.Columns, sentSize.Rows, Math.Round(cell.Width, 3), Math.Round(cell.Height, 3), scale, theme.Name,
                Capabilities));
            HelloSent = true;
            log.Write("info", $"hello: {sentSize.Columns}x{sentSize.Rows} cells of {cell.Width:0.###}x{cell.Height:0.###} in {cell.Family.Source}");
            if (pendingOpen.Count > 0)
            {
                Send(new OpenMessage(pendingOpen.ToList()));
                pendingOpen.Clear();
            }

            var reading = connection;
            _ = Task.Run(() => reading.ReadAsync(
                message => Dispatcher.BeginInvoke(() => Handle(message)),
                problem => log.Write("warning", "the connection: " + problem),
                stopping.Token));
        }
        catch (Exception exception) when (exception is LaunchException or System.ComponentModel.Win32Exception or IOException or InvalidOperationException or System.Net.Sockets.SocketException)
        {
            Fatal($"{manifest.App.Name} could not start.\n\n{exception.Message}");
        }
    }

    internal void Handle(Message message)
    {
        switch (message)
        {
            case PatchMessage patch:
                var result = tree.Apply(patch.Ops);
                foreach (var warning in result.Warnings)
                {
                    log.Write("warning", "patch: " + warning);
                }

                reconciler.Apply(tree, result);
                if (result.RootChanged)
                {
                    content.Child = reconciler.Root;
                }

                content.UpdateLayout();
                SendRects(force: true);
                Patched?.Invoke();
                break;
            case FocusMessage focus:
                FocusNode(focus.Node);
                break;
            case WindowMessage window:
                if (window.Title.IsSet)
                {
                    // null: the title of the manifest, before the application set one
                    Title = window.Title.Value ?? manifest.Window.Title;
                }

                if (window.Progress.IsSet)
                {
                    SetProgress(window.Progress.Value);
                }

                break;
            case NotifyMessage notify:
                Notify(notify.Title, notify.Body.Or(""));
                Notifications++;
                break;
            case BeepMessage:
                SystemSounds.Beep.Play();
                Beeps++;
                break;
            case MenuMessage menu:
                appMenu = menu;
                BuildMenu();
                break;
            case CloseReplyMessage reply:
                Step(flow.Replied(reply.Accept));
                break;
            case CursorMessage cursor:
                var shape = reconciler.SetCursor(cursor.Node, cursor.Shape);
                log.Write("debug", shape is null ? $"cursor {cursor.Shape} for node {cursor.Node}, not a canvas: ignored" : $"cursor {cursor.Shape} over canvas {cursor.Node}: {shape}");
                break;
            case ContextMenuMessage menu:
                ShowContextMenu(menu);
                break;
            case ClipboardMessage clipboard:
                _ = SetClipboard(clipboard.Text);
                break;
            case ByeMessage bye:
                log.Write("info", $"bye {(bye.Status.IsSet ? bye.Status.Value : 0)}");
                Step(flow.Bye());
                break;
            default:
                log.Write("debug", "ignored " + message.Type);
                break;
        }
    }

    /// <summary>
    /// contextmenu: a WPF ContextMenu over the canvas, its corner at the pointer when it is in that cell (as Windows
    /// opens a context menu: the release of the right button then falls on its border, not on an item), else at the
    /// cell's. Choosing an item sends contextmenu-select, or for the paste role the clipboard's text as paste (nothing
    /// when it holds none, or an empty one); closing it otherwise sends nothing. A new one replaces the one shown.
    /// </summary>
    private void ShowContextMenu(ContextMenuMessage message)
    {
        if (!reconciler.Views.TryGetValue(message.Node, out var view) || view.Canvas is not { } canvas)
        {
            log.Write("debug", $"contextmenu for node {message.Node}, not a canvas: ignored");
            return;
        }

        if (CanvasMenu is { IsOpen: true } shown)
        {
            shown.IsOpen = false;
        }

        var menu = new ContextMenu { PlacementTarget = canvas, Placement = System.Windows.Controls.Primitives.PlacementMode.Relative };
        foreach (var item in message.Items)
        {
            if (item.IsSeparator)
            {
                menu.Items.Add(new Separator());
                continue;
            }

            // A label as it is: an underscore is not an access key
            var entry = new MenuItem { Header = item.Label.Value.Replace("_", "__"), IsEnabled = item.IsEnabled, Tag = item.Id.Value };
            var chosen = item;
            entry.Click += (_, _) =>
            {
                if (chosen.IsPaste)
                {
                    // Nothing for no text, an empty one, or a clipboard that cannot be read
                    if (ClipboardText.ToPaste(busy => log.Write("warning", "the clipboard could not be read: " + busy)) is { } text)
                    {
                        Send(new PasteMessage(text));
                    }
                }
                else
                {
                    Send(new ContextMenuSelectMessage(chosen.Id.Value));
                }
            };
            menu.Items.Add(entry);
        }

        var corner = new Point(message.Column * cell.Width, message.Row * cell.Height);
        var pointer = Mouse.GetPosition(canvas);
        var inCell = PointerTracker.Cell(pointer.X, pointer.Y, cell.Width, cell.Height) == (message.Row, message.Column);
        var at = inCell ? pointer : corner;
        menu.HorizontalOffset = at.X;
        menu.VerticalOffset = at.Y;
        CanvasMenu = menu;
        menu.IsOpen = true;
        log.Write("debug", $"contextmenu over canvas {message.Node} at ({message.Row}, {message.Column}): {message.Items.Count} items");
    }

    /// <summary>The clipboard's text, empty included, null when it holds none or cannot be read.</summary>
    private string? Readable()
    {
        try
        {
            return Clipboard.ContainsText() ? Clipboard.GetText() : null;
        }
        catch (System.Runtime.InteropServices.COMException exception)
        {
            log.Write("warning", "the clipboard could not be read: " + exception.Message);
            return null;
        }
    }

    /// <summary>
    /// clipboard: the text on the clipboard, tried again for up to 5 s while another process has it open
    /// (CLIPBRD_E_CANT_OPEN; a VM's clipboard agent reads each change), the dispatcher running meanwhile: that agent may
    /// wait for this thread to render the text. A newer clipboard message ends the tries of an older one, which would
    /// otherwise put its text back over the newer's.
    /// </summary>
    private async Task SetClipboard(string text)
    {
        var generation = ++clipboardGeneration;
        var waited = System.Diagnostics.Stopwatch.StartNew();
        for (var attempt = 1; ; attempt++)
        {
            if (generation != clipboardGeneration)
            {
                log.Write("debug", $"clipboard: dropped after {attempt - 1} attempts, a newer one came");
                return;
            }

            try
            {
                Clipboard.SetText(text);
                Copied.Add(text);
                return;
            }
            catch (System.Runtime.InteropServices.COMException exception)
            {
                if (waited.ElapsedMilliseconds >= 5000)
                {
                    log.Write("warning", $"the clipboard stayed busy for {waited.ElapsedMilliseconds} ms ({attempt} attempts): " + exception.Message);
                    return;
                }

                await Task.Delay(200);
                // Its text may be there already: only the flush failed (and only if it is still the newest)
                if (generation == clipboardGeneration && Readable() == text)
                {
                    Copied.Add(text);
                    return;
                }
            }
        }
    }

    /// <summary>A message sent to the application (the tests watch it).</summary>
    public event Action<Message>? Sent;

    private void Send(Message message)
    {
        if (connection is { IsConnected: true } && HelloSent || message is HelloMessage)
        {
            connection!.Send(message);
            Sent?.Invoke(message);
        }
    }

    private (int Columns, int Rows) Size()
    {
        var width = Math.Max(0, content.ActualWidth - content.Padding.Left - content.Padding.Right);
        var height = Math.Max(0, content.ActualHeight - content.Padding.Top - content.Padding.Bottom);
        return (Cells.Fit(width, cell.Width), Cells.Fit(height, cell.Height));
    }

    private void SendResize()
    {
        resizeTimer.Stop();
        if (!HelloSent)
        {
            return;
        }

        var size = Size();
        if (size != sentSize)
        {
            sentSize = size;
            Send(new ResizeMessage(size.Columns, size.Rows));
        }
    }

    /// <summary>Every node's frame in cells, relative to the content area; [0, 0, 0, 0] when it is not on screen.</summary>
    private void SendRects(bool force)
    {
        if (!HelloSent || reconciler.Root is not FrameworkElement root || !root.IsLoaded && !force)
        {
            return;
        }

        var rects = new List<KeyValuePair<int, int[]>>();
        foreach (var node in tree.Walk())
        {
            var rect = new[] { 0, 0, 0, 0 };
            if (reconciler.Views.TryGetValue(node.Id, out var view) && view.Host.IsVisible && view.Host.IsDescendantOf(root))
            {
                var bounds = view.Host.TransformToAncestor(root).TransformBounds(new Rect(view.Host.RenderSize));
                rect = Cells.Rect(bounds.X, bounds.Y, bounds.Width, bounds.Height, cell.Width, cell.Height);
            }

            rects.Add(new KeyValuePair<int, int[]>(node.Id, rect));
        }

        var message = new RectsMessage(rects);
        var json = MessageCodec.Serialize(message);
        if (force || json != sentRects)
        {
            sentRects = json;
            Send(message);
            RectsSent++;
        }
    }

    private void FocusNode(int? id)
    {
        appFocusing = true;
        try
        {
            if (id is { } node && reconciler.Views.TryGetValue(node, out var view))
            {
                var target = view.FocusTarget;
                if (!target.Focus())
                {
                    // Created in this patch: focusable once laid out.
                    Dispatcher.BeginInvoke(DispatcherPriority.Loaded, () =>
                    {
                        appFocusing = true;
                        target.Focus();
                        focusedNode = node;
                        appFocusing = false;
                    });
                }

                focusedNode = node;
            }
            else
            {
                Keyboard.ClearFocus();
                FocusManager.SetFocusedElement(this, this);
                Focus();
                focusedNode = null;
            }
        }
        finally
        {
            appFocusing = false;
        }
    }

    private void FocusChanged(object sender, KeyboardFocusChangedEventArgs e)
    {
        var node = reconciler.NodeOf(e.NewFocus as DependencyObject);
        if (node is null || node == focusedNode)
        {
            return;
        }

        focusedNode = node;
        if (!appFocusing)
        {
            Send(new EventMessage(node.Value, "focus", default));
        }
    }

    /// <summary>Keys that no control used: menu shortcuts KeyGesture cannot hold, then the rest as key.</summary>
    private void UnhandledKey(object sender, KeyEventArgs e)
    {
        // A key an input method or a dead key took, in a canvas or a text box: its text comes as TextInput
        if (e.Key is Key.ImeProcessed or Key.DeadCharProcessed)
        {
            return;
        }

        var key = Input.ActualKey(e);
        var modifiers = (WpfModifiers)Keyboard.Modifiers;
        if (key == Key.Tab && (modifiers & ~WpfModifiers.Shift) == WpfModifiers.None)
        {
            return;
        }

        var id = Input.KeyId(key, modifiers);
        if (id is null)
        {
            return;
        }

        if (shortcuts.ContainsKey(id))
        {
            SelectMenu(id);
            e.Handled = true;
            return;
        }

        // Typed text went to the control that has the focus, or nowhere.
        if (KeyIds.TypesText((int)key, modifiers) || key == Key.System && modifiers == WpfModifiers.Alt)
        {
            return;
        }

        Send(new KeyMessage(id, new Opt<string?>(true, null)));
        e.Handled = true;
    }

    private bool IsShortcut(string id) => shortcuts.ContainsKey(id);

    private void SelectMenu(string shortcutOrId)
    {
        if (shortcuts.TryGetValue(shortcutOrId, out var item))
        {
            if (item.IsEnabled && item.Id.IsSet)
            {
                Send(new MenuSelectMessage(item.Id.Value));
            }
        }
    }

    private void BuildMenu()
    {
        menuBar.Items.Clear();
        shortcuts.Clear();
        foreach (var binding in InputBindings.OfType<KeyBinding>().Where(b => b.Command == MenuCommand).ToList())
        {
            InputBindings.Remove(binding);
        }

        menuBar.Visibility = manifest.Menu.Native ? Visibility.Visible : Visibility.Collapsed;
        var menus = new List<MenuItem>();
        foreach (var menu in appMenu?.Menus ?? Array.Empty<MenuDef>())
        {
            var top = new MenuItem { Header = "_" + menu.Title.Replace("_", "__") };
            foreach (var item in menu.Items)
            {
                if (item.IsSeparator)
                {
                    top.Items.Add(new Separator());
                    continue;
                }

                var entry = new MenuItem
                {
                    Header = item.Label.Or("").Replace("_", "__"),
                    IsEnabled = item.IsEnabled,
                    IsChecked = item.Checked.IsSet && item.Checked.Value == true,
                };
                var id = item.Id.Or("");
                entry.Click += (_, _) =>
                {
                    if (id.Length > 0)
                    {
                        Send(new MenuSelectMessage(id));
                    }
                };
                if (item.Shortcut is { IsSet: true, Value: { Length: > 0 } shortcut })
                {
                    entry.InputGestureText = KeyIds.Display(shortcut);
                    shortcuts[shortcut] = item;
                    if (KeyIds.ToWpf(shortcut) is { } gesture)
                    {
                        try
                        {
                            InputBindings.Add(new KeyBinding(MenuCommand, new KeyGesture((Key)gesture.Key, (ModifierKeys)gesture.Modifiers)) { CommandParameter = shortcut });
                        }
                        catch (NotSupportedException)
                        {
                            // A letter without modifiers is no KeyGesture: UnhandledKey matches it.
                        }
                    }
                }

                top.Items.Add(entry);
            }

            menus.Add(top);
        }

        if (manifest.Menu.Quit)
        {
            var file = menus.FirstOrDefault(m => ((string)m.Header).Trim('_').Equals("File", StringComparison.OrdinalIgnoreCase));
            if (file is null)
            {
                file = new MenuItem { Header = "_File" };
                menus.Insert(0, file);
            }
            else
            {
                file.Items.Add(new Separator());
            }

            var exit = new MenuItem { Header = "E_xit", InputGestureText = "Alt+F4" };
            exit.Click += (_, _) => Close();
            file.Items.Add(exit);
        }

        if (manifest.Menu.About)
        {
            var help = menus.FirstOrDefault(m => ((string)m.Header).Trim('_').Equals("Help", StringComparison.OrdinalIgnoreCase));
            if (help is null)
            {
                help = new MenuItem { Header = "_Help" };
                menus.Add(help);
            }

            var about = new MenuItem { Header = "_About " + manifest.App.Name.Replace("_", "__") };
            about.Click += (_, _) => About();
            help.Items.Add(about);
        }

        foreach (var menu in menus)
        {
            menuBar.Items.Add(menu);
        }
    }

    private void About()
    {
        var app = manifest.App;
        var lines = new[] { $"{app.Name} {app.Version}", app.Description, app.Copyright, app.Homepage }.Where(l => !string.IsNullOrEmpty(l));
        MessageBox.Show(this, string.Join("\n\n", lines), "About " + app.Name, MessageBoxButton.OK, MessageBoxImage.Information);
    }

    private void SetProgress(WindowProgress? progress)
    {
        var info = TaskbarItemInfo;
        if (progress is null)
        {
            info.ProgressState = TaskbarItemProgressState.None;
            return;
        }

        info.ProgressState = progress.State switch
        {
            "indeterminate" => TaskbarItemProgressState.Indeterminate,
            "paused" => TaskbarItemProgressState.Paused,
            "error" => TaskbarItemProgressState.Error,
            _ => TaskbarItemProgressState.Normal,
        };
        info.ProgressValue = Math.Clamp(progress.Value.Or(null) ?? 0, 0, 1);
    }

    /// <summary>
    /// notify: a balloon from a tray icon, which Windows 10 and 11 show as a toast, plus a flash of the
    /// taskbar button when the window is in the background. A real toast needs the Windows App SDK and
    /// an AppUserModelID registered by the installer, which is too heavy for this host.
    /// </summary>
    private void Notify(string title, string body)
    {
        if (tray is null)
        {
            tray = new System.Windows.Forms.NotifyIcon
            {
                Icon = File.Exists(Path.Combine(manifestDirectory, "app.ico"))
                    ? new System.Drawing.Icon(Path.Combine(manifestDirectory, "app.ico"))
                    : System.Drawing.Icon.ExtractAssociatedIcon(Environment.ProcessPath!) ?? System.Drawing.SystemIcons.Application,
                Text = manifest.App.Name.Length > 63 ? manifest.App.Name[..63] : manifest.App.Name,
            };
            tray.BalloonTipClicked += (_, _) => Dispatcher.BeginInvoke(() => Activate());
            tray.Click += (_, _) => Dispatcher.BeginInvoke(() => Activate());
        }

        tray.Visible = true;
        tray.ShowBalloonTip(5000, title, body.Length == 0 ? " " : body, System.Windows.Forms.ToolTipIcon.None);
        if (!IsActive)
        {
            Native.Flash(this);
        }
    }

    private void SetIcon()
    {
        try
        {
            var icon = Path.Combine(manifestDirectory, "app.ico");
            if (File.Exists(icon))
            {
                Icon = System.Windows.Media.Imaging.BitmapFrame.Create(new Uri(icon));
            }
        }
        catch (Exception exception) when (exception is IOException or NotSupportedException or UriFormatException)
        {
        }
    }

    private void PreferenceChanged(object sender, Microsoft.Win32.UserPreferenceChangedEventArgs e)
    {
        if (e.Category == Microsoft.Win32.UserPreferenceCategory.General && manifest.Window.Theme == "system")
        {
            Dispatcher.BeginInvoke(() =>
            {
                var next = Theme.For("system");
                if (next != theme)
                {
                    theme = next;
                    ApplyTheme();
                    reconciler.Retheme(theme, tree);
                    Send(new AppearanceMessage(theme.Name));
                }
            });
        }
    }

    private void ApplyTheme()
    {
        theme.Apply(Resources);
        Background = theme.Background;
        Foreground = theme.Text;
        TextElement.SetForeground(layout, theme.Text);
        menuBar.Background = theme.Background;
        menuBar.Foreground = theme.Text;
        Native.DarkTitleBar(this, theme.Dark);
    }

    protected override void OnClosing(CancelEventArgs e)
    {
        base.OnClosing(e);
        if (reallyClosing)
        {
            return;
        }

        var step = flow.CloseRequested();
        if (step == CloseStep.CloseNow)
        {
            reallyClosing = true;
            return;
        }

        e.Cancel = true;
        Step(step);
    }

    protected override void OnClosed(EventArgs e)
    {
        base.OnClosed(e);
        SaveGeometry();
        stopping.Cancel();
        closeTimer.Stop();
        process?.Kill();
        connection?.Dispose();
        if (tray is not null)
        {
            tray.Visible = false;
            tray.Dispose();
        }
    }

    private void Step(CloseStep step)
    {
        switch (step)
        {
            case CloseStep.AskApp:
                Send(new CloseMessage());
                Restart(CloseFlow.AnswerTime);
                break;
            case CloseStep.StopWaiting:
                closeTimer.Stop();
                break;
            case CloseStep.WaitForExit:
                Restart(CloseFlow.ExitTime);
                break;
            case CloseStep.CloseNow:
                closeTimer.Stop();
                reallyClosing = true;
                Close();
                break;
            case CloseStep.OfferForceQuit:
                OfferForceQuit();
                break;
            case CloseStep.ShowStartFailure:
                var errors = process?.LastErrors ?? "";
                Fatal($"{manifest.App.Name} stopped before it started.\n\n{(errors.Length > 0 ? errors : "It printed nothing.")}\n\nThe log is {log.Path}.");
                break;
        }
    }

    private void Restart(TimeSpan interval)
    {
        closeTimer.Stop();
        closeTimer.Interval = interval;
        closeTimer.Start();
    }

    private void CloseTimedOut()
    {
        closeTimer.Stop();
        Step(flow.TimedOut());
    }

    private void OfferForceQuit()
    {
        if (options.SelfTest)
        {
            Failed?.Invoke(flow.ByeReceived ? "the application sent bye but did not exit" : "the application did not answer close");
            return;
        }

        ForceQuitOffered?.Invoke();
        var choice = MessageBox.Show(this, $"{manifest.App.Name} is not responding. Force quit?", manifest.App.Name, MessageBoxButton.YesNo, MessageBoxImage.Warning);
        if (choice == MessageBoxResult.Yes)
        {
            process?.Kill();
            Step(flow.ForceQuit());
        }
        else
        {
            Step(flow.ForceQuitDeclined());
        }
    }

    private void AppExited(int code)
    {
        log.Write("info", $"exit {code}, bye {(flow.ByeReceived ? "received" : "not received")}");
        Step(flow.Exited());
    }

    /// <summary>A native error dialog, then the window closes.</summary>
    private void Fatal(string message)
    {
        log.Write("error", message.Replace("\n", " "));
        if (options.SelfTest)
        {
            Failed?.Invoke(message.Replace("\n", " "));
            return;
        }

        Errors++;
        MessageBox.Show(this, message, manifest.App.Name, MessageBoxButton.OK, MessageBoxImage.Error);
        process?.Kill();
        flow.ForceQuit();
        reallyClosing = true;
        Close();
    }

    private string? GeometryFile => manifest.Window.RememberGeometry
        ? WindowGeometry.FilePath(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), manifest.App.Id)
        : null;

    private void RestoreGeometry()
    {
        if (GeometryFile is not { } file || !File.Exists(file))
        {
            return;
        }

        var geometry = WindowGeometry.FromJson(File.ReadAllText(file));
        if (geometry is null || !geometry.IsVisibleOn(SystemParameters.VirtualScreenLeft, SystemParameters.VirtualScreenTop, SystemParameters.VirtualScreenWidth, SystemParameters.VirtualScreenHeight))
        {
            return;
        }

        WindowStartupLocation = WindowStartupLocation.Manual;
        Left = geometry.Left;
        Top = geometry.Top;
        Width = Math.Max(MinWidth, geometry.Width);
        Height = Math.Max(MinHeight, geometry.Height);
        if (geometry.Maximized)
        {
            WindowState = WindowState.Maximized;
        }
    }

    private void SaveGeometry()
    {
        if (GeometryFile is not { } file)
        {
            return;
        }

        try
        {
            var bounds = WindowState == WindowState.Normal ? new Rect(Left, Top, ActualWidth, ActualHeight) : RestoreBounds;
            Directory.CreateDirectory(Path.GetDirectoryName(file)!);
            File.WriteAllText(file, new WindowGeometry(bounds.Left, bounds.Top, bounds.Width, bounds.Height, WindowState == WindowState.Maximized).ToJson());
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            log.Write("warning", "could not save the window geometry: " + exception.Message);
        }
    }
}
