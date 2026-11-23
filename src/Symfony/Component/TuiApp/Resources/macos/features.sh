#!/bin/sh
# The macOS host's --feature-test (HOSTS.md) end to end: notify, Dock progress, beep, menus,
# appearance, open (at launch and while running, through Launch Services) and the close flow
# with its force-quit alert.
#  1. --dev against the stand-in app (FAKE_APP_FEATURES=1 tools/fake-app.php)
#  2. --dev against a real TuiApp application (tools/feature-app.php), when the Symfony
#     checkout of TUI_APP_AUTOLOAD (default the bundled autoloader helper) is there
#  3. a .app, launched by `open -a` with a file: the file arrives as an open documents Apple
#     event, and the test asks Launch Services to open a file and a URL while it runs
# FEATURES_CAPTURE=<dir> keeps what the host drew (window, Dock tile, alerts).
# Needs php on PATH. Leaves no process behind: checked after each run.
set -eu

here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
root=$(dirname -- "$here")
cd "$here"
work=$(mktemp -d)
[ -n "$work" ] && [ -d "$work" ] || exit 1
lsregister=/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister
app=
cleanup() {
    [ -n "$app" ] && [ -d "$app" ] && "$lsregister" -u "$app" 2> /dev/null || true
    case $work in /*/tmp.*) rm -rf "$work" ;; esac
}
trap cleanup EXIT

fail() {
    echo "features: FAILED: $1" >&2
    exit 1
}

within() { # within <seconds> <command…>
    seconds=$1
    shift
    perl -e 'alarm shift; exec @ARGV or die "exec: $!"' "$seconds" "$@"
}

# The test passed, and nothing it started is left.
check_run() { # check_run <name> <rc> <stdout> <stderr> <app log>
    grep '^feature-test' "$3" | sed "s/^/features: $1: /"
    [ "$2" -eq 0 ] || { grep -v '^{' "$4" >&2 || true; fail "$1 exited with $2"; }
    grep -q '^feature-test .*: ok$' "$3" || fail "$1 did not say ok"
    if pgrep -f "$work" > /dev/null; then
        pgrep -fl "$work" >&2
        fail "$1 left processes behind"
    fi
    pid=$(sed -n 's/^feature-test .*: the window closed; process \([0-9]*\) is gone$/\1/p' "$3")
    [ -n "$pid" ] || fail "$1 did not name the application's process"
    if kill -0 "$pid" 2> /dev/null; then fail "$1: the application's process $pid is still there"; fi
    echo "features: $1: passed; no process left behind"
}

capture() { # capture <name>: where the host draws, if FEATURES_CAPTURE is set
    if [ -n "${FEATURES_CAPTURE:-}" ]; then
        mkdir -p "$FEATURES_CAPTURE/$1"
        CDPATH= cd -- "$FEATURES_CAPTURE/$1" && pwd -P
    fi
}

echo 'features: swift build'
swift build
host=$(swift build --show-bin-path)/TuiAppHost

# The apps run from $work, so that pgrep can tell them apart.
mkdir -p "$work/tools" "$work/contract/fixtures"
cp "$root/tools/fake-app.php" "$root/tools/feature-app.php" "$work/tools/"
cp "$root/contract/fixtures/messages.json" "$work/contract/fixtures/"

echo 'features: 1. --dev, stand-in app'
set +e
FAKE_APP_FEATURES=1 FAKE_APP_LOG="$work/fake.log" TUI_APP_FEATURE_CAPTURE=$(capture dev) \
    within 90 "$host" --feature-test --dev -- php "$work/tools/fake-app.php" > "$work/fake.out" 2> "$work/fake.err"
rc=$?
set -e
check_run 'stand-in app' "$rc" "$work/fake.out" "$work/fake.err" "$work/fake.log"
for line in '"type":"hello"' '"type":"menu-select","id":"file.save"' '"type":"menu-select","id":"file.wrap"' '"type":"appearance"' hanging; do
    grep -q "$line" "$work/fake.log" || fail "the stand-in app did not log $line"
done
[ "$(grep -c '"type":"close"' "$work/fake.log")" -eq 3 ] || fail 'the stand-in app did not get three closes'

autoload=${TUI_APP_AUTOLOAD:-$root/tools/autoload.php}
if [ -f "$autoload" ]; then
    echo "features: 2. --dev, a real TuiApp application ($autoload)"
    set +e
    within 90 "$host" --feature-test --dev -- php "$work/tools/feature-app.php" "$autoload" > "$work/real.out" 2> "$work/real.err"
    rc=$?
    set -e
    check_run 'TuiApp app' "$rc" "$work/real.out" "$work/real.err" /dev/null
    grep -q hanging "$work/real.err" || fail 'the TuiApp app did not hang on the second close'
else
    echo "features: 2. skipped: no $autoload"
fi

echo 'features: 3. a .app, launched by open -a with a file'
payload=$work/payload
mkdir -p "$payload/tools" "$payload/contract/fixtures"
cp "$root/tools/fake-app.php" "$payload/tools/"
cp "$root/contract/fixtures/messages.json" "$payload/contract/fixtures/"
cat > "$work/app.manifest.json" <<'JSON'
{
    "manifest": 1,
    "app": {"name": "Feature Test", "id": "dev.tuiapp.features.fake", "version": "0.1.0", "category": "developer-tools", "singleInstance": false,
            "fileAssociations": [{"extension": "fake", "name": "Fake document", "mimeType": null, "role": "editor"}],
            "urlSchemes": ["fake-tuiapp"]},
    "window": {"title": "Feature test", "width": 900, "height": 720, "resizable": true, "rememberGeometry": false, "theme": "system", "canvasFont": {"family": null, "size": 13}},
    "menu": {"native": true, "about": true, "quit": true},
    "launch": {"mode": "system", "command": null, "args": ["./tools/fake-app.php"], "php": {"path": null, "minVersion": "8.1"},
               "env": {"pass": ["FAKE_APP_FEATURES", "FAKE_APP_LOG"], "set": {}}},
    "logging": {"file": null, "level": "warning"}
}
JSON
sips -s format png -Z 1024 /System/Library/CoreServices/CoreTypes.bundle/Contents/Resources/GenericApplicationIcon.icns --out "$work/icon.png" > /dev/null
app=$("$here/build-app.sh" --host "$host" --icon "$work/icon.png" "$work/app.manifest.json" "$payload" "$work/out")
echo "features: built $app"
printf 'x' > "$work/launch.fake"
launch=$(CDPATH= cd -- "$work" && pwd -P)/launch.fake
# -n: a new instance even if one runs; the environment goes through --env, the output to files.
open -n -a "$app" --stdout "$work/bundle.out" --stderr "$work/bundle.err" \
    --env FAKE_APP_FEATURES=1 --env FAKE_APP_LOG="$work/bundle.log" --env TUI_APP_FEATURE_CAPTURE="$(capture app)" \
    "$launch" --args --feature-test
binary="$app/Contents/MacOS/Feature Test"
tries=0
until pgrep -f "$binary" > /dev/null; do
    tries=$((tries + 1))
    [ "$tries" -lt 40 ] || fail 'the .app did not start'
    sleep 0.25
done
tries=0
while pgrep -f "$binary" > /dev/null; do
    tries=$((tries + 1))
    [ "$tries" -lt 360 ] || { pkill -9 -f "$work"; fail 'the .app feature test did not end within 90 s'; }
    sleep 0.25
done
grep -q 'FAILED' "$work/bundle.err" "$work/bundle.out" && rc=1 || rc=0
check_run '.app' "$rc" "$work/bundle.out" "$work/bundle.err" "$work/bundle.log"
grep -q "feature-test .*: open at launch: $launch\$" "$work/bundle.out" || fail 'the file given to open -a did not arrive at launch'
# A signed bundle notifies through UNUserNotificationCenter; macOS refuses an ad hoc signed one,
# which falls back to osascript.
if ! grep -q 'posted to UNUserNotificationCenter' "$work/bundle.out"; then
    grep -q 'shown with osascript (UNUserNotificationCenter: ' "$work/bundle.out" || fail 'the notification went neither through UNUserNotificationCenter nor osascript'
    echo 'features: .app: ad hoc signed: UNUserNotificationCenter refused, osascript showed the notification'
fi
grep -q '"type":"open","paths":\["fake-tuiapp://feature?id=42"\]' "$work/bundle.log" || fail 'the URL did not reach the application'

echo 'features: ok'
