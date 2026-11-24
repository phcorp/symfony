# What a host does

Each host, on every OS, behaves the same way. The contract is in
`contract/protocol.json` (messages) and `contract/manifest.schema.json`
(launch settings). Read both first.

## Code layout

Each host is split in two:

- **Core**, with no UI code: frame codec, message types, node tree model,
  key ids, manifest parsing and launch plan, environment filtering, and the
  close-flow state machine. Unit tests run it against `contract/fixtures`:
  - `frames.json`: decode and encode byte for byte.
  - `messages.json`: every message decodes, and encodes back to equal JSON.
  - `session.json`: applying the patches gives the expected trees.
  - `manifest.json`: parses.
- **UI**: the window, the reconciler that turns the node tree into native
  controls, layout, menus, notifications, and the dock or taskbar.

## Start-up

1. Read `app.manifest.json` (see the schema for its location in each
   package). For development, `--manifest <file>` names one directly, and
   `--dev -- <command> [args…]` skips the manifest and runs the command,
   for example `php tools/fake-app.php`, from the current directory with the
   host's whole environment (`TUI_APP_*` included).
2. Listen on `127.0.0.1`, port 0. Make a token of 16 random bytes in
   lowercase hex.
3. Start the application with `TUI_APP_ADDR=tcp://127.0.0.1:<port>` and
   `TUI_APP_TOKEN=<token>`, replacing inherited ones. Drop every inherited
   variable except the allowlist in the manifest schema and
   `launch.env.pass` (in `--dev`, none), then add `launch.env.set`; the
   other `TUI_APP_*` variables are not special.
   - Its stdout and stderr go to the log (`logging.file`, else the
     platform log).
   - `system` mode: find `php` (`launch.php.path`, else PATH) and check
     `php -r 'echo PHP_VERSION;'` against `minVersion`. If no suitable php
     is found, show a native error dialog that says what is needed.
4. Accept one connection within 10 s. Its first frame must be `auth` with
   the token (constant-time compare) and protocol 1; otherwise close that
   connection and keep waiting. Then answer `hello`.
   - `columns` and `rows` are the window's content size divided by the
     cell size of the canvas font. Fall back to the platform's monospaced
     font when `canvasFont.family` is null; its size is
     `canvasFont.size` points.
5. If the process exits before `auth`, show its last stderr lines in an
   error dialog.

## The node tree

- Apply each `patch` in order. Build or update the native controls at the
  end of the patch, then lay out once.
- After every layout, send `rects`: after a patch is drawn, when the
  window is resized, and when a control changes size on its own (a
  disclosure opens, a text area grows). Skip it when no rect changed. Each
  node's frame is converted to cells:
  `[floor(y / cell.height), floor(x / cell.width), ceil(w / cell.width), ceil(h / cell.height)]`
  relative to the content area.
- What the user changes on a control is its prop from then on, as the
  application takes it without sending it back (TuiApp's
  `HostEvent::acknowledge`): `change`/`submit` the `value` of a text field,
  a password, a text area or a number field, `change` the `value` of a
  slider, `toggle` a checkbox's `checked` and a disclosure's `expanded`,
  `select` the `selected` of a pop-up, a list box or a list (`checked` for
  a multiple list), `tab` the `active` tab. An update of the node's other props (a
  `maxLength`, `disabled`, a `place` after a scroll) keeps it; an update of
  that prop sets it.
- Unknown kinds draw as an empty box. Unknown props are ignored.

| kind | Behaviour |
|---|---|
| `stack` | Vertical or horizontal box. `gap` and `padding` are in cells, converted with the cell size. Children with `grow` > 0 share the free space by weight; the others take their natural size. The root fills the window. |
| `label` | Selectable read-only text, wrapping to the width. `runs` are drawn as attributed text. |
| `textfield` | Single-line entry. `prompt` is shown before it as a label. `change` on every edit, `submit` on Enter (value = text), `cancel` on Escape. `maxLength`: see below. |
| `textarea` | Multi-line, scrolls. `change` on edit. `submit` on Ctrl+Enter or Cmd+Enter. Height is between `minLines` and `maxLines` lines. `maxLength`: see below. |
| `list` | One row per item: label, plus description in secondary text. `select` with the index on double-click or Enter. For `multiple`, a checkbox per row, and `select` sends the list of checked indexes when one changes. Moving the selection with the arrow keys sends no event; it shows `selected` from the props. |
| `form` | Two columns: label, then a popup/dropdown when `choices` is set, else a text field, which the user cannot edit for a `readOnly` row. `setting` with `{id, value}` when a value is committed. |
| `tabs` | A tab view; tab *i* shows child *i*. `tab` with the index when the user switches. |
| `disclosure` | Expander with `summary`, `description` as secondary text, and its children as content. `toggle` with the new state. |
| `progress` | Determinate when `progress` is a number from 0 to 1, indeterminate when it is null. `text` is shown with it. |
| `spinner` | Spinning indicator while `running`, with `text`. A Cancel button when `cancellable`, which sends `cancel`. |
| `canvas` | A custom view that draws `lines` (rows of runs) in the canvas font on a cell grid: `fg`/`bg` colours, bold, dim (alpha 0.6), italic, underline, strikethrough, reverse. Draw the `cursor` cell. It takes focus when `focusable`; every key press then goes out as `key`, and it takes input methods (see Input). Its children are native controls placed over it (see below). |
| `button` | Push button showing `text`. `press` when clicked, or Space/Enter while focused. |
| `checkbox` | Check box with `text` as its label, checked when `checked`. `toggle` with the new state when the user flips it. With `group`, a radio button: clicking it when unchecked sends `toggle` `true`; the application unchecks the others of the group. |
| `popup` | Pop-up button (drop-down) of `choices`, showing `selected`. `select` with the index the user picks. |
| `password` | A `textfield` in a secure field: bullets, nothing of it on the pasteboard, no prompt. `change`, `submit`, `cancel`, `maxLength` as a text field. Its value is never logged. macOS: NSSecureTextField. Linux: GtkPasswordEntry. Windows: PasswordBox, which tells nothing of its selection: an edit is found by comparing the value with the one before, and cut to `maxLength`. |
| `number` | A field with a stepper: `value` (the text), `min`, `max`, `step` (1). Up and Down in it, and the stepper, go to the next step, within min and max: a value off the steps to the nearest one that way, an empty one from 0. What is typed is sent as `change` as it is, a number or not, past min or max or off the steps, and kept, also when it is committed (Enter, the focus leaving): the application says it is invalid. `submit` (Enter), `cancel` (Escape). macOS: NSTextField and NSStepper, stepped like HTML. Linux: GtkSpinButton, snapped to the steps from min, from its last number when its text is not one: past min or max, as HTML's stepUp and stepDown, count steps on (Page Up and Page Down 10), then min, or the highest step at or below max; a step that would go the other way (Up above max, Down below min) leaves the number, nothing sent; with max off the steps, Up stops at the highest step at or below it, never max. Every step is the host's, not GTK's (which climbs off the steps when a key is held); a stepper arrow held repeats after 500 ms, every 50 ms. Placed over a canvas, its text is one character wide at least, so that the field and both arrows fit its cells. Windows: a TextBox and two RepeatButtons, stepped like macOS. |
| `slider` | A horizontal slider: `value` (a number) between `min` (0) and `max` (100), on a step from min (`step`, 1). `change` with the value, on a step, as it moves. The arrows move it by a step, Page Up and Page Down by a tenth of the range, Home and End to the bounds. macOS: NSSlider, tick marks for up to 21 steps. Linux: GtkScale. Windows: Slider, snapping to the steps when dragged. |
| `listbox` | A list of `choices`, `selected` the indexes of the selected ones; several with `multiple` (Cmd/Ctrl-click, Shift-click). `select` with the list of selected indexes when the user changes them. macOS: NSTableView. Linux: GtkListBox. Windows: ListBox, Extended selection when `multiple`. |

### `maxLength` (`textfield`, `textarea`)

The most characters the value may have, counted in Unicode code points like
PHP's `mb_strlen` (not UTF-16 units, not graphemes): `😀` is one, `e` with a
combining accent two. Absent, null or negative: no limit; an update that
removes it lifts it.

- An edit by the user, typed, pasted, dropped or committed by an input
  method, inserts only what fits: a paste is cut to the room left, a key at
  the limit is refused (with the platform's beep). The text it replaces makes
  room.
- A value already past the limit (the application set it, or lowered the
  limit) is shown as it is and may be edited as long as it gets no longer.
- The host never cuts a value the application sends. The application cuts a
  longer value it gets and sends back the value it keeps.
- What an input method composes (preedit, marked text) is not the value
  yet: it is let in whole, not counted, and not sent as `change`; its commit
  is counted, and cut to what fits. A commit the input method makes as the
  focus leaves is the user's edit too, also when the application moved the
  focus or hid or disabled the field: counted, and sent as `change`.
- macOS: the field editor's and the text view's
  `textView(_:shouldChangeTextIn:replacementString:)`. Linux: `insert-text` on
  the entry's GtkText and the view's GtkTextBuffer (not
  `gtk_entry_set_max_length`, which cuts values the application sets and
  cannot say 0). Windows: `TextBox.MaxLength` counts UTF-16 units, so a host
  checks the code points itself; an input method composes in the TextBox, and
  its commit is there already when `PreviewTextInput` comes: it is cut once the
  composition is over.

### Controls over a canvas (`canvas-controls`)

A host that does this lists `canvas-controls` in `hello.capabilities`; the
application sends children to a canvas only then. A host lists `password`,
`number`, `slider` and `listbox` too when it draws those kinds; without them,
the application sends a number field as a `textfield`, and keeps the others
drawn in the canvas.

- Each child of a canvas is the native control of its kind, drawn above the
  canvas surface at `place`, `[row, column, columns, rows]` in cells from
  the canvas's top-left corner: `x = column * cell.width`,
  `y = row * cell.height`, `columns * cell.width` wide, `rows * cell.height`
  high. The control fills that box; it does not change the canvas's size.
- `clip`, `[row, column, columns, rows]` in canvas cells too, is the part
  shown: draw the control at `place`, cut to `clip`. `hidden`: not shown.
- The canvas draws no characters in the cells of a control shown over it
  (its `clip`, else its `place`): a glyph like `─` or `→` reaches past its
  cell, into the one before the control, where the control's box does not
  hide it.
- Placed `textfield` and `textarea` draw their text in the canvas font
  without a prompt. `disabled` greys a control out.
- The focus chain (Tab order, key-view loop) goes from the canvas through its
  children in the order of the nodes, wherever they are placed, then on to
  what follows the canvas (Shift+Tab: the other way). A control that is
  hidden or clipped to nothing is skipped.
- `keys` on the canvas (`tab`, `shift+tab`): while the canvas or one of its
  controls has the focus, those keys are sent as `key` and not used. The
  application then moves the focus with `focus`. While an input method
  composes in the canvas or in the focused control, they are the input
  method's.
- Clicking a control focuses it and sends `event` `focus`, like any control.

Common props:
- `style.fg`/`style.bg`: text and background colour. The others: font traits.
- `hidden`: not shown and takes no space.
- `disabled`: greyed out, takes no input.
- `focusable: false`: skipped by Tab.
- `border`: a rounded 1 px box.
- `label`: the accessible name.

## Input

- Every user action listed above is sent as `event`. When a native control
  takes the focus, send `event` `focus` for its node.
- `focus` from the application focuses that node's control (`null`: the
  window).
- Keys the focused control does not use, menu shortcuts not bound to a
  native item, and every key in a canvas are sent as `key`, using Tui key
  ids. Modifiers come in the order `ctrl+shift+alt+super+`; macOS Cmd is
  `super`. Then the key: `enter`, `escape`, `tab`, `space`, `backspace`,
  `delete`, `insert`, `home`, `end`, `page_up`, `page_down`, `up`, `down`,
  `left`, `right`, `f1`–`f12`, or the lowercase character.
  - `text` is the typed text without modifiers, or null.
  - Tab and Shift+Tab move the native focus and are not sent, unless a
    canvas asks for them with `keys`.
- Paste into a canvas sends `paste`.
- A canvas takes input methods: dead keys, the input methods of Chinese,
  Japanese or Korean, the emoji and symbol picker.
  - What is still being composed (the preedit, marked text) is drawn by the
    host only, underlined, from the `cursor` cell (or the first cell when
    there is none), over the lines. The candidate window opens next to that
    cell. It is not sent. Windows: WPF tells an input method where the
    composition is only for a TextBox (for another element, an empty
    rectangle, and the window opens in the screen's top left corner), so the
    canvas's focus is an invisible TextBox over the cell, which holds only
    what is being composed; its keys and commits are the canvas's.
  - The text the input method commits is sent like typed text: one `key`
    per character (grapheme), `key` the character, lowercased with `shift+`
    for an uppercase letter (also one with combining marks, `E` and an
    acute), `space` for a space, and `text` the character.
  - Keys the input method does not use go out as `key` as usual.
- When the content area changes size, send `resize` with the new columns
  and rows, at most once per 50 ms while resizing.

### The mouse on a canvas (`pointer`)

A host that does this lists `pointer` in `hello.capabilities`. What the mouse
or the trackpad does on the surface of a canvas is sent as `pointer`, `node`
the canvas; never what it does on a control placed over the canvas, which
handles it itself, nor on other controls.

- `row` and `column`: the cell under the pointer, from the canvas's top-left
  corner, the position divided by `cell.width` and `cell.height`, rounded down
  (also when negative: a drag goes on past the canvas's edges).
- `press` and `release`: a button, `button` `left`, `middle` or `right`. A
  press focuses the canvas as before; its `event` `focus` goes first. While a
  button is held, its moves and its release are the canvas's (the platform's
  implicit grab), wherever the pointer is.
- `move`: the pointer went to another cell (not every pixel), `button` the one
  held or null. Only while the window is the key one, or a button is held.
- `leave`: the pointer left the canvas with no button held, at the cell it was
  last sent at. A host that cannot tell sends nothing.
- `wheel`: `rows` (down, negative up) and `columns` (right, negative left). A
  notch of a mouse wheel is one row; a trackpad's (or any precise) deltas in
  pixels are added up per cell height and width, and sent once they make whole
  cells, the rest kept for the next delta and dropped when a new gesture
  starts. The platform's direction setting (natural scrolling) applies: rows
  are positive when the content should move up, showing what is below.
- `button` and `modifiers` are always sent (`button` null for `leave`,
  `wheel` and a move with no button), `modifiers` the ids `ctrl`, `shift`,
  `alt`, `super` held, in that order; `rows` and `columns` with `wheel` only.

## Window, menus and system

- `window`:
  - `title` sets the window title; `null` gives back the title of the manifest, and a
    `window` without `title` leaves it as it is.
  - `progress` drives the dock tile, taskbar progress or Unity launcher
    progress; null clears it.
- `menu`: the application menus.
  - macOS: the main menu bar, with the app menu (About when
    `menu.about`, Quit when `menu.quit`).
  - Linux and Windows: a menu bar in the window.
  - Items with a `shortcut` show it and send `menu-select`; their key is
    not also sent as `key`.
- `contextmenu`, Linux: a GtkPopoverMenu from a GMenu, pointing to the cell of
  the canvas; a separator starts a section, `enabled` false disables the
  item's action. Choosing an item sends `contextmenu-select`; one with role
  `paste` reads the clipboard's text (gdk_clipboard_read_text_async) and sends
  `paste`, nothing when it is empty. Dismissing it sends nothing; a new menu
  replaces the one shown.
- `contextmenu`, Windows: a WPF ContextMenu placed on the canvas's surface, its
  corner at the pointer when it is in that cell (the right button's release then
  falls on its border), else at the cell's; a `Separator` for a separator,
  `IsEnabled` false for `enabled` false, labels as given (an underscore is not an
  access key). Choosing an item sends `contextmenu-select`; one with role `paste`
  sends the clipboard's text as `paste`, nothing when it holds none. Dismissing
  it sends nothing; a new menu replaces the one shown. The canvas's TextBox has
  no editing menu of its own (the menu key would open Cut, Copy, Paste).
- `clipboard`, Linux: the text on the display's clipboard (gdk_clipboard_set_text).
- `clipboard`, Windows: Clipboard.SetText, tried again for up to 5 s while another
  process has the clipboard open (CLIPBRD_E_CANT_OPEN).
- `notify`: a system notification.
  - macOS: UNUserNotificationCenter, shown even while the host is active. An
    unbundled host, or a bundle macOS refuses (ad hoc signed), uses
    `osascript`'s `display notification`.
  - Linux: GNotification (the desktop's notification server).
- `beep`: the system beep.
- Appearance:
  - `window.theme` `system` follows the OS and sends `appearance` when it
    changes. Linux: the settings portal's `color-scheme`, else the GTK theme
    name (XSETTINGS).
  - `light`/`dark` force it.
  - `hello.appearance` gives the initial value.
- `open`:
  - macOS: application:openURLs and Apple events.
  - Linux and Windows: command-line arguments; on Linux, all of them after
    `--`, which the desktop entry puts before `%U`.
  - Files dropped on the window.
  - With `singleInstance`, a second launch hands its paths to the first
    instance and exits.
- `rememberGeometry`:
  - macOS: frame autosave.
  - Others: a JSON file in the platform config directory.

## Closing

1. The window close button and Quit send `close`.
2. `close-reply` `accept: true`: wait for `bye` and the process to exit,
   then close.
3. `accept: false`: keep the window open.
4. No reply within 4 s, or no exit within 15 s after `bye`: ask
   "\<name\> is not responding. Force quit?" and kill it on yes.
5. If the process exits by itself, close the window, and quit the host when
   no window is left.

## Seeing the frames

`tools/frame-proxy.php` sits between a host and an application and logs every
frame, both ways, to `$TUI_PROXY_LOG` (stderr by default):

    TUI_PROXY_LOG=/tmp/frames.log <host> --self-test --dev -- php tools/frame-proxy.php php app.php

## Self-test (`--self-test`)

Used by the smoke tests on every OS: `<host> --self-test --dev -- php tools/fake-app.php`.

1. Start like a normal launch.
2. Once the first patch is drawn, find the textfield node 3, type "Ada" into
   the native control as a user would (set its value through the toolkit,
   so its change handler runs), and trigger its submit action.
3. Wait up to 5 s for the label node 2's native text to read "Hello Ada!".
4. Check that a native control exists for every node in the tree.
5. Send `close`.
6. Exit 0 once the app sent `bye` and exited; print why and exit 1 on any
   failure or after 20 s.

## Feature test (`--feature-test`)

The rest of the protocol, end to end: `<host> --feature-test [<path>…] --dev --
php tools/fake-app.php` with `FAKE_APP_FEATURES=1`, or `php tools/feature-app.php`,
which plays the same part through the TuiApp component. The application shows what
it got in label node 2, and the host waits up to 5 s for each text:

1. It sends `notify`, `window` with progress normal 0.5, and `beep`, then node 2
   reads "ready", or "open: <paths>" when the host was given paths at launch. The
   host checks each one reached the system: the notification was handed over, the
   dock tile, taskbar or launcher shows the progress, the beep was played.
2. Menus: the shortcut of File > Save (ctrl+s) goes through the native menu, then
   File > Wrap lines is chosen as a click does: "menu: file.save", "menu: file.wrap".
3. With `window.theme` `system`, the system appearance flips and comes back:
   "appearance: dark", "appearance: light", in the order of the flip.
   Windows, while dark: a form with a choice row, a spinner with `cancellable`
   and a popup, each with `style.fg`, added to the root as if the application
   sent them; the text of the form's label, of the choice each pop-up shows, of
   the Cancel button and of an item of each opened drop-down is drawn in that
   colour, not the dark theme's; then they are removed.
4. Files and URLs open while it runs, the way the system hands them over: "open: <path>".
   macOS: Launch Services sends the bundle a file, then a URL (skipped unbundled).
   Linux: with `singleInstance` and a manifest, a second launch gets
   `-- -feature-test.fake <url>` and hands both over (skipped otherwise).
5. The close button: the application refuses, "close: kept open", and the window stays.
6. The close button: no answer. After 4 s the force-quit question comes up; the test
   answers Wait, and the window stays.
7. The close button: the application hangs, ignoring SIGTERM. The question again; the
   test answers Force Quit. The process is killed, no error is shown, the window
   closes, and the host exits 0.

`TUI_APP_FEATURE_CAPTURE=<dir>` writes PNGs of what the host drew: the window,
the progress and the force-quit question. A window is taken as the screen shows
it where the platform lets a process read it (macOS: `screencapture -l`, as
drawing a view to a bitmap leaves out the selected tab of an NSTabView, a white
block there; Windows: `PrintWindow` with `PW_RENDERFULLCONTENT`, the desktop
window manager's copy, not `RenderTargetBitmap`), else drawn to a bitmap. `macos/features.sh`,
`linux/features.sh` and `windows/vm/features.sh` (in a Windows VM) run it,
and check what only a second process can see: that no process is left, and
on Linux what reached the session bus (`linux/tests/fake-desktop.py` stands
in for the notification server and the settings portal).

On Windows, the shell is asked where the notification icon is
(`Shell_NotifyIconGetRect`); the taskbar progress is checked on
`TaskbarItemInfo`, as the shell cannot be asked for it back; the appearance
flips through `AppsUseLightTheme` and a `WM_SETTINGCHANGE` "ImmersiveColorSet"
broadcast, as Settings does, and is put back; the force-quit question is a
Yes/No message box (No is Wait); there is no SIGTERM to ignore, and the hung
application is killed with `TerminateProcess`.

## Canvas test (`--canvas-test`)

Controls over a canvas, Tab handed to the application, input methods and
rects after a resize, end to end: `<host> --canvas-test --dev -- php
tools/fake-app.php` with `FAKE_APP_CANVAS=1`. The application sends the
fixtures, `patch-canvas-controls` (controls 30 to 35 over canvas 21, `keys`
tab and shift+tab, `maxLength` 40 on text field 30), then clips textarea 35 to
its first row, gives it `maxLength` 8 and makes canvas 21 focusable, and shows what it gets in label node 2.
Once 35 changes to "Notesabc", it lowers the `maxLength` of 30 to 30. The host waits up to 5 s for each text:

1. `hello` listed `canvas-controls`; node 2 reads "canvas ready". Every child of
   node 21 has a native control above the canvas surface, its frame at `place`
   from the canvas's corner (within a pixel), and node 35 shows its first row
   only. The host moves button 34 above the others and clips
   popup 33 to nothing, as a patch would; Tab from the canvas goes through 30,
   31, 32, 34 to 39, then out of the canvas (Windows: around to the first node,
   not to the menu bar); the host puts them back. Linux: then
   with `focusable` false on 36 and 37, Tab skips them. Windows: then checkbox
   32 placed on no columns is skipped too. macOS: once 36 to 39 are there (step
   3), every view of 30 to 39, down to a stepper's arrows and a check box's label
   (not the content of a scroll view), fits the cells of its `place` within half
   a point; a stepper too big for its row is scaled down whole.
   Windows: every element drawn for 30 to 39, down to a number's arrows and a
   list box's rows (what a scroll viewer or a clip cuts off not counted), fits
   the cells of its `place` within half a DIP; a placed control's edges are on
   the pixels nearest its cells', and a placed list box has no border.
2. Through the toolkit, as a user would: "Ada" typed into 30, "event 30 change
   \"Ada\""; 31 clicked, "event 31 toggle false"; 32 clicked, "event 32 toggle
   true"; 33 set to its third choice, "event 33 select 2"; 34 clicked, "event
   34 press".
3. `maxLength`, through the toolkit: 40 digits pasted at the end of 30
   ("Ada"), "event 30 change \"Ada<the first 37 digits>\""; a typed z then
   changes nothing. a, b, c, d typed at the end of 35 ("Notes"): "event 35
   change \"Notesabc\"", and nothing after it.
   The application then lowers the `maxLength` of 30 to 30, its 40
   characters past it, which it keeps: XYZ pasted over its last 5,
   "event 30 change \"Ada<32 digits>XYZ\""; LMNOP pasted over its last 2,
   "…XLM", the value no longer; a z typed at the end, nothing.
   macOS, with the NSTextInputClient calls of an input method: once its last
   9 characters are deleted (29 left), にほん marked at its end is shown whole
   and nothing is sent, nor Tab, which is the input method's; 日本 committed: "event 30 change \"Ada<26 digits>日\"".
   Windows, as WPF's text store does it: the composition put in the TextBox after
   `PreviewTextInputStart`, then the commit in its place and `PreviewTextInput`.
   With `password`, `number`, `slider` and `listbox` in `hello`, the application
   also sends `patch-native-inputs` (36 to 39), and the host goes through them
   (Linux: first, every placed control, 30 to 39, its widget's bounds within its
   cells; one cell high, a text field, password or number field has no top and
   bottom frame lines, and a placed text area's height is its cells, not
   minLines; one row high, it has neither a scrollbar nor top and bottom frame
   lines, and scrolls as its cursor moves),
   printing no password: "s3cret" typed into 36, "event 36 change (6
   characters)" (the application shows its length only); 10 digits pasted, "(8
   characters)", its `maxLength`. In 37 (1, 0 to 10 by 0.5): Up, "event 37
   change \"1.5\""; Down twice, "0.5"; the stepper's up arrow, "1" (macOS: 37
   moved onto row 0 of the canvas, as under its rows it is not in the window, and
   back after; its arrows drawn over its whole frame; the up arrow, the down
   arrow, the up arrow clicked through `NSWindow.sendEvent` at their centres,
   which hit-test to the stepper: "1", "0.5", "1"); x typed at
   its end, "1x", kept (Linux: then Enter, "event 37 submit \"1x\""; 12 typed over it, past max, then
   Enter, "event 37 submit \"12\""; Up, nothing; Down, "10", max; 12 typed, Page Down, "7"; -3 typed, below min, Down, nothing; Up, "0", min; -3 typed, Page Up, "2"; 2.3 typed, off the steps, "event 37 change \"2.3\"", kept when the focus leaves; Up,
   "2.5"; 2.7 typed, then a click on the stepper's down arrow, "2.5"; a step sends that one change, no "" before it; with step 3, 9 left by Up and Page Up, 6 to 9 by Up and by Page Up, never 10; Up held 1 s (xdotool keydown) repeats; both arrows inside the field's place, a click on the up one from 60, "63"; the down one held 1 s repeats; with max 100 and 99, all on the steps). 38 (50, by 5):
   Right, "event 38 change 55"; moved to 73 as a drag does, "75". 39 (multiple,
   row 0 selected): rows 1 and 2 selected as Ctrl-clicks do, "event 39 select
   [1,2]"; Down, "[3]".
   Linux, then: x typed at the end of the row "name" of form 12 ("Ada"), nothing
   sent; the application moves the focus (`focus` 34): "event 12 setting
   {\"id\":\"name\",\"value\":\"Adax\"}". Canvas 21 drawn with ─ along row 2 has
   ink before text field 30 and none in its cells. Text field 3 moved into the
   canvas by a patch of the move alone hides its prompt, and shows it moved back.
   Nodes moved out of a stack, tabs and a canvas that the same patch removes stay,
   and are updated, moved and removed; `linux/smoke.sh` runs the test again built
   with AddressSanitizer, which aborts on a read of the removed container.
4. Tab pressed in the text field 30, as the window gets it: "key tab", and the
   native focus did not move by itself; the application answers `focus` 34,
   and the button has the focus. Linux: first, Tab while GTK's input method
   composes in 30 (Ctrl+Shift+U) sends nothing and the focus stays; Escape
   drops the composition.
5. In the canvas, the way the platform's input method talks to it (macOS: the
   NSTextInputClient calls; Linux: GTK's input method, Ctrl+Shift+U, as Xvfb
   has no dead keys; Windows: the composition events, on the TextBox that takes
   the canvas's focus over the cursor cell, see Input): text marked (the host
   shows it, nothing is sent), then "é" committed, then "日本": "typed é日本".
   A plain key typed after it goes out once. Linux: Tab while the canvas
   composes é sends nothing, and the focus stays; Super+A (xdotool, through
   Xvfb, no window manager to take Super) with the canvas focused goes out once
   as "key super+a", no text (GDK gives it as GDK_SUPER_MASK with Mod4). Then a
   `contextmenu` at cell (1, 2) of canvas 21: shown there, its labels in two
   sections, the disabled item greyed out; Copy Link Address, activated as its
   button does, sends "contextmenu-select copy", the disabled item nothing;
   `clipboard` puts its text on the clipboard, as xclip reads it; Paste sends
   `paste` with what xclip, another X client, put on the clipboard, and nothing
   once `clipboard` emptied it; dismissing sends nothing; a second menu
   replaces the first.
6. With `pointer` in `hello`, the mouse on canvas 21, handed to the view under it
   the way the window hands events over (macOS: the view `hitTest` finds, as
   `NSWindow.sendEvent` takes the first click of a window that is not key; Linux:
   the canvas's gesture controllers; Windows: the cursor moved and the buttons and
   the wheel sent as input, which WPF hit-tests, as its routed events carry the
   cursor's real position): a left
   click on cell (0, 1), "pointer release 0 1 left", the press then the release
   sent, and the canvas focused; a press at (0, 1), drags to (1, 3) twice and the
   release there, "pointer release 1 3 left", one move sent for the cell; 3 lines
   of a mouse wheel down where the mouse is, "pointer wheel <row> <column> 3 0"
   (Linux: X11 delivers one event per notch, so three `wheel` messages of one
   row each are sent, adding up to 3; Windows: then a notch of a horizontal
   wheel right, "pointer wheel <row> <column> 0 1");
   a move with no button to (2, 4), "pointer move 2 4"; a click at the middle of
   text field 30 focuses it, and no `pointer` is sent (the controls under the
   last row of canvas 21 are not over it, as it draws 3 rows).
   Windows, then: a press at (2, 4) dragged onto text field 30 and released there,
   its moves and release sent, then "leave" at the release's cell; over 30 with
   no button, moves and the wheel send nothing; a press at (2, 4) dragged 40
   pixels off the window (SetCursorPos, the canvas keeping the mouse) and
   released there, its move and release sent at the cell past the canvas's
   edges, then "leave" there.
   macOS, before that click: `cursor` messages handed to the host as if received,
   the mouse at (2, 4): `pointer`, the pointing hand set (`NSCursor.current`);
   for label 2 and node 999, nothing changes; `none`, a cursor with nothing drawn;
   `col-resize`, the column resize cursor (macOS 15); `help`, the arrow; `text`,
   the I-beam; a move over text field 30, the surface lets go of it, the arrow
   back; back to (2, 4), the I-beam; the mouse leaving, the arrow; `grab` while
   out, kept; the mouse back in, the open hand with no new message.
   macOS, then, with the moves of text field 30 handed to the surface, as its
   tracking area gets them: a move to (2, 5), then onto 30 and along it, "leave
   2 5" once and no move; the wheel over 30, nothing; a press at (2, 4) dragged
   onto 30 and released there, its move and release sent, then "leave" (none
   mid-drag); back to (2, 3), "pointer move 2 3". A Control-click at (1, 2),
   "pointer release 1 2 right": the press and release of the right button, no
   ctrl (the left button held after Control-click is the right one until released).
   macOS, then, the user's pasteboard saved first and put back after (also on a
   failure): `contextmenu` at (1, 2) as if received, popped up as an NSMenu at
   that cell; a second one and one for label 2 while it is shown, ignored. Its
   items, with performActionForItem while it tracks: "Open Link", "Copy Link
   Address", a separator, "Paste", "Nothing to undo" disabled; Copy Link
   Address, id "1:2", sends "contextmenu-select 1:2" (ids are opaque); Paste sends `paste` with the
   pasteboard's text, nothing when it holds none or an empty one; dismissed, nothing.
   `clipboard` puts its text on the pasteboard. Paste of the Edit menu in the
   canvas, with an empty string on the pasteboard: disabled, nothing sent. A menu
   closed with the pointer off the canvas sends "leave 1 2". Once the pointer left the
   canvas, a `contextmenu` is ignored. A menu that opens while the right button
   is held (a Control-click, or a right press, not released) takes the release:
   the host sends "release 1 2 right" when it opens, a move with Control after it
   carries ctrl again, and, closed with the pointer off the canvas, "leave 1 2".
   When the pointer left (a leave sent) before such a menu opens, no release is
   sent, and the left button is the left one again.
   Linux, after that click: `cursor` messages handed to the host as if received,
   the name of the cursor set on the drawing (`gtk_widget_get_cursor`), none on
   text field 30 or what holds it: `pointer`; for text field 30 and node 999,
   nothing changes; `none`; `hand2`, not a CSS keyword, the arrow (no cursor
   set); `text`; `default`, the arrow; `pointer` again. Then, from a move to
   (0, 1), a press there dragged to (1, 3) and onto the middle of text field 30,
   released there: "21 focus", press, the two moves, the release and then
   "leave" at the field's cell (GTK's implicit grab keeps the drag the canvas's;
   the leave it tells of meanwhile is held back to the release); two moves along
   30 with no button held, nothing.
   Windows, after that click: `cursor` messages handed to the host as if received,
   the `Cursor` of the canvas's surface (its placed controls are its siblings, and
   keep theirs) and the pointer WPF gives Windows (`GetCursor`; `GetCursorInfo`
   says none is shown in a VM taking the mouse as a tablet), the mouse at (0, 1):
   `pointer`, the hand, without a move; over text field 30, the I-beam; over
   button 34 moved onto row 1, the arrow; back to (0, 1), the hand; under the
   canvas, not the hand; `none`, no pointer; `zoom-in`, the arrow; `text`, then
   for text field 30 and node 99999, nothing changes; `default`, the arrow.
   Windows, then: a right click on (2, 5), no menu coming, sends its press and
   release and opens no menu of the host's own, nor does the menu key with the
   canvas focused; `clipboard` puts its text on the clipboard, which
   `Get-Clipboard` reads once the host is gone; a `contextmenu` at (1, 3): placed
   on the canvas at the cell, Open_Link shown with its underscore, a separator,
   the disabled item greyed out; a click on Copy Link Address sends
   "contextmenu-select 1:2" (its id as it came), on Paste `paste` with the
   clipboard's text, nothing when it is empty, nor for Ctrl+V, nor while
   another thread holds the clipboard open; a second menu replaces the first;
   Shift+F10 opens no menu of the host's own either (the application gets
   "key shift+f10"); closed, nothing.
   Windows, last: the display's scale raised a step as Settings does
   (`SPI_SETLOGICALDPIOVERRIDE`), DpiChanged awaited, the placed controls on the
   pixels of the new DPI and fitting, the scale put back. Windows applies a scale
   only once saved in the profile: a run killed meanwhile leaves it raised, also
   past a restart, and the next run puts it back before its window shows (the
   step before the change, in `%TEMP%\tuiapp-canvas-scale-step.txt`); by hand,
   delete `HKCU\Control Panel\Desktop\PerMonitorSettings\<monitor>\DpiValue`.
7. The window is made 10 cells wider: "rects <the new width of node 21>".
8. `close`; exit 0 once the application said `bye` and exited.

macOS, with `TUI_APP_CANVAS_REAL_INPUT=1` (and the Accessibility permission to post
events), before step 7: events posted with CGEvent at the HID level, through the
window server, each click only where the frontmost window is the host's, and the
mouse put back after. A move to (1, 2) then `cursor` pointer: the pointing hand
on screen (`NSCursor.currentSystem`); onto label 2, "leave", its own I-beam; back,
the pointing hand with no new message. A right-click at (1, 2), its press and
release sent, then a `contextmenu` as if received: a click on its second item,
"contextmenu-select 2"; again, then Escape: nothing sent; again, then a click
on label 2, which closes the menu with the button still down: "leave 1 2".

`TUI_APP_CANVAS_CAPTURE=<dir>` writes a PNG of the window after step 5, and of the
native inputs. `windows/vm/canvas.sh` runs the test in a Windows VM.

`tools/html-demo.php` shows an HTML view with every native control in a window,
next to what stays in the canvas; `HTML_DEMO=dropdown` and `HTML_DEMO=dialog`
open a list and a dialog the view draws over the controls, which are hidden then.

The context menu of a real application (Linux, `linux/contextmenu.sh`): `tools/html-demo.php`
run by a PHP 8.4 or newer with dom that loads the checkout: `TUI_TEST_PHP` names one, like the
project's embedded runtime (`runtime/build-php.sh --version 8.5 --extensions dom --out <path>`,
static, so an older system PHP does not matter), else the system's, else the Mac's through
OrbStack's `mac`. `linux/features.sh` runs its part 2 with it too (`FEATURES_PHP` first). A real
right-click on a link shows its menu (ids like "1:2" passed through as they are), Copy Link Address
puts the href on the clipboard, as xclip reads it, and Open Link on a page the script writes is
followed (its `LinkEvent`). The host logs what the script waits for with
`G_MESSAGES_DEBUG=tui-app`.

Real input methods (Linux, `linux/ime.sh`): with `TUI_APP_CANVAS_IME`, step 5 goes
through an IBus engine the host takes for that step only, typing with xdotool, in the
canvas, then in text field 30 emptied first:

- `mozc`: Mozc in Hiragana types "nihon": the preedit にほｎ is shown, nothing is sent;
  Space converts it, Enter commits 日本: "typed 日本", one key per character, once; the
  field gets "event 30 change \"日本\"" and keeps it when the focus leaves. Then it gets
  29 characters of its 30 (`maxLength`), composes again, and the application moves the
  focus away (`focus`): what the input method commits then is cut to the 30th character
  and sent, and the field and the application agree. Linux: then number field 37,
  emptied, composes, and the application moves the focus: what the input method
  commits is sent and kept, not a number.
- `dead`: the dead keys of US international, as m17n's latn-pre composes them: ' is
  the preedit, then e and " u commit éü. Xvfb keeps the keymap it starts with
  (setxkbmap, xkbcomp and xmodmap change nothing there), so the `us(intl)` layout's
  own dead keys cannot be typed in it.

Real input methods (Windows, `windows/vm/ime.sh`, in a Windows VM): the Microsoft IME
(ja-JP) types "nihonn", Space, Space (the candidate list), Enter into text field 3 and
into the canvas: nothing is sent while composing, then the commit once, and the
candidate list, found on the screen, is next to the focused element. Then text field 30
gets 39 characters of its 40: the composition にほん is shown whole and not sent, and
its commit is cut to its first character.

IBus 1.5.29 (Ubuntu 24.04) drops the hiding of the preedit that comes back with a key
it takes synchronously (fixed in 1.5.30): its GTK module keeps the committed text as
the preedit, draws it, and commits it again when the focus leaves. A host takes a
commit of the whole preedit as the end of the composition: it resets the input method
and drops what that commits again, in a canvas and in its native text fields.


## Mobile hosts

The iOS host reuses the Swift frame, message, manifest, node tree and Unicode
helpers from `macos/Sources/TuiAppHostCore`. Its UIKit views are in `ios/Sources`.
Android separates its frame codec and node tree from the Activity and session.
Both run the contract fixtures on the emulator; see `mobile/README.md`.

Mobile hosts link PHP through the embed SAPI and run it on a worker thread.
Their manifest has `launch.mode: embedded` and `launch.command: null`.
`mobile/prepare.py` prepares the entry, arguments and environment. INI settings
are applied before interpreter startup. A native lifecycle callback requests
suspension; only the PHP worker waits, at a VM safe point. Foregrounding resumes
it. Closing a transport resumes the worker so it can shut down.

Native text controls retain their instances across patches. Composition stays
local until committed, then maxLength counts Unicode scalar values. Canvas
children use the shared place/clip cell geometry. Touch hosts advertise `touch`
as well as `pointer`; PHP selects coarse pointer/no-hover media queries. A
finger leaving the canvas ends its hover state. Vertical swipes emit wheel
messages and horizontal drags emit pointer moves. Native controls own their
platform gestures and accessibility behavior.

Development packages, device signing configuration, runtime probes and the real
native/HTML demo recipes are documented in `mobile/README.md`.
