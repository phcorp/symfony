# TuiApp native hosts

Native hosts for Symfony TuiApp: they draw a Symfony Tui widget tree with
native controls (AppKit on macOS, GTK 4 on Linux, WPF on Windows, UIKit on iOS and Android controls), and
package the application with its runtime.

- `contract/`: the canonical protocol and manifest contract. Every host tests against
  `contract/fixtures`.
- `macos/`, `linux/`, `windows/`, `ios/`, `android/`: the hosts.
- `mobile/`: embedded PHP, shared mobile packaging and acceptance tools. See [mobile development](mobile/README.md).
- `runtime/`: builds of the embedded PHP.
- `tools/fake-app.php`: a stand-in application for host tests.
