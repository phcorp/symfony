#!/usr/bin/env bash
# Runs the self-test and a screenshot of the published host in the tuiapp-windows UTM VM, from the Mac.
#
# The VM is reached over ssh when it answers there (TUIAPP_VM_SSH, default <user>@192.168.64.3, key TUIAPP_VM_KEY,
# default ~/.ssh/utm_windows; TUIAPP_VM_TRANSPORT=ssh or utmctl forces one), else through the UTM guest agent (utmctl
# exec, file push and pull). utmctl goes through UTM's AppleEvents, whose calls can stall for 2 minutes (OSStatus -1712)
# and never reach the guest, seen while the Mac was locked; ssh goes to the guest over the bridge. Either way the
# session is session 0, where no window can open, so it registers a scheduled task that runs vm/guest.ps1 in the
# logged-on user's session. The guest fetches its files over HTTP from
# this Mac on the UTM bridge (192.168.64.1), served by `php -S` for the duration of the run.
# guest.ps1 is pushed into the guest and the task runs it with -File: a hidden PowerShell that downloads a
# script and runs it (iwr …; & …) is what Defender's Trojan:Win32/Commando.B!ml looks for in a command line,
# and it then refuses to start the task's action, at times ("access denied", 0x80070005, TaskScheduler event 203).
#
# Usage: vm/run.sh [arm64|x64]   (publish first: dotnet publish App -c Release -r win-<arch> --self-contained -o publish/win-<arch>)
# Results: vm/out/ (selftest.txt, guest.txt, app.log) and screenshot.png next to this folder.
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
repo="$(cd "$here/.." && pwd)"
arch="${1:-arm64}"
vm="${TUIAPP_VM:-tuiapp-windows}"
user="${TUIAPP_VM_USER:-user}"
port="${TUIAPP_VM_PORT:-8765}"
bridge="${TUIAPP_VM_BRIDGE:-192.168.64.1}"
php_zip="${TUIAPP_PHP_ZIP:-}"
serve="$(mktemp -d "${TMPDIR:-/tmp}/tuiapp-vm.XXXXXX")"
[ -n "$serve" ] && [ -d "$serve" ] || { echo "no staging folder" >&2; exit 1; }
cleanup() {
    [ -n "${server:-}" ] && kill "$server" 2>/dev/null || true
    rm -rf -- "$serve"
}
trap cleanup EXIT

[ -f "$here/publish/win-$arch/TuiAppHost.exe" ] || { echo "publish win-$arch first" >&2; exit 1; }
if [ -z "$php_zip" ]; then
    php_zip="$serve/php.zip"
    version="$(curl -sf https://downloads.php.net/~windows/releases/releases.json | python3 -c 'import json,sys; print(json.load(sys.stdin)["8.4"]["nts-vs17-x64"]["zip"]["path"])')"
    curl -sfL -o "$php_zip" "https://downloads.php.net/~windows/releases/$version"
else
    cp "$php_zip" "$serve/php.zip"
fi
(cd "$here/publish/win-$arch" && zip -qr "$serve/host.zip" .)
mkdir -p "$serve/kit/tools" "$serve/kit/contract/fixtures" "$serve/kit/vm"
cp "$repo/tools/fake-app.php" "$serve/kit/tools/"
cp "$repo/contract/fixtures/messages.json" "$serve/kit/contract/fixtures/"
cp "$here/vm/shot.ps1" "$serve/kit/vm/"
(cd "$serve/kit" && zip -qr "$serve/kit.zip" .)

php -S "$bridge:$port" -t "$serve" >/dev/null 2>&1 &
server=$!
sleep 1

# The transport: g_ps runs PowerShell in the guest (as SYSTEM through utmctl, as the user over ssh), g_push copies a
# file in, g_pull prints one (empty when it is not there)
[ -n "$user" ] || { echo "no guest user" >&2; exit 2; }
target="${TUIAPP_VM_SSH:-$user@192.168.64.3}"
ssh_opts=(-i "${TUIAPP_VM_KEY:-$HOME/.ssh/utm_windows}" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4)
transport="${TUIAPP_VM_TRANSPORT:-auto}"
if [ "$transport" = auto ]; then
    if ssh "${ssh_opts[@]}" "$target" 'echo ok' 2>/dev/null | grep -q ok; then transport=ssh; else transport=utmctl; fi
fi
slashed() { printf '%s' "${1//\\//}"; }
if [ "$transport" = ssh ]; then
    g_ps() { ssh "${ssh_opts[@]}" "$target" "powershell -NoProfile -NonInteractive -EncodedCommand $(printf '%s' "$1" | iconv -t UTF-16LE | base64 | tr -d '\n')" >/dev/null 2>&1 || true; }
    g_push() { scp -q "${ssh_opts[@]}" "$1" "$target:$(slashed "$2")" 2>/dev/null || true; }
    g_pull() {
        local copy
        copy="$(mktemp "$serve/pull.XXXXXX")"
        scp -q "${ssh_opts[@]}" "$target:$(slashed "$1")" "$copy" 2>/dev/null && cat "$copy"
        rm -f -- "$copy"
        return 0
    }
    ssh "${ssh_opts[@]}" "$target" 'echo ok' 2>/dev/null | grep -q ok || { echo "$target does not answer over ssh" >&2; exit 1; }
else
    g_ps() { utmctl exec "$vm" --cmd powershell.exe -NoProfile -Command "$1" >/dev/null 2>&1 || true; }
    g_push() { utmctl file push "$vm" "$2" < "$1" >/dev/null 2>&1 || true; }
    g_pull() { utmctl file pull "$vm" "$1" 2>/dev/null || true; }
    utmctl exec "$vm" --cmd cmd.exe /c "echo ok > C:\\Windows\\Temp\\tuiapp-ping.txt" >/dev/null 2>&1 || true
    g_pull 'C:\Windows\Temp\tuiapp-ping.txt' | grep -q ok || { echo "the guest agent of $vm does not answer" >&2; exit 1; }
fi
echo "run.sh: through $transport" >&2

# The results of an earlier run must not pass for this one's: gone here, and a folder of the guest's own for this
# run (the guest agent may keep a file it read open, which then cannot be replaced)
run="$(date +%Y%m%d%H%M%S)$$"
out="C:\\Users\\$user\\tuiapp-test\\out-$run"
mkdir -p "$here/vm/out"
for f in guest.txt selftest.txt selftest-err.txt selftest-exit.txt app.log; do
    rm -f -- "$here/vm/out/$f"
done
rm -f -- "$here/screenshot.png"

# utmctl's calls to the guest agent can stall (2 min, then OSStatus -1712) and never reach the guest, and utmctl
# exits 0 when they fail: what the calls did is checked in the guest, and they are tried again, up to $attempts times.
attempts="${TUIAPP_VM_ATTEMPTS:-3}"

# The guest script, in the guest under a name of this run's and attempt's, read back: the agent may keep a file it
# wrote open, empty when its call stalled, which another attempt then cannot open again
pushed=
for attempt in $(seq 1 "$attempts"); do
    guest="C:\\Users\\$user\\tuiapp-test\\guest-$run-$attempt.ps1"
    g_ps "New-Item -ItemType Directory -Force -Path C:\\Users\\$user\\tuiapp-test | Out-Null"
    g_push "$here/vm/guest.ps1" "$guest"
    if g_pull "$guest" | tr -d '\r' | cmp -s - <(tr -d '\r' < "$here/vm/guest.ps1"); then
        pushed=1
        break
    fi
    echo "run.sh: guest.ps1 is not in the guest after attempt $attempt of $attempts ($transport)" >&2
done
[ -n "$pushed" ] || { echo "guest.ps1 did not reach the guest (C:\\Users\\$user\\tuiapp-test\\guest-$run-<attempt>.ps1) in $attempts attempts" >&2; exit 1; }

# As SYSTEM (utmctl) or the user (ssh): a task in the user's interactive session, removed again at the end. Then, in the guest, what schtasks
# says of it a few seconds after its start, which is pulled: there and running (or done) is a start; missing, the
# command did not reach the guest; a last result of 0x80070005 (-2147024891), its action was refused
started=
for attempt in $(seq 1 "$attempts"); do
    state="C:\\Users\\$user\\tuiapp-test\\task-$run-$attempt.txt"
    register="\$a = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File $guest -Base http://$bridge:$port -Run $run';
\$p = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive;
Register-ScheduledTask -TaskName TuiAppHostTest -Action \$a -Principal \$p -Force | Out-Null;
Start-ScheduledTask -TaskName TuiAppHostTest;
Start-Sleep -Seconds 3;
schtasks /Query /TN TuiAppHostTest /V /FO LIST 2>&1 | Out-File -Encoding ascii $state"
    g_ps "$register"
    query=
    for _ in $(seq 1 6); do
        sleep 5
        query="$(g_pull "$state" | tr -d '\r')"
        [ -n "$query" ] && break
    done
    if [ -z "$query" ]; then
        echo "run.sh: attempt $attempt of $attempts: no answer from the guest on the task (the command did not reach it?)" >&2
    elif ! grep -q 'TuiAppHostTest' <<<"$query"; then
        echo "run.sh: attempt $attempt of $attempts: the task is not registered: $(grep -m1 -i 'error' <<<"$query")" >&2
    elif grep -Eq '^Last Result: *(-2147024891|2147942405)' <<<"$query"; then
        echo "run.sh: attempt $attempt of $attempts: the task's action was refused (access denied; Defender, event 1116?)" >&2
    elif grep -Eq '^Status: *Running' <<<"$query" || grep -Eq '^Last Result: *(0|267009)$' <<<"$query"; then
        started=1
        [ "$attempt" -eq 1 ] || echo "run.sh: the task started at attempt $attempt" >&2
        break
    else
        echo "run.sh: attempt $attempt of $attempts: the task did not start: $(grep -E '^(Status|Last Result):' <<<"$query" | tr '\n' ' ')" >&2
    fi
done
[ -n "$started" ] || { echo "the guest's task did not start in $attempts attempts" >&2; g_ps "Unregister-ScheduledTask -TaskName TuiAppHostTest -Confirm:\$false"; exit 1; }

done=
for _ in $(seq 1 120); do
    sleep 5
    if g_pull "$out\\done.txt" | grep -q done; then
        done=1
        break
    fi
done
g_ps "Unregister-ScheduledTask -TaskName TuiAppHostTest -Confirm:\$false"

mkdir -p "$here/vm/out"
for f in guest.txt selftest.txt selftest-err.txt selftest-exit.txt app.log; do
    g_pull "$out\\$f" > "$here/vm/out/$f"
done
g_pull "$out\\screenshot.png" > "$here/screenshot.png"
[ -n "$done" ] || { echo "the guest did not finish in 10 minutes (its task started)" >&2; cat "$here/vm/out/guest.txt" 2>/dev/null; exit 1; }
cat "$here/vm/out/selftest.txt" "$here/vm/out/selftest-exit.txt"
grep -q 'exit 0' "$here/vm/out/selftest-exit.txt"
