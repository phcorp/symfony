#!/bin/sh
# The host on a virtual display, end to end: smoke.sh <meson build dir>.
# Run under: dbus-run-session -- xvfb-run -a -s '-screen 0 1280x1024x24' ./smoke.sh <build>
#  1. --self-test --dev -- php ../tools/fake-app.php, then --canvas-test with FAKE_APP_CANVAS=1
#     (CANVAS_CAPTURE=<dir> keeps what it drew), then again built with AddressSanitizer, which aborts on a read of
#     freed memory (SMOKE_ASAN=0 skips it)
#  2. a session driven with xdotool: menu shortcut, key, resize, open, refused then accepted close
#  3. package.sh (--icon, --arch, --formats, stdout), then the tarball in launch mode system, --self-test
#  4. a php older than minVersion fails with the reason
#  5. singleInstance hands a second launch's file to the first window
# Its waits are bounded in seconds, SMOKE_TIMEOUT (120), not in tries: on a loaded machine one xdotool call takes
# seconds, and a window comes up after a minute or more. A wait on the host gives up at once when the host is gone.
set -eu

build=$(CDPATH= cd -- "$1" && pwd -P)
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
host=$build/tui-app-host
work=$(mktemp -d)
[ -n "$work" ] && [ -d "$work" ] || exit 1
trap 'rm -rf "$work"' EXIT
# The canvas test composes with GTK's own input method (Ctrl+Shift+U), not one GTK finds installed, like IBus, which
# ime.sh tests
export GDK_BACKEND=x11 GTK_A11Y=none GTK_IM_MODULE=gtk-im-context-simple XDG_CONFIG_HOME="$work/config" XDG_DATA_HOME="$work/data"
cd "$here"

fail() {
    echo "smoke: FAIL: $*" >&2
    [ -f "$work/app.log" ] && sed 's/^/smoke:   app.log: /' "$work/app.log" >&2
    exit 1
}

# wait_start, then waited_out <what> [<pid>] in the loop: fails once SMOKE_TIMEOUT seconds have gone by since
# wait_start, naming what never came, how long it waited and the load, killing <pid> first (one reading of the clock)
wait_limit=${SMOKE_TIMEOUT:-120}
wait_start() {
    started=$(date +%s)
}
waited_out() {
    elapsed=$(($(date +%s) - started))
    [ "$elapsed" -lt "$wait_limit" ] && return 0
    if [ -n "${2:-}" ]; then kill "$2" 2> /dev/null || true; fi
    fail "$1 after ${elapsed} s (SMOKE_TIMEOUT=$wait_limit; load average $(cut -d ' ' -f 1-3 /proc/loadavg 2> /dev/null || echo unknown))"
}
# host_alive <pid> <its log> <what>: fails at once when the host waited on exited
host_alive() {
    kill -0 "$1" 2> /dev/null && return 0
    [ -f "$2" ] && sed 's/^/smoke:   host: /' "$2" >&2
    fail "$3: the host exited first"
}

# xvfb-run can return before its server takes clients.
wait_start
until xdotool getdisplaygeometry > /dev/null 2>&1; do
    waited_out 'the virtual display never came up'
    sleep 0.25
done

# 1. The acceptance test.
set +e
"$host" --self-test --dev -- php ../tools/fake-app.php > "$work/self-test.log" 2>&1
rc=$?
set -e
grep -v libEGL "$work/self-test.log" | grep '^self-test' || true
[ "$rc" -eq 0 ] || { cat "$work/self-test.log" >&2; fail "the self-test exited $rc"; }
echo 'smoke: 1. the self-test passes'
set +e
FAKE_APP_CANVAS=1 TUI_APP_CANVAS_CAPTURE="${CANVAS_CAPTURE:-}" "$host" --canvas-test --dev -- php ../tools/fake-app.php > "$work/canvas-test.log" 2>&1
rc=$?
set -e
grep '^canvas-test' "$work/canvas-test.log" | sed 's/^/smoke:    /' || true
[ "$rc" -eq 0 ] || { cat "$work/canvas-test.log" >&2; fail "the canvas test exited $rc"; }
echo 'smoke:    the canvas test passes: controls over a canvas, Tab to the application, input method, rects after a resize'
if [ "${SMOKE_ASAN:-1}" != 0 ]; then
    # Its patches move nodes out of containers they remove, which a host would read after freeing them
    meson setup "$work/asan" "$here" -Db_sanitize=address -Dbuildtype=debug > "$work/asan-build.txt" 2>&1 \
        && meson compile -C "$work/asan" tui-app-host >> "$work/asan-build.txt" 2>&1 \
        || { cat "$work/asan-build.txt" >&2; fail 'cannot build the host with AddressSanitizer'; }
    set +e
    ASAN_OPTIONS=detect_leaks=0 FAKE_APP_CANVAS=1 "$work/asan/tui-app-host" --canvas-test --dev -- php ../tools/fake-app.php > "$work/canvas-asan.log" 2>&1
    rc=$?
    set -e
    [ "$rc" -eq 0 ] && ! grep -q 'ERROR: AddressSanitizer' "$work/canvas-asan.log" || { cat "$work/canvas-asan.log" >&2; fail "the canvas test built with AddressSanitizer exited $rc"; }
    echo 'smoke:    and passes built with AddressSanitizer'
fi

# 2. A session driven like a user.
# Read the log without backslashes: JSON may escape slashes.
log() {
    tr -d '\\' < "$work/app.log"
}
await_log() {
    wait_start
    until log | grep -q "$1"; do
        waited_out "the application never got $1"
        sleep 0.25
    done
}
window() {
    xdotool search --onlyvisible --name '^Fake app$' 2> /dev/null | tail -n 1
}
press() {
    xdotool windowfocus --sync "$(window)" 2> /dev/null || true
    xdotool key --clearmodifiers "$1"
}
: > "$work/app.log"
printf 'x' > "$work/draft.fake"
FAKE_APP_LOG="$work/app.log" FAKE_APP_REFUSE_CLOSE=1 "$host" "$work/draft.fake" fake://open?id=42 --dev -- php ../tools/fake-app.php > "$work/host.log" 2>&1 &
pid=$!
wait_start
until [ -n "$(window)" ]; do
    host_alive "$pid" "$work/host.log" 'no window titled "Fake app"'
    waited_out 'no window titled "Fake app"'
    sleep 0.25
done
await_log '"type":"rects"'
log | grep -q "\"type\":\"open\",\"paths\":\[\"$work/draft.fake\",\"fake://open?id=42\"\]" || fail 'the command-line paths did not go out as open'
echo 'smoke: 2. the window is up; the command-line file and URL went out as open'
press ctrl+s
await_log '"type":"menu-select","id":"file.save"'
echo 'smoke:    ctrl+s chose File > Save (menu-select)'
grep -q '"key":"ctrl+s"' "$work/app.log" && fail 'ctrl+s also went out as a key'
press F5
await_log '"type":"key","key":"f5","text":null'
echo 'smoke:    F5, unused by the text field, went out as key f5'
xdotool windowsize --sync "$(window)" 1100 800 2> /dev/null || xdotool windowsize "$(window)" 1100 800
await_log '"type":"resize"'
echo "smoke:    a resize went out: $(grep '"type":"resize"' "$work/app.log" | tail -n 1)"
press ctrl+q
await_log '"type":"close"'
sleep 1
kill -0 "$pid" 2> /dev/null || fail 'the window closed although the application refused'
[ -n "$(window)" ] || fail 'the window is gone although the application refused'
echo 'smoke:    the first Quit was refused (close-reply false) and the window stayed'
press ctrl+q
wait_start
while kill -0 "$pid" 2> /dev/null; do
    waited_out 'the host did not exit after the accepted close' "$pid"
    sleep 0.25
done
wait "$pid" || fail 'the host exited non-zero'
[ "$(grep -c '"type":"close"' "$work/app.log")" -eq 2 ] || fail 'expected two close messages'
echo 'smoke:    the second Quit was accepted: bye, exit, the host quit with 0'

# 3. The packaged tarball, launch mode system.
mkdir -p "$work/payload/tools" "$work/payload/contract/fixtures" "$work/out" "$work/installed"
cp ../tools/fake-app.php "$work/payload/tools/"
cp ../contract/fixtures/messages.json "$work/payload/contract/fixtures/"
case $(uname -m) in aarch64 | arm64) arch=arm64 other=x86_64 ;; *) arch=x86_64 other=arm64 ;; esac
convert -size 256x256 xc:'#3a6ea5' -fill white -draw 'circle 128,128 128,40' "$work/icon.png"
convert -size 64x64 xc:red "$work/small.png"
export TUI_HOST_BUILD="$build"
# stdout is exactly the packages written.
./package.sh --icon "$work/icon.png" --arch "$arch" --formats tar tests/smoke-manifest.json "$work/payload" "$work/out" > "$work/written" 2> "$work/package.log" \
    || { cat "$work/package.log" >&2; fail 'package.sh --formats tar failed'; }
tarball=$work/out/org.example.FakeApp-0.1.0-linux-$arch.tar.gz
[ "$(cat "$work/written")" = "$tarball" ] || fail "package.sh printed '$(cat "$work/written")', not only $tarball"
[ "$(ls "$work/out" | wc -l)" -eq 1 ] || fail '--formats tar wrote more than the tarball'
tar -tzf "$tarball" | grep -qx './share/icons/hicolor/256x256/apps/org.example.FakeApp.png' || fail '--icon did not install the PNG into hicolor/256x256'
echo "smoke: 3. package.sh --icon <256 px PNG> --arch $arch --formats tar printed only $tarball"
if ./package.sh --arch "$other" --formats tar tests/smoke-manifest.json "$work/payload" "$work/wrong" > "$work/written" 2> "$work/package.log"; then fail "--arch $other was accepted"; fi
[ ! -s "$work/written" ] || fail 'a failed package.sh printed on stdout'
echo "smoke:    --arch $other fails: $(cat "$work/package.log")"
if ./package.sh --icon "$work/small.png" --formats tar tests/smoke-manifest.json "$work/payload" "$work/wrong" > /dev/null 2> "$work/package.log"; then fail 'a 64 px icon was accepted'; fi
echo "smoke:    a 64 px icon fails: $(cat "$work/package.log")"
mkdir -p "$work/bin-no-nfpm"
for tool in sh od tr awk sed cut mktemp rm cp mkdir chmod tar cat find dirname uname printf; do
    command -v "$tool" > /dev/null 2>&1 && ln -sf "$(command -v "$tool")" "$work/bin-no-nfpm/$tool"
done
if PATH=$work/bin-no-nfpm ./package.sh --formats deb tests/smoke-manifest.json "$work/payload" "$work/wrong" > /dev/null 2> "$work/package.log"; then fail '--formats deb without nfpm was accepted'; fi
echo "smoke:    --formats deb without nfpm fails: $(cat "$work/package.log")"
if command -v nfpm > /dev/null 2>&1; then
    ./package.sh tests/smoke-manifest.json "$work/payload" "$work/all" > "$work/written" 2> "$work/package.log" || { cat "$work/package.log" >&2; fail 'package.sh with every format failed'; }
    [ "$(wc -l < "$work/written")" -eq 3 ] || fail "the default formats printed: $(cat "$work/written")"
    sed 's/^/smoke:    default formats wrote /' "$work/written"
    tar -tzf "$work/all/org.example.FakeApp-0.1.0-linux-$arch.tar.gz" | grep -q 'scalable/apps/org.example.FakeApp.svg' || fail 'no letter icon without --icon'
fi
unset TUI_HOST_BUILD
tar -xzf "$tarball" -C "$work/installed"
(cd "$work/installed" && find . -type f | sort | sed 's/^/smoke:    tarball: /')
if command -v desktop-file-validate > /dev/null 2>&1; then
    desktop-file-validate "$work/installed/share/applications/org.example.FakeApp.desktop" || fail 'the desktop entry does not validate'
    echo 'smoke:    desktop-file-validate accepts the desktop entry'
fi
: > "$work/app.log"
set +e
FAKE_APP_LOG="$work/app.log" SMOKE_SECRET=1 "$work/installed/bin/org.example.FakeApp" --self-test > "$work/packaged.log" 2>&1
rc=$?
set -e
grep '^self-test' "$work/packaged.log" || true
[ "$rc" -eq 0 ] || { cat "$work/packaged.log" >&2; fail "the packaged self-test exited $rc"; }
grep -q '"type":"hello"' "$work/app.log" || fail 'FAKE_APP_LOG (launch.env.pass) did not reach the application'
geometry=$XDG_CONFIG_HOME/org.example.FakeApp/geometry.json
[ -f "$geometry" ] || fail "rememberGeometry wrote no $geometry"
echo "smoke:    rememberGeometry wrote $(cat "$geometry")"
echo 'smoke:    the packaged host found share/<id>/app.manifest.json, ran the system php, and passed its self-test'

# 4. A php older than minVersion: the host says what is needed.
sed 's/"minVersion": "8.1"/"minVersion": "99.0"/' "$work/installed/share/org.example.FakeApp/app.manifest.json" > "$work/old-php.json"
cp -R "$work/payload/." "$work/"
set +e
"$host" --self-test --manifest "$work/old-php.json" > "$work/old-php.log" 2>&1
rc=$?
set -e
[ "$rc" -eq 1 ] || fail "a too-old php exited $rc"
grep -q 'needs PHP 99.0 or newer' "$work/old-php.log" || { cat "$work/old-php.log" >&2; fail 'no reason for the missing php'; }
echo "smoke: 4. $(grep -o 'Fake App needs PHP 99.0 or newer[^.]*' "$work/old-php.log" | head -n 1)"

# 5. singleInstance: a second launch hands its file to the first window and exits.
sed 's/"singleInstance": false/"singleInstance": true/' "$work/installed/share/org.example.FakeApp/app.manifest.json" > "$work/installed/share/org.example.FakeApp/single.json"
: > "$work/app.log"
FAKE_APP_LOG="$work/app.log" "$host" --manifest "$work/installed/share/org.example.FakeApp/single.json" > "$work/first.log" 2>&1 &
pid=$!
wait_start
until [ -n "$(window)" ]; do
    host_alive "$pid" "$work/first.log" 'the single-instance window never came up'
    waited_out 'the single-instance window never came up'
    sleep 0.25
done
await_log '"type":"rects"'
timeout "$wait_limit" "$host" --manifest "$work/installed/share/org.example.FakeApp/single.json" "$work/second.fake" > "$work/second.log" 2>&1 || fail 'the second launch did not exit 0'
await_log "\"type\":\"open\",\"paths\":\[\"$work/second.fake\"\]"
[ "$(pgrep -c -f fake-app.php || true)" -eq 1 ] || fail 'the second launch started a second application'
echo 'smoke: 5. a second launch handed second.fake to the first instance (open) and exited'
press ctrl+q
wait_start
while kill -0 "$pid" 2> /dev/null; do
    waited_out 'the single instance did not quit' "$pid"
    sleep 0.25
done
# GTK and GLib criticals are bugs, even when the run passed (a signal of a destroyed widget at exit)
if grep -H -e '-CRITICAL \*\*' -e '-WARNING \*\*' "$work"/*.log | grep -v libEGL >&2; then
    fail 'the host printed GTK or GLib criticals or warnings'
fi
echo 'smoke: PASS'
