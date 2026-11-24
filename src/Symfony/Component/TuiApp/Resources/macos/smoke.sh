#!/bin/sh
# The macOS host end to end: builds, runs the unit tests, runs the self-test and the canvas
# test against tools/fake-app.php in --dev mode, then packages the fake app as a .app (launch mode system)
# and runs that bundle's self-test too. Needs php on PATH.
set -eu

here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
root=$(dirname -- "$here")
cd "$here"
work=$(mktemp -d)

fail() {
    echo "smoke: FAILED: $1" >&2
    exit 1
}

# A self-test gives up by itself after 20 s; this catches a host that hangs before that.
within() { # within <seconds> <command…>
    seconds=$1
    shift
    perl -e 'alarm shift; exec @ARGV or die "exec: $!"' "$seconds" "$@"
}

echo 'smoke: swift build'
swift build
host=$(swift build --show-bin-path)/TuiAppHost

echo 'smoke: swift test'
swift test || fail 'swift test'

echo 'smoke: self-test, --dev'
set +e
within 60 "$host" --self-test --dev -- php ../tools/fake-app.php 2> "$work/dev.err"
rc=$?
set -e
[ "$rc" -eq 0 ] || { cat "$work/dev.err" >&2; fail "the --dev self-test exited with $rc"; }
grep -q '"name":"submit","node":3,"value":"Ada"' "$work/dev.err" || fail 'the fake app did not log the submit event'

echo 'smoke: self-test, --dev, with a close refused once'
set +e
FAKE_APP_REFUSE_CLOSE=1 within 60 "$host" --self-test --dev -- php ../tools/fake-app.php 2> "$work/refuse.err"
rc=$?
set -e
[ "$rc" -eq 0 ] || { cat "$work/refuse.err" >&2; fail "the self-test with a refused close exited with $rc"; }

echo 'smoke: canvas-test, --dev'
set +e
# A variable set for a shell function stays set after it: not refusing to close here
FAKE_APP_REFUSE_CLOSE= FAKE_APP_CANVAS=1 within 60 "$host" --canvas-test --dev -- php ../tools/fake-app.php > "$work/canvas.out" 2> "$work/canvas.err"
rc=$?
set -e
cat "$work/canvas.out"
[ "$rc" -eq 0 ] || { cat "$work/canvas.err" >&2; fail "the canvas test exited with $rc"; }
grep -q '"type":"key","key":"日","text":"日"' "$work/canvas.err" || fail 'the fake app did not log the committed text as keys'

echo 'smoke: a .app around the fake app'
payload=$work/payload
mkdir -p "$payload/tools" "$payload/contract/fixtures"
cp "$root/tools/fake-app.php" "$payload/tools/"
cp "$root/contract/fixtures/messages.json" "$payload/contract/fixtures/"
cat > "$work/app.manifest.json" <<'JSON'
{
    "manifest": 1,
    "app": {"name": "Fake App", "id": "dev.tuiapp.smoke.fake", "version": "0.1.0", "copyright": "© 2026 The TuiApp authors",
            "category": "developer-tools", "singleInstance": false,
            "fileAssociations": [{"extension": "fake", "name": "Fake document", "mimeType": null, "role": "editor"}],
            "urlSchemes": ["fake-tuiapp"]},
    "window": {"title": "Fake app", "width": 900, "height": 720, "resizable": true, "rememberGeometry": false, "theme": "system", "canvasFont": {"family": null, "size": 13}},
    "menu": {"native": true, "about": true, "quit": true},
    "launch": {"mode": "system", "command": null, "args": ["./tools/fake-app.php"], "php": {"path": null, "minVersion": "8.1"},
               "env": {"pass": ["FAKE_APP_LOG"], "set": {}}},
    "logging": {"file": null, "level": "warning"}
}
JSON
sips -s format png -Z 1024 /System/Library/CoreServices/CoreTypes.bundle/Contents/Resources/GenericApplicationIcon.icns --out "$work/icon.png" > /dev/null
app=$("$here/build-app.sh" --host "$host" --icon "$work/icon.png" "$work/app.manifest.json" "$payload" "$work/out")
echo "smoke: built $app"

plist=$app/Contents/Info.plist
check() { # check <key> <expected>
    value=$(/usr/libexec/PlistBuddy -c "Print :$1" "$plist" 2>/dev/null || true)
    [ "$value" = "$2" ] || fail "Info.plist $1 is '$value', not '$2'"
}
check CFBundleName 'Fake App'
check CFBundleIdentifier dev.tuiapp.smoke.fake
check CFBundleShortVersionString 0.1.0
check NSHumanReadableCopyright '© 2026 The TuiApp authors'
check LSApplicationCategoryType public.app-category.developer-tools
check CFBundleExecutable 'Fake App'
check CFBundleIconFile AppIcon.icns
check CFBundleDocumentTypes:0:CFBundleTypeExtensions:0 fake
check CFBundleURLTypes:0:CFBundleURLSchemes:0 fake-tuiapp
[ -f "$app/Contents/Resources/AppIcon.icns" ] || fail 'no icon in the bundle'
cmp -s "$work/app.manifest.json" "$app/Contents/Resources/app.manifest.json" || fail 'the manifest was not copied'
[ -f "$app/Contents/Resources/tools/fake-app.php" ] || fail 'the payload was not copied'
codesign --verify --strict "$app" || fail 'the bundle signature does not verify'

echo 'smoke: self-test, .app'
set +e
FAKE_APP_LOG=$work/bundle.log within 60 "$app/Contents/MacOS/Fake App" --self-test
rc=$?
set -e
[ "$rc" -eq 0 ] || fail "the .app self-test exited with $rc"
# FAKE_APP_LOG reached the application through launch.env.pass.
grep -q '"type":"hello"' "$work/bundle.log" || fail 'the bundled app logged no hello: launch.env.pass did not reach it'

echo 'smoke: ok'
case $work in /*/tmp.*) rm -rf "$work" ;; esac
