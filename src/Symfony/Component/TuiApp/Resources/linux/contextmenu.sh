#!/bin/sh
# The context menu of a real TuiApp application, end to end: contextmenu.sh <meson build dir>.
# Run under: dbus-run-session -- xvfb-run -a -s '-screen 0 1280x1024x24' ./contextmenu.sh <build>
# Needs xdotool and xclip, and a PHP 8.4 or newer with dom that loads the symfony/symfony checkout with TuiApp and
# TuiHtml: CONTEXTMENU_PHP, else TUI_TEST_PHP (the project's embedded runtime: runtime/build-php.sh --version 8.5
# --extensions dom --out <path>), else php, or "mac php" in an OrbStack machine whose own php cannot, and
# TUI_APP_AUTOLOAD (default the bundled autoloader helper/vendor/autoload.php). The host logs what it waits for with
# G_MESSAGES_DEBUG=tui-app and TUI_APP_TEST=1 (GLib writes its debug messages to stdout).
#  1. tools/html-demo.php: a real right-click (xdotool, button 3) on the link "one" opens the host's context menu,
#     Open Link and Copy Link Address, which the application sent (contextmenu), its first item focused; Down, Return chooses Copy Link
#     Address (contextmenu-select), and the application puts the href, #one, on the clipboard (clipboard): xclip,
#     another X client, reads it. Then Return on a second menu chooses Open Link (contextmenu-select): the demo
#     follows #one, which it has no element for, so nothing else shows it.
#  2. A page with a link to https://example.com/docs whose LinkEvent the application writes to its stderr: Open
#     Link from its context menu follows it, "link https://example.com/docs" in the host's log.
set -eu

build=$(CDPATH= cd -- "$1" && pwd -P)
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
root=$(dirname -- "$here")
host=$build/tui-app-host
autoload=${TUI_APP_AUTOLOAD:-$root/tools/autoload.php}
work=$(mktemp -d)
[ -n "$work" ] && [ -d "$work" ] || exit 1
pid=
log=$work/none.host.log
cleanup() {
    [ -n "$pid" ] && kill "$pid" 2> /dev/null || true
    case $work in /*/tmp.*) rm -rf "$work" ;; esac
}
trap cleanup EXIT
# TUI_APP_TEST=1: the host logs the text of the canvas's rows too, which cell_of reads
export GDK_BACKEND=x11 GTK_A11Y=none G_MESSAGES_DEBUG=tui-app TUI_APP_TEST=1 XDG_CONFIG_HOME="$work/config" XDG_DATA_HOME="$work/data"
cd "$here"

fail() {
    echo "contextmenu: FAIL: $*" >&2
    [ -f "$log" ] && grep -v 'canvas [0-9]* row' "$log" | tail -n 30 | sed 's/^/contextmenu:   host: /' >&2
    exit 1
}

# The PHP to run the application with: one that loads the checkout and has the dom extension TuiHtml parses with
# (Dom\HTMLDocument, PHP 8.4)
loads() { # loads <php command...>: it runs the checkout's HTML views
    "$@" -r 'require $argv[1]; exit(class_exists(Dom\HTMLDocument::class) ? 0 : 1);' "$autoload" > /dev/null 2>&1
}
how='runtime/build-php.sh --version 8.5 --extensions dom --out <path> builds one (the embedded runtime, static)'
php=${CONTEXTMENU_PHP:-${TUI_TEST_PHP:-}}
if [ -n "$php" ]; then
    # The command's first word: "mac php", "/path/to/php -n" are commands with arguments
    command -v "${php%% *}" > /dev/null 2>&1 || fail "no PHP at ${php%% *} (CONTEXTMENU_PHP, TUI_TEST_PHP): $how"
    # shellcheck disable=SC2086
    loads $php || fail "$php ($($php -r 'echo PHP_VERSION;' 2> /dev/null)) cannot run $autoload's HTML views: it needs PHP 8.4 with dom; $how"
elif loads php; then
    php=php
elif command -v mac > /dev/null 2>&1 && loads mac php; then
    php='mac php'
else
    fail "no php here runs $autoload's HTML views (PHP 8.4 with dom): set CONTEXTMENU_PHP or TUI_TEST_PHP; $how"
fi
echo "contextmenu: php: $php ($($php -r 'echo PHP_VERSION;'))"

tries=0
until xdotool getdisplaygeometry > /dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -lt 40 ] || fail 'the virtual display never came up'
    sleep 0.25
done

# await <what> <grep -E pattern>: the host's log has it within 30 s
await() {
    started=$(date +%s)
    until grep -Eq "$2" "$log"; do
        kill -0 "$pid" 2> /dev/null || fail "$1: the host exited"
        [ $(($(date +%s) - started)) -lt 30 ] || fail "$1: not in the host's log after 30 s"
        sleep 0.2
    done
}

# start <name> <php args...>: the host running the application with $php, which `mac` reaches through the address
# the host gives (OrbStack forwards the machine's localhost); `mac` passes no environment, so the address and token
# go on its command line
start() {
    name=$1
    shift
    # A log per run, all of them checked for criticals at the end
    log=$work/$name.host.log
    : > "$log"
    "$host" --dev -- sh -c 'exec '"$run"' -d display_errors=stderr "$@"' sh "$@" > "$log" 2>&1 &
    pid=$!
    await 'the hello' 'sent hello, cell'
    cell=$(sed -n 's/.*sent hello, cell \([0-9.]*\)x\([0-9.]*\).*/\1 \2/p' "$log" | head -n 1)
    cell_width=${cell% *}
    cell_height=${cell#* }
    started=$(date +%s)
    xid=
    until [ -n "$xid" ]; do
        xid=$(xdotool search --onlyvisible --pid "$pid" 2> /dev/null | tail -n 1 || true)
        [ $(($(date +%s) - started)) -lt 30 ] || fail "$name: no window"
        sleep 0.2
    done
}

# The command the host runs: `mac` passes no environment, so the address and token go on its command line (the inner
# sh expands them)
case $php in
    mac\ *) run="mac env TUI_APP_ADDR=\"\$TUI_APP_ADDR\" TUI_APP_TOKEN=\"\$TUI_APP_TOKEN\" ${php#mac }" ;;
    *) run=$php ;;
esac

# cell_of <text>: the row and column of <text> on the canvas the application draws in (the first one the host logs
# rows of): the first row with it, in the rows as last drawn; the column in cells, from the characters before it (two
# for a wide one), not their bytes (TUI_APP_TEST=1, G_MESSAGES_DEBUG)
cell_of() {
    python3 -I - "$log" "$1" << 'PY'
import re, sys, unicodedata
rows, canvas = {}, None
with open(sys.argv[1], encoding='utf-8', errors='replace') as log:
    for line in log:
        m = re.search(r'canvas (\d+) row (\d+): (.*)$', line.rstrip('\n'))
        if m:
            canvas = canvas or m.group(1)
            if m.group(1) == canvas:
                rows[int(m.group(2))] = m.group(3)
for row in sorted(rows):
    at = rows[row].find(sys.argv[2])
    if at >= 0:
        print(row, sum(2 if unicodedata.east_asian_width(c) in 'WF' else 1 for c in rows[row][:at]))
        break
PY
}

# menu_ready <n>: the n-th context menu of this run is mapped and has the focus, for the keys (the host's log)
menu_ready() {
    started=$(date +%s)
    until [ "$(grep -c 'context menu mapped, the focus in it' "$log" || true)" -ge "$1" ]; do
        grep -q 'context menu .*, the focus not in it' "$log" && fail "context menu $1 did not take the focus"
        [ $(($(date +%s) - started)) -lt 30 ] || fail "context menu $1 not mapped with the focus after 30 s"
        sleep 0.1
    done
}

# right_click <row> <column>: xdotool, button 3, on that cell of the canvas, found from the cell a move is sent at
right_click() {
    # The window the key one: a move is sent only then (or with a button held). A probe at the left edge, where the
    # canvas has text, not a control placed over it (a move onto one is a leave)
    xdotool windowfocus --sync "$xid" 2> /dev/null || true
    moves=$(grep -c 'sent pointer move' "$log" || true)
    xdotool mousemove --window "$xid" 25 300
    started=$(date +%s)
    until [ "$(grep -c 'sent pointer move' "$log" || true)" -gt "$moves" ]; do
        [ $(($(date +%s) - started)) -lt 10 ] || fail 'no pointer move sent for the mouse at 25,300 in the window'
        sleep 0.1
    done
    at=$(sed -n 's/.*sent pointer move \(-*[0-9]*\) \(-*[0-9]*\)$/\1 \2/p' "$log" | tail -n 1)
    x=$(awk -v c="$2" -v at="${at#* }" -v w="$cell_width" 'BEGIN { printf "%d", 25 + (c - at) * w }')
    y=$(awk -v r="$1" -v at="${at% *}" -v h="$cell_height" 'BEGIN { printf "%d", 300 + (r - at) * h }')
    xdotool mousemove --window "$xid" "$x" "$y" click 3
}

# 1. tools/html-demo.php
start demo "$root/tools/html-demo.php" "$autoload"
await 'the link "one"' 'canvas [0-9]+ row [0-9]+: Links: one'
link=$(cell_of 'one, two')
[ -n "$link" ] || fail 'no link "one" in the canvas'
echo "contextmenu: 1. html-demo.php: the link \"one\" at row ${link% *}, column ${link#* }"
right_click ${link% *} $((${link#* } + 1))
await 'the context menu of the link' 'context menu shown on canvas [0-9]+ at .*=Open Link; [^;]*=Copy Link Address'
menu=$(grep -E 'context menu shown on canvas' "$log" | tail -n 1 | sed 's/.*context menu shown on //')
echo "contextmenu:    a right-click (xdotool) on it showed the context menu: $menu"
copy_id=$(echo "$menu" | sed -n 's/.*; \([^;=]*\)=Copy Link Address.*/\1/p')
menu_ready 1
# The menu opens with its first item focused (GtkPopoverMenu): Down goes to the second
xdotool key --clearmodifiers Down Return
await 'Copy Link Address chosen' "sent contextmenu-select $copy_id\$"
await 'the clipboard message' 'clipboard: 4 characters'
copied=$(timeout 10 xclip -o -selection clipboard 2> /dev/null || true)
[ "$copied" = '#one' ] || fail "xclip read \"$copied\" from the clipboard, not #one"
echo "contextmenu:    Down, Return chose Copy Link Address (contextmenu-select $copy_id): xclip reads $copied"
right_click ${link% *} $((${link#* } + 1))
started=$(date +%s)
until [ "$(grep -c 'context menu shown on canvas' "$log")" -ge 2 ]; do
    [ $(($(date +%s) - started)) -lt 30 ] || fail 'no second context menu'
    sleep 0.2
done
menu_ready 2
open_id=$(grep -E 'context menu shown on canvas' "$log" | tail -n 1 | sed -n 's/.*: \([^;=]*\)=Open Link.*/\1/p')
[ "$open_id" != "$(echo "$menu" | sed -n 's/.*: \([^;=]*\)=Open Link.*/\1/p')" ] || fail "the second menu has the ids of the first: $open_id"
xdotool key --clearmodifiers Return
await 'Open Link chosen' "sent contextmenu-select $open_id\$"
echo "contextmenu:    Return on a second menu chose Open Link (contextmenu-select $open_id)"
kill "$pid"
wait "$pid" 2> /dev/null || true
pid=

# 2. A link the application logs when it is followed
cat > "$work/link-app.php" << 'PHP'
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiHtml\Event\LinkEvent;
use Symfony\Component\TuiHtml\Html\HtmlBuilder;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;
// Run by -r: $argv[1] is this code, $argv[2] the autoloader
require $argv[2];
$app = new Application(renderer: new HtmlRenderer());
$view = (new HtmlBuilder())->build('<p>Café, thé, crème — read the docs: <a href="https://example.com/docs">docs</a>.</p>')->mount($app->getTui());
$app->getTui()->addListener(static function (LinkEvent $event): void {
    fwrite(STDERR, 'link '.$event->getHref()."\n");
});
$app->run();
PHP
# Its code inline, run with eval: a file here is not one the Mac's php can read (our own code, written above)
code=$(base64 < "$work/link-app.php" | tr -d '\n')
start link -r 'eval(base64_decode($argv[1]));' -- "$code" "$autoload"
await 'the link "docs"' 'canvas [0-9]+ row [0-9]+: Café, thé, crème — read the docs: docs'
link=$(cell_of 'docs.')
echo "contextmenu:    the link \"docs\", after Café, thé, crème —, at row ${link% *}, column ${link#* }"
right_click ${link% *} $((${link#* } + 1))
await 'the context menu of the link' 'context menu shown on canvas [0-9]+ at .*=Open Link'
menu_ready 1
open_id=$(grep -E 'context menu shown on canvas' "$log" | tail -n 1 | sed -n 's/.*: \([^;=]*\)=Open Link.*/\1/p')
xdotool key --clearmodifiers Return
await 'Open Link chosen' "sent contextmenu-select $open_id\$"
await 'the link followed' 'link https://example.com/docs'
echo "contextmenu: 2. Open Link (contextmenu-select $open_id) on https://example.com/docs: the application followed it (LinkEvent)"
kill "$pid"
wait "$pid" 2> /dev/null || true
pid=
if grep -H -e '-CRITICAL \*\*' -e '-WARNING \*\*' "$work"/*.host.log | grep -v libEGL >&2; then
    fail 'the host printed GTK or GLib criticals or warnings'
fi
echo 'contextmenu: PASS'
