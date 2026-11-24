TuiApp Component
================

The TuiApp component runs a [Tui](../Tui/README.md) application as a desktop
or mobile application: the same widget tree draws with the native controls of
macOS (AppKit), Linux (GTK 4), Windows (WPF), iOS (UIKit) and Android. The
application ships with its own PHP, as a desktop package, an iOS app or an
Android APK.

**This Component is experimental**.
[Experimental features](https://symfony.com/doc/current/contributing/code/experimental.html)
are not covered by Symfony's
[Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html).

Getting Started
---------------

```bash
composer require symfony/tui-app
```

```php
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\TuiApp\Application;

$app = new Application();

$title = new TextWidget('What is your name?');
$name = new InputWidget();
$name->onSubmit(static function (SubmitEvent $event) use ($title): void {
    $title->setText('Hello '.$event->getValue().'!');
});

$app->getTui()->add($title)->add($name);
$app->run();
```

Started by a native host, the title is a label and the name a text field of
the window. Started from a terminal, the same code runs as a terminal UI.

How it works
------------

A native host owns the window or mobile screen. Desktop hosts start a PHP
process; mobile hosts run embedded PHP on a worker thread. Both give it the
address of a loopback socket and a token, which the application removes from its
environment once read, so that the processes it starts do not get them. It
connects with `HostConnection` and draws through `NativeRenderer`, a Tui
rendering backend, which draws with the renderer given to `Application`:

 * Each frame, mappers turn widgets into nodes (a text field, a list, tabs...),
   and only what changed since the last frame goes to the host.
 * What the user does on a native control goes back to its widget, which
   dispatches the events it dispatches when the same happens from the
   keyboard: `ChangeEvent`, `SubmitEvent`, `SelectEvent`, `TabChangeEvent`...
   A disabled widget takes nothing, and when the widget keeps another value
   than the one the user gave, the control shows the value of the widget.
 * A widget without a native control, like `MarkdownWidget`, draws as styled
   text on a canvas; the keys typed in it reach it like in a terminal.

| Widget                                 | Native control           |
|----------------------------------------|--------------------------|
| `ContainerWidget`                      | a stack                  |
| `TextWidget`                           | a label                  |
| `InputWidget`                          | a text field             |
| `EditorWidget`                         | a text area              |
| `SelectListWidget`                     | a list                   |
| `SettingsListWidget`                   | a form                   |
| `TabsWidget`                           | tabs                     |
| `CollapsibleWidget`                    | a disclosure             |
| `ProgressBarWidget`                    | a progress bar           |
| `LoaderWidget`, `CancellableLoaderWidget` | a spinner             |
| others                                 | a canvas of styled text  |

The protocol between the application and the host is described in
`Resources/contract/protocol.json`, with fixtures every host tests against.

Widgets of your own
-------------------

Give the application a mapper to draw a widget of your own with a native
control, or a built-in one otherwise:

```php
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;

final class DownloadMapper implements WidgetMapperInterface
{
    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof DownloadWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        return new Node('progress', $context->getCommonProps($widget) + [
            'progress' => $widget->getRatio(),
            'text' => $widget->getFileName(),
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
    }
}

$app = new Application(mappers: [new DownloadMapper()]);
```

HTML views
----------

Give the application another renderer, like the HTML renderer of TuiHtml
(`symfony/tui-html`), and it draws the TUI in a terminal, and in a window: as
one canvas, drawn by that renderer, so that the CSS layout is kept. The keys
reach the TUI like in a terminal (Enter submits a form), and text typed with
an input method (dead keys, Chinese or Japanese, emoji) is typed like any
other; Tab and Shift+Tab move the focus, like in a browser, in the order the
renderer gives (tabindex, reading-flow). The canvas is drawn again when the
window is resized; a frame taller than the window shows its bottom, like in a
terminal.

When the host sends what the mouse does (`pointer`), it reaches the view like
in a browser: a click focuses what it lands on and presses a button, toggles a
box or follows a link (a `LinkEvent`, which the application handles), the
element under the pointer matches `:hover`, as do the `(hover: hover)` media
queries, and the wheel scrolls the scroll container under the pointer, else
the frame of the window, like the scrollback of a terminal. A press drags the
thumb of a slider drawn in the canvas, and the pointer takes the CSS `cursor`
of what is under it (`cursor`). The right button opens the context menu of the
host (`contextmenu`): Open Link and Copy Link Address over a link, Paste over a
field.

When the host can (`canvas-controls`), the form controls of an HTML view are
native ones placed over the canvas where the CSS layout puts them: text fields
and text areas, checkboxes and radio buttons, pop-up buttons for a `<select>`
without `size` nor `multiple`, and buttons; and, when the host lists their
kinds, password and number fields, sliders, and lists for a `<select>` with
`size` or `multiple`. What the user does on them reaches the widgets like
typing does: their listeners get the same events, Enter in a field submits its
form, a button presses. Read-only fields, fields that complete what is typed
(a list of suggestions, a file field, whose completion Tab takes), controls
that CSS draws itself (`appearance: none`, or a button or a text area the page
gives a border), and a `<select>` with `<optgroup>` headings, which a native
pop-up or list does not show, stay drawn in the canvas, as do the controls of
a host that does not list their kind.
The `prefers-color-scheme` media queries of the view follow the light or dark
appearance of the system, also when it changes, unless the application gives
the HTML renderer a color scheme (`setMediaContext()`) other than the one of
its environment, before or after. Only the HTML renderer, or a renderer that decorates it
(`RendererDecoratorInterface`), draws the window as one canvas: with any other
renderer, the mappers draw the widgets, the renderer resolving their styles.

```php
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiHtml\Html\HtmlBuilder;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;

$app = new Application(renderer: new HtmlRenderer());
(new HtmlBuilder())->build('<h1>Hello</h1><form><input name="name" aria-label="Name"></form>')->mount($app->getTui());
$app->run();
```

The window
----------

```php
use Symfony\Component\TuiApp\Event\ContextMenuEvent;
use Symfony\Component\TuiApp\Event\ContextMenuItem;
use Symfony\Component\TuiApp\Event\MenuSelectEvent;
use Symfony\Component\TuiApp\Event\OpenEvent;
use Symfony\Component\TuiApp\Event\PointerEvent;
use Symfony\Component\TuiApp\Event\WindowCloseEvent;

$app->setMenus([
    ['title' => 'File', 'items' => [
        ['id' => 'save', 'label' => 'Save', 'shortcut' => 'ctrl+s'],
        ['separator' => true],
        ['id' => 'wrap', 'label' => 'Wrap lines', 'checked' => true],
    ]],
]);

$tui = $app->getTui();
$tui->addListener(static function (MenuSelectEvent $event): void { /* $event->getId() */ });
// What the mouse does on a canvas, of any renderer: stopPropagation() keeps an HTML view from taking it
$tui->addListener(static function (PointerEvent $event): void { /* $event->getAction(), getWidget(), getRow()... */ });
// The items of the menu of the right button, those of the view first
$tui->addListener(static function (ContextMenuEvent $event): void {
    $event->addItem(ContextMenuItem::separator());
    $event->addItem(new ContextMenuItem('Inspect', static fn () => inspect($event->getWidget())));
});
$tui->addListener(static function (OpenEvent $event): void { /* $event->getPaths() */ });
$tui->addListener(static function (WindowCloseEvent $event) use ($document): void {
    if ($document->isModified()) {
        $event->keepOpen();
    }
});

$app->notify('Export finished', '3 files written');
$tui->getTerminal()->setTitle('Acme Notes — draft.acme');
```

Building the application
------------------------

The configuration says what the application is, how its window looks, what
runs it and how it is built. In a Symfony application, it goes under
`framework.tui_app` (or `tui_app`); without a kernel, in a `tui_app.yaml` or
`tui_app.php` file at the root of the project or in `config/`, which may use
`%env(NAME)%` and `%kernel.project_dir%` too:

```yaml
# config/packages/tui_app.yaml
framework:
    tui_app:
        app:
            name: Acme Notes
            id: com.acme.notes
            version: '%env(APP_VERSION)%'
            icon: assets/icon.png # an SVG, or a PNG of at least 512×512 pixels, 1024×1024 for Retina displays
            entry: bin/app.php
            file_associations:
                - { extension: acme, name: Acme note }
            url_schemes: [acme]
        window: { width: 900, height: 640, theme: system }
        runtime:
            mode: embedded # embedded, custom, system or elephc (beta)
            php:
                version: '8.5'
                extensions: [ctype, iconv, mbstring, pcntl, posix, tokenizer]
                ini: { memory_limit: 256M }
        build:
            targets: [macos-arm64, linux-x86_64, windows-x64]
```

`bin/console config:dump-reference tui_app` lists every option.

Then build the packages, an app bundle and a disk image on macOS, deb, rpm and
tar packages on Linux, an installer on Windows:

```bash
bin/console tui-app:build          # in a Symfony application
vendor/bin/tui-app build           # without a kernel
```

The native hosts and their packaging scripts are bundled in the component’s
`Resources` directory. A target builds on its own system; Linux targets can build in a
virtual machine or a container (`build.hosts.linux_runners`).

What runs the application:

 * `embedded`: a static PHP built with static-php-cli for the application,
   with the extensions it needs, shipped with it: those of `php.extensions`,
   and those the project and the packages it installs require (`ext-*`), like
   `dom` for the HTML views of TuiHtml;
 * `custom`: a PHP binary you give, which must not need libraries of its
   installation, and must have those extensions;
 * `system`: the PHP installed on the computer, at least `php.min_version`;
 * `elephc` (beta): the application compiled to a native executable with
   [elephc](https://elephc.dev), on macOS arm64 and Linux only. elephc
   compiles a subset of PHP: the build lists what it does not support. Tui
   itself is not in that subset yet.

What `build.include` lists is shipped, but what `build.exclude` and its
defaults (tests, Markdown files, `var/`, `.env.local`...) match. The local
`.env` files (`.env.local`, `.env.*.local`) and Composer's `auth.json` never
ship, even when `build.include` lists them. Their PHP dumps (`.env.local.php`,
`.env.*.local.php`, written by `composer dump-env prod`) ship only when
`build.include` lists their path; or set `APP_ENV` with `runtime.env.set`
instead. A warning tells each time. A key that decrypts the secrets of the
application stops the build.

Mobile applications
-------------------

Build the same application for iOS devices, the iOS simulator and Android:

```yaml
build:
    targets: [ios-arm64, ios-simulator-arm64, android-arm64]
```

Both the widget tree and HTML views draw locally, with native form controls.
The UI stays on the platform's main thread; PHP runs on its own thread and
pauses at a VM safe point while the application is in the background. Native
text fields use the system keyboard, including composition and Unicode input.
Vertical swipes over HTML canvases scroll; horizontal drags reach the canvas.
Native controls keep their own touch gestures. An application's normal PHP
exit ends its process, so opening it again starts a fresh session.

The mobile runtime uses PHP 8.5.13 (`runtime.php.version: '8.5'`), with ctype,
filter, iconv, mbstring, tokenizer, Phar, DOM and libxml. The desktop defaults
pcntl and posix are omitted on mobile. Explicit requests for unsupported
extensions, including Composer requirements, stop the build. Mobile targets
require `runtime.mode: embedded`. Startup INI options, application arguments
and `runtime.env.set` are passed to the embedded application. Mobile PHP shares
the platform process environment; `runtime.env.pass` filters desktop processes. PHP diagnostics
go to `tui-php.log` in the application's cache or temporary directory.

Build iOS targets on macOS with Xcode. For Android, install a JDK, CMake, the
Android SDK and NDK on macOS or Linux, then set `ANDROID_HOME` and
`ANDROID_NDK_HOME`. The host defaults to Android platform 34 and build-tools
35.0.0; `TUI_APP_ANDROID_API` and `TUI_APP_ANDROID_BUILD_TOOLS` select other
installed versions. Android applications require API 26 or later. iOS
applications require iOS 18 or later. The initial mobile targets are arm64.

`app.id` must also be a valid Java package name for Android: use letters,
digits and dots, with each component starting with a letter. `app.icon`
provides the launcher icon; SVG icons require `rsvg-convert` (librsvg).
Window geometry, desktop menus, desktop notifications and desktop file
associations belong to the desktop hosts. Mobile hosts advertise appearance,
clipboard, touch input and their native canvas controls. HTML media queries use
`(pointer: coarse)` and `(hover: none)` on touch hosts.

The build writes `Application.app` for iOS and a debug-signed `application.apk`
for Android, under the target's output directory. The Android debug key is cached under `~/.cache/tui-app/signing`;
`TUI_APP_ANDROID_DEBUG_KEYSTORE` selects an existing debug key. The simulator app is signed
ad hoc. To install the iOS device app, configure a development identity and a
matching provisioning profile:

```yaml
signing:
    ios:
        identity: 'Apple Development: Your Name (TEAMID)'
        provisioning_profile: build/profiles/App.mobileprovision
```

The host checks the profile's expiry, application identifier and signing
certificate. Without that configuration, the device app is built with an ad
hoc signature for compile and link validation. Store submission is a separate
release workflow. Use `xcrun simctl install booted path/to/Application.app` for
the simulator and `adb install -r path/to/application.apk` for Android.

The mobile PHP builds are cached under `~/.cache/tui-app/mobile`, or under
`TUI_APP_MOBILE_CACHE`; the cache key includes the target, toolchain metadata,
source checksums and runtime build recipe. Each app keeps its data in its
platform sandbox. Android installs the bundled PHP source payload into its
private files directory, keyed by its content digest.

Development benchmarks
----------------------

From a Symfony source checkout with dependencies installed, run:

```bash
php src/Symfony/Component/TuiApp/Tests/Benchmark/runtime.php
```

The benchmark checks batched frame decoding and removal of synthetic nodes,
then reports the median, minimum and maximum duration of five samples.
Compare revisions with the same PHP binary and machine, without concurrent
builds or tests. These measurements cover PHP processing; they do not measure
native layout, painting or input latency. No timing threshold is enforced.

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/symfony/issues) and
   [send Pull Requests](https://github.com/symfony/symfony/pulls)
   in the [main Symfony repository](https://github.com/symfony/symfony)
