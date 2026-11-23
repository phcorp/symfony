#!/bin/sh
# The Linux host's --feature-test (HOSTS.md) end to end: features.sh <meson build dir>.
# Run under: dbus-run-session -- xvfb-run -a -s '-screen 0 1280x1024x24' ./features.sh <build>
# tests/fake-desktop.py stands in for the desktop on the session bus (notification server,
# settings portal); dbus-monitor shows what the host sent there.
#  1. --dev against the stand-in app: the appearance follows the GTK theme name (no portal)
#  2. --dev against a real TuiApp application (tools/feature-app.php), when the Symfony
#     checkout of TUI_APP_AUTOLOAD (default the bundled autoloader helper) is there and FEATURES_PHP,
#     else TUI_TEST_PHP, else php can load it (PHP 8.4 or newer: the project's embedded runtime,
#     runtime/build-php.sh --version 8.5 --out <path>, where the system's is older)
#  3. a manifest with singleInstance, launched as the desktop entry does (-- %U): a second
#     launch hands a file and a URL over, and the appearance follows the settings portal
# FEATURES_CAPTURE=<dir> keeps what the host drew (window, dialogs). Checks that no process is
# left behind after each run.
set -eu

build=$(CDPATH= cd -- "$1" && pwd -P)
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
root=$(dirname -- "$here")
host=$build/tui-app-host
work=$(mktemp -d)
[ -n "$work" ] && [ -d "$work" ] || exit 1
desktop=
monitor=
cleanup() {
    [ -n "$desktop" ] && kill "$desktop" 2> /dev/null || true
    [ -n "$monitor" ] && kill "$monitor" 2> /dev/null || true
    case $work in /*/tmp.*) rm -rf "$work" ;; esac
}
trap cleanup EXIT
export GDK_BACKEND=x11 GTK_A11Y=none XDG_CONFIG_HOME="$work/config" XDG_DATA_HOME="$work/data"
cd "$here"

fail() {
    echo "features: FAILED: $*" >&2
    exit 1
}

# xvfb-run can return before its server takes clients.
tries=0
until xdotool getdisplaygeometry > /dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -lt 40 ] || fail 'the virtual display never came up'
    sleep 0.25
done

start_desktop() { # start_desktop <name> [--portal light|dark]
    [ -n "$desktop" ] && { kill "$desktop"; wait "$desktop" 2> /dev/null || true; }
    [ -n "$monitor" ] && { kill "$monitor"; wait "$monitor" 2> /dev/null || true; }
    name=$1
    shift
    : > "$work/$name.desktop.log"
    python3 tests/fake-desktop.py "$work/$name.desktop.log" "$@" &
    desktop=$!
    tries=0
    until grep -q '"ready": true' "$work/$name.desktop.log"; do
        tries=$((tries + 1))
        [ "$tries" -lt 40 ] || fail 'fake-desktop.py did not start'
        sleep 0.25
    done
    dbus-monitor --session "type='signal',interface='com.canonical.Unity.LauncherEntry'" > "$work/$name.monitor.log" 2>&1 &
    monitor=$!
    sleep 0.5
}

capture() { # capture <name>: where the host draws, if FEATURES_CAPTURE is set
    if [ -n "${FEATURES_CAPTURE:-}" ]; then
        mkdir -p "$FEATURES_CAPTURE/$1"
        CDPATH= cd -- "$FEATURES_CAPTURE/$1" && pwd -P
    fi
}

# The test passed, the desktop got the notification and the progress, and nothing is left.
check_run() { # check_run <name> <rc>
    grep '^feature-test' "$work/$1.out" | sed "s/^/features: $1: /"
    [ "$2" -eq 0 ] || { grep -v '^{\|libEGL' "$work/$1.err" >&2 || true; fail "$1 exited with $2"; }
    grep -q '^feature-test .*: ok$' "$work/$1.out" || fail "$1 did not say ok"
    pid=$(sed -n 's/^feature-test .*: the window closed; process \([0-9]*\) is gone$/\1/p' "$work/$1.out")
    [ -n "$pid" ] || fail "$1 did not name the application's process"
    if kill -0 "$pid" 2> /dev/null; then fail "$1: the application's process $pid is still there"; fi
    left=$(pgrep -fa "$work" | grep -v 'fake-desktop.py\|dbus-monitor' || true)
    [ -z "$left" ] || { echo "$left" >&2; fail "$1 left processes behind"; }
    grep -q '"summary": "Feature test", "body": "notify reached the host"' "$work/$1.desktop.log" || fail "$1: the notification server got no Notify"
    echo "features: $1: the notification server got: $(grep '"notify"' "$work/$1.desktop.log")"
    grep -A12 'member=Update' "$work/$1.monitor.log" | tr -s ' \n' ' ' | grep -q 'string "progress" variant double 0.5' || fail "$1: no launcher progress of 0.5 on D-Bus"
    echo "features: $1: dbus-monitor saw com.canonical.Unity.LauncherEntry.Update with progress 0.5"
    echo "features: $1: passed; no process left behind"
}

# The apps run from $work, so that pgrep can tell them apart.
mkdir -p "$work/tools" "$work/contract/fixtures"
cp "$root/tools/fake-app.php" "$root/tools/feature-app.php" "$work/tools/"
cp "$root/contract/fixtures/messages.json" "$work/contract/fixtures/"

echo 'features: 1. --dev, stand-in app, no portal'
start_desktop dev
set +e
FAKE_APP_FEATURES=1 FAKE_APP_LOG="$work/dev.app.log" TUI_APP_FEATURE_CAPTURE=$(capture dev) \
    timeout 90 "$host" --feature-test --dev -- php "$work/tools/fake-app.php" > "$work/dev.out" 2> "$work/dev.err"
rc=$?
set -e
check_run dev "$rc"
grep -q 'set the GTK theme to Adwaita-dark, as XSETTINGS does' "$work/dev.out" || fail 'the appearance did not go through the GTK theme name'
[ "$(grep -c '"type":"close"' "$work/dev.app.log")" -eq 3 ] || fail 'the stand-in app did not get three closes'

autoload=${TUI_APP_AUTOLOAD:-$root/tools/autoload.php}
php=${FEATURES_PHP:-${TUI_TEST_PHP:-php}}
if [ -f "$autoload" ] && "$php" -r 'require $argv[1];' "$autoload" > /dev/null 2>&1; then
    echo "features: 2. --dev, a real TuiApp application ($autoload)"
    start_desktop real
    set +e
    timeout 90 "$host" --feature-test --dev -- "$php" "$work/tools/feature-app.php" "$autoload" > "$work/real.out" 2> "$work/real.err"
    rc=$?
    set -e
    check_run real "$rc"
else
    if ! command -v "$php" > /dev/null 2>&1; then
        echo "features: 2. skipped: no PHP at $php (FEATURES_PHP, TUI_TEST_PHP)"
    else
        echo "features: 2. skipped: no $autoload, or $php ($("$php" -r 'echo PHP_VERSION;' 2> /dev/null)) cannot load it"
    fi
    echo "features:    a PHP that can: FEATURES_PHP or TUI_TEST_PHP, e.g. the embedded runtime runtime/build-php.sh --version 8.5 --out <path> builds"
fi

echo 'features: 3. singleInstance, launched as the desktop entry does, with the settings portal'
mkdir -p "$work/app/tools" "$work/app/contract/fixtures"
cp "$root/tools/fake-app.php" "$work/app/tools/"
cp "$root/contract/fixtures/messages.json" "$work/app/contract/fixtures/"
sed -e 's/"singleInstance": false/"singleInstance": true/' -e 's/"pass": \["FAKE_APP_LOG"\]/"pass": ["FAKE_APP_LOG", "FAKE_APP_FEATURES"]/' \
    tests/smoke-manifest.json > "$work/app/app.manifest.json"
grep -q '"singleInstance": true' "$work/app/app.manifest.json" && grep -q FAKE_APP_FEATURES "$work/app/app.manifest.json" || fail 'cannot make the manifest'
start_desktop app --portal light
printf 'x' > "$work/-launch.fake"
set +e
(cd "$work" && FAKE_APP_FEATURES=1 FAKE_APP_LOG="$work/app.app.log" TUI_APP_FEATURE_CAPTURE=$(capture app) \
    timeout 90 "$host" --feature-test --manifest "$work/app/app.manifest.json" -- -launch.fake > "$work/app.out" 2> "$work/app.err")
rc=$?
set -e
check_run app "$rc"
grep -q "feature-test .*: open at launch: $work/-launch.fake\$" "$work/app.out" || fail 'the file after -- did not arrive at launch'
grep -q 'the portal switched color-scheme to dark' "$work/app.out" || fail 'the appearance did not go through the portal'
grep -q '"color-scheme": 1' "$work/app.desktop.log" || fail 'the portal was not switched'
grep -q 'node 2 reads "open: /.*/tui-feature-[^/]*/-feature-test.fake | fake-tuiapp://feature?id=42"' "$work/app.out" || fail 'the second launch did not hand its file and URL over'

# GTK and GLib criticals are bugs, even when the run passed (a signal of a destroyed widget at exit)
if grep -H -e '-CRITICAL \*\*' -e '-WARNING \*\*' "$work"/*.out "$work"/*.err 2> /dev/null | grep -v libEGL >&2; then
    fail 'the host printed GTK or GLib criticals or warnings'
fi
echo 'features: PASS'
