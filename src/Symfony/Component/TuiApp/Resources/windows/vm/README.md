# Testing the Windows host in a VM

Scripts that run the Windows host's tests in a Windows VM from the Mac. They were written for a UTM VM
(`tuiapp-windows`, Windows 11 on Arm) but only need ssh to a Windows guest whose user is logged on.
Windows on Arm runs both hosts: arm64 natively, x64 emulated.

Publish the host first, for the architecture to test:

    dotnet publish App -c Release -r win-arm64 --self-contained -o publish/win-arm64

| Script | What it does |
| --- | --- |
| `run.sh [arm64\|x64]` | `--self-test` against the stand-in app, then a screenshot of the window (`shot.ps1`). Reaches the VM over ssh when it answers there, else through the UTM guest agent (`utmctl`, whose calls can stall; `TUIAPP_VM_TRANSPORT=ssh` or `utmctl` forces one), checks in the guest that its script arrived and its task started, trying again up to 3 times, and downloads PHP for the guest. Results in `out/` and `../screenshot.png`. |
| `features.sh [arm64\|x64]` | `--feature-test` (HOSTS.md) three ways: `--dev` with the stand-in app, `--dev` with a real TuiApp application (`TUIAPP_VM_AUTOLOAD`), and a manifest with `singleInstance` launched as the file handler does. Each must say ok and leave no process. Flips the guest's light/dark setting and puts it back. Results in `out/features/` (output and the PNGs the host drew). |
| `package.sh [arm64\|x64]` | Builds the installer and the portable `.zip` in the guest with `package.ps1 -Arch <arch>` (the stand-in app, the smoke manifest, Inno Setup 6 in the guest), then runs `package-e2e.sh` on them. x64 installs and runs emulated on Windows on Arm. |
| `package-e2e.sh '<guest folder>'` | The `.zip` and setup `.exe` that `tui-app build` made in the guest: the portable app self-tested; installed silently, self-tested, its file associations, URL schemes, uninstall entry and Start menu shortcut checked; uninstalled, and all of them checked gone. |
| `canvas.sh [arm64\|x64]` | `--canvas-test` (HOSTS.md) against the stand-in app, its window brought to the front: controls over a canvas, `maxLength`, the native inputs (password, number, slider, list box), Tab, the canvas's input method path, a resize. Must pass and leave no process. Results in `out/canvas/` (output, what the application got, `canvas.png` and `inputs.png`). |
| `ime.sh [arm64\|x64]` | The Microsoft IME (ja-JP) for real: adds ja-JP to the user's input languages (and puts them back), types "nihonn", Space, Space, Enter into a text field and into the canvas of the stand-in app, and checks that nothing goes out while composing and the commit once, and that the candidate list opens next to the focused element (found on captures of the screen: Windows 11's IME draws it in TextInputHost's window over the whole screen). Then a text field with 39 characters of its `maxLength` 40: the composition is shown whole and not sent, and its commit cut to one character. Then, with `ime-app.php`, which updates its fields' other props every 100 ms, a key that commits and starts a new composition (k a typed on after a conversion), in a text field, a full one and a canvas: the commit sent once, the new composition never sent, cut or wiped. `TUIAPP_VM_IME_PART=1` or `2` runs one part. Needs the guest capability `Language.Basic~~~ja-JP`. Results and captures of the guest's screen in `out/ime/`. |
| `link.sh [arm64\|x64]` | A real TuiApp application, `link-app.php` (its page one link), run by the host: a right click on the link through Windows, Copy Link Address and Open Link chosen in the host's context menu (found by UI Automation, clicked), `Get-Clipboard` and the application's log checked, then the close button. The application runs from a kit `link-kit.php` makes of a checkout with the TuiApp component (`TUIAPP_CHECKOUT`, default `~/Sites/symfony-tui-app`): the components and packages it loads, about 12 MB, checked here first. Results in `out/link/`. |
| `session1.sh <script.ps1> [args]` | What the others use: runs a guest script in the logged-on user's session. |

## The guest

- An OpenSSH server, and a key for the user (`TUIAPP_VM_SSH`, default `user@192.168.64.3`;
  `TUIAPP_VM_KEY`, default `~/.ssh/utm_windows`). `utmctl ip-address tuiapp-windows` gives the address.
- The user logged on (automatic logon is fine): windows open only in an interactive session. An ssh
  session is session 0, so `session1.sh` runs the script as a scheduled task with `-LogonType
  Interactive` in the user's session, then waits for it.
- PHP on the user's PATH, or `TUIAPP_VM_PHP` (a `php.exe` in the guest) for `features.sh`.
- For `package-e2e.sh`: packages built in the guest by `tui-app build --target windows-<arch>`, which
  needs Inno Setup 6 there; signing needs signtool and a certificate.

Guest paths and arguments go through a scheduled task's command line: no spaces in them. `session1.sh`
refuses any but letters, digits and `_ . , : = + @ \ / -`.
