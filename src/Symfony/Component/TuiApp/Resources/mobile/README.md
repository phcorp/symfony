# Mobile hosts and embedded PHP

TuiApp runs its widget tree and HTML canvases in a UIKit iOS app or an Android
Activity. PHP is linked into the application and runs on one worker thread.
The host and PHP exchange the same authenticated loopback frames as the desktop
hosts. Foreground/background callbacks pause PHP at a VM safe point; native UI
work stays on the main thread. A normal PHP exit ends the application process.

Use Symfony's `tui-app:build` command or `vendor/bin/tui-app build`. Configure
`ios-arm64`, `ios-simulator-arm64` and/or `android-arm64` in `build.targets`.
See the TuiApp component README for signing and configuration examples.

## Build requirements

- iOS: macOS with Xcode and the selected device or simulator SDK.
- Android: macOS or Linux, a JDK, CMake, an Android SDK and NDK. Set
  `ANDROID_HOME` and `ANDROID_NDK_HOME`. The default build-tools version is
  35.0.0 and the default compile/target platform is 34; override with
  `TUI_APP_ANDROID_BUILD_TOOLS` and `TUI_APP_ANDROID_API` for installed versions.
- Python 3, curl, make and the platform's C tools. SVG icons also need
  `rsvg-convert`; PNG icons use the platform packaging tools.

The initial targets are arm64, iOS 18+ and Android API 26+. Development builds
produce an iOS `Application.app` or a debug-signed Android `application.apk`.
iOS simulator builds use ad hoc signing. Device installation needs a matching
development identity and provisioning profile. Android debug keys stay in the
private TuiApp signing cache. Store signing, publication and submission are release
workflows outside these scripts.

## Runtime

`build-runtime.sh TARGET OUTPUT` uses a checksum-keyed build cache and copies
its headers, static library and dependencies into `OUTPUT`. The cache defaults
to `~/.cache/tui-app/mobile`; `TUI_APP_MOBILE_CACHE` overrides it. The cache key
includes the target, SDK/NDK metadata, source lock, build recipes and C flags.
`compile-runtime.sh` and `build-dependencies.sh` implement a cache miss.
Downloads are verified against `runtime/php-sources.lock` before extraction.

PHP 8.5.13 is built with ctype, filter, iconv, mbstring, tokenizer, Phar, DOM and
libxml. Android builds static libiconv and libxml2; iOS links its SDK libraries.
PCRE JIT and OPcache JIT are disabled. iOS process creation is disabled. Android
omits DNS record-query APIs absent from the public NDK; ordinary host lookup
remains available. Unsupported extension requests stop Symfony's build.

Startup INI values are installed before PHP initializes. The prepared bootstrap
sets the application's arguments and environment, then requires its entry at
global scope. PHP shares the native process environment; `runtime.env.pass`
filters desktop child processes. Authentication variables are removed by the
PHP connection after they are read. PHP diagnostics default to `tui-php.log`
in the application's temporary/cache directory. Each process starts PHP once.

Android copies the bundled PHP payload into its private files directory, keyed
by a digest of its paths and contents. iOS reads the bundle directly. Use the
platform's writable data directories for persistent application data and PHP's
`sys_get_temp_dir()` for temporary files.

Mobile hosts advertise `touch`, `pointer`, `appearance`, `clipboard` and the
native controls they implement. Touch maps to coarse pointer/non-hovering CSS
media queries. Vertical canvas swipes scroll, horizontal drags reach the
canvas, and native controls keep their own gestures. Desktop menus, desktop
notifications and desktop file associations stay with the desktop hosts.

## Validation

```sh
mobile/tests/build-runtime.sh
python3 mobile/tests/prepare-test.py
swift test --package-path macos
php /path/to/symfony/phpunit /path/to/symfony/src/Symfony/Component/TuiApp/Tests/Protocol
```

Create a small real Symfony demo from a checkout, then use the normal builder:

```sh
python3 mobile/tests/make-demo.py /path/to/symfony /tmp/tui-mobile-demo
cd /tmp/tui-mobile-demo
php /path/to/symfony/src/Symfony/Component/TuiApp/Resources/bin/tui-app build
# Select the HTML demo at build time:
TUI_DEMO_HTML=1 php /path/to/symfony/src/Symfony/Component/TuiApp/Resources/bin/tui-app build
```

`TUI_APP_HOST_TESTS=1` packages the shared contract fixtures and Android test
class. Android runs them with `--ez selftest true`; iOS uses `--contract-test`.
They write `HOST_TESTS=0` to `tests.txt` in private app storage. Android's same
mode also tests native composition, Unicode limits, secure password input,
disabled controls, single selection, stepping and touch release/leave events, writing `CONTROLS_TESTS=0` to
`controls-tests.txt`. iOS runs its native controls and touch sequence checks with `--controls-test`.

The real native demo accepts `--ez uitest true` on Android or `--ui-test` on
iOS. The HTML demo accepts `--ez htmltest true` or `--html-test`. They type Ada
through native control callbacks and submit to PHP, then check the returned
label/canvas. Success writes `UI_TEST=0` or `HTML_TEST=0`. PHP independently
writes `native-submitted.txt` or `html-submitted.txt` in its temporary directory.
The demos also verify startup INI settings, UTF-8 values and application arguments named `-d` that must not change PHP INI settings.

The runtime probe scripts in `tests/` build small standalone apps. Their PHP
script checks extensions, DOM and Unicode, authenticates, emits a patch, pauses
while a command is pending, resumes and shuts down. Success writes
`TUI_MOBILE_PROBE=0` to `probe.txt`. The host-native probe is separate from
widget and HTML rendering acceptance.

For Android, use `adb shell run-as APP_ID cat files/tests.txt` and the other
receipt files; the PHP temporary directory is `cache/`. For iOS, get the data
container with `xcrun simctl get_app_container DEVICE APP_ID data` and inspect
`Documents/` and `tmp/`. Restart or terminate the app between modes. Review
screenshots after changing layout or native controls. Emulator validation and
physical device installation are separate checks.
