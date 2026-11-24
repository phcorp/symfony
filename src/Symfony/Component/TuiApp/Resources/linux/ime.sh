#!/bin/sh
# Real input methods in the Linux host: the canvas test's input method stage (HOSTS.md) through IBus
# rather than GTK's own, in the canvas and in a native text field: ime.sh <meson build dir>.
# Run under: dbus-run-session -- xvfb-run -a -s '-screen 0 1280x1024x24' ./ime.sh <build>
# Needs ibus, ibus-gtk4 (GTK 4's IBus module), ibus-mozc and ibus-m17n (m17n-db).
#  1. mozc: Japanese, Mozc started in Hiragana (active_on_launch): "nihon" shows the preedit にほ…,
#     Space converts it, Enter commits 日本: sent once, one key per character, nothing while composing; in text field 30
#     too, then, one character short of its maxLength, the application moves the focus while it composes:
#     what Mozc commits then is cut to maxLength and sent; in number field 37 too, what it commits as the application
#     moves the focus is sent and kept
#  2. dead: the dead keys of US international, as m17n's latn-pre composes them (' then e is é,
#     " then u is ü). Xvfb keeps the keymap it starts with (setxkbmap, xkbcomp and xmodmap change
#     nothing there), so the us(intl) layout's own dead keys cannot be typed; an input method that
#     composes them does the same in the toolkit: a preedit, then the commit.
# IME_CAPTURE=<dir> keeps what the host drew (the preedits, the window), and its output.
set -eu

build=$(CDPATH= cd -- "$1" && pwd -P)
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
host=$build/tui-app-host
work=$(mktemp -d)
[ -n "$work" ] && [ -d "$work" ] || exit 1
cleanup() {
    ibus exit > /dev/null 2>&1 || true
    case $work in /*/tmp.*) rm -rf "$work" ;; esac
}
trap cleanup EXIT
export GDK_BACKEND=x11 GTK_A11Y=none GTK_IM_MODULE=ibus XDG_CONFIG_HOME="$work/config" XDG_DATA_HOME="$work/data"
cd "$here"

fail() {
    echo "ime: FAILED: $*" >&2
    exit 1
}

for tool in ibus-daemon ibus xdotool; do
    command -v "$tool" > /dev/null || fail "$tool is not installed"
done

# xvfb-run can return before its server takes clients.
tries=0
until xdotool getdisplaygeometry > /dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -lt 40 ] || fail 'the virtual display never came up'
    sleep 0.25
done

# Mozc in Hiragana from the start, not in direct input
mkdir -p "$XDG_CONFIG_HOME/mozc"
printf '%s\n' 'engines {' '  name: "mozc-jp"' '  longname: "Mozc"' '  layout: "default"' '}' 'active_on_launch: True' > "$XDG_CONFIG_HOME/mozc/ibus_config.textproto"

ibus-daemon --daemonize --replace --panel=disable > "$work/ibus.log" 2>&1
tries=0
until ibus list-engine > /dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -lt 40 ] || fail 'ibus-daemon did not start'
    sleep 0.25
done

run() { # run <name> <engine>: the host takes the engine for its input method stage
    ibus list-engine | grep -q "^ *$2 " || fail "the IBus engine $2 is not installed"
    # Plain keys for the other stages
    ibus engine xkb:us::eng || true
    [ "$(ibus engine)" = xkb:us::eng ] || fail 'IBus did not take the engine xkb:us::eng'
    capture=
    if [ -n "${IME_CAPTURE:-}" ]; then
        mkdir -p "$IME_CAPTURE/$1"
        capture=$(CDPATH= cd -- "$IME_CAPTURE/$1" && pwd -P)
    fi
    set +e
    FAKE_APP_CANVAS=1 TUI_APP_CANVAS_IME=$1 TUI_APP_CANVAS_CAPTURE=$capture "$host" --canvas-test --dev -- php ../tools/fake-app.php > "$work/$1.out" 2> "$work/$1.err"
    rc=$?
    set -e
    [ -z "$capture" ] || cp "$work/$1.out" "$work/$1.err" "$capture/"
    grep '^canvas-test' "$work/$1.out" | sed "s/^/ime: $1: /"
    [ "$rc" -eq 0 ] || { grep -v '^{\|libEGL' "$work/$1.err" >&2 || true; fail "$1 exited with $rc"; }
    grep -q '^canvas-test: PASS$' "$work/$1.out" || fail "$1 did not pass"
}

run mozc mozc-jp
run dead m17n:t:latn-pre

# GTK and GLib criticals are bugs, even when the run passed; but for what IBus 1.5.29 says of the hiding of the preedit
# it drops (see im_committed() in src/main.c)
if grep -H -e '-CRITICAL \*\*' -e '-WARNING \*\*' "$work"/*.out "$work"/*.err | grep -v -e libEGL -e "IBUS-WARNING \*\*: .*: ibus_input_context_post_process_key_event: Type 'h' is not supported" >&2; then
    fail 'the host printed GTK or GLib criticals or warnings'
fi
echo 'ime: ok'
