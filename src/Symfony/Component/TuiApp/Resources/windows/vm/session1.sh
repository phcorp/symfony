#!/usr/bin/env bash
# Runs a guest script in the logged-on user's session of the Windows VM, where windows can open and take
# the foreground, and prints what it wrote. Over ssh: the ssh session is session 0, so the script runs as a
# scheduled task (LogonType Interactive) in session 1, and this waits for its done.txt.
#
# Usage: vm/session1.sh <script.ps1> [arguments for it…]
# The script is copied to C:\tuiapp-vm\ and gets -Out C:\tuiapp-vm\out; it writes <its name>.txt there,
# <its name>.exit (its exit code) and done.txt last. This exits with that code.
#
# TUIAPP_VM_SSH (default user@192.168.64.3; `utmctl ip-address tuiapp-windows` gives the address) and
# TUIAPP_VM_KEY (default ~/.ssh/utm_windows) say how to reach the VM; the user is the logged-on one.
set -euo pipefail
target="${TUIAPP_VM_SSH:-user@192.168.64.3}"
key="${TUIAPP_VM_KEY:-$HOME/.ssh/utm_windows}"
wait_seconds="${TUIAPP_VM_WAIT:-600}"
ssh_opts=(-i "$key" -o IdentitiesOnly=yes -o ConnectTimeout=15 -o ServerAliveInterval=30)
script="$1"
shift
name="$(basename "$script" .ps1)"
user="${target%@*}"

# Each goes into a single-quoted PowerShell string and a scheduled task's command line, split at spaces: only
# characters that mean nothing to either (no spaces, no quotes, no $ or ;), else this stops
plain() {
    case "$1" in
        '' | *[!A-Za-z0-9_.,:=+@\\/-]*) echo "session1: '$1': only letters, digits and _ . , : = + @ \\ / - please" >&2; exit 2 ;;
    esac
}
plain "$name"
plain "$user"
for argument in "$@"; do
    plain "$argument"
done

ssh "${ssh_opts[@]}" "$target" 'if not exist C:\tuiapp-vm\out mkdir C:\tuiapp-vm\out' > /dev/null
scp -q "${ssh_opts[@]}" "$script" "$target:C:/tuiapp-vm/$name.ps1"

# The task's action: the script, -Out and the arguments as they are (checked above)
register="$(mktemp "${TMPDIR:-/tmp}/tuiapp-vm.XXXXXX")"
[ -n "$register" ] || exit 1
trap 'rm -f -- "$register"' EXIT
cat > "$register" <<PS
Remove-Item -Force -ErrorAction SilentlyContinue C:\\tuiapp-vm\\out\\done.txt, C:\\tuiapp-vm\\out\\$name.txt, C:\\tuiapp-vm\\out\\$name.exit
\$a = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File C:\\tuiapp-vm\\$name.ps1 -Out C:\\tuiapp-vm\\out $*'
\$p = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive
Register-ScheduledTask -TaskName TuiAppVm -Action \$a -Principal \$p -Force | Out-Null
Start-ScheduledTask -TaskName TuiAppVm
PS
scp -q "${ssh_opts[@]}" "$register" "$target:C:/tuiapp-vm/register.ps1"
ssh "${ssh_opts[@]}" "$target" 'powershell -NoProfile -ExecutionPolicy Bypass -File C:\tuiapp-vm\register.ps1' > /dev/null

finished=
for _ in $(seq 1 $((wait_seconds / 5))); do
    sleep 5
    if ssh "${ssh_opts[@]}" "$target" 'if exist C:\tuiapp-vm\out\done.txt echo done' 2> /dev/null | grep -q done; then
        finished=1
        break
    fi
done
ssh "${ssh_opts[@]}" "$target" "schtasks /delete /tn TuiAppVm /f > nul & type C:\\tuiapp-vm\\out\\$name.txt" | tr -d '\r' || true
[ -n "$finished" ] || { echo "session1: $name did not finish within $wait_seconds s" >&2; exit 1; }
code="$(ssh "${ssh_opts[@]}" "$target" "type C:\\tuiapp-vm\\out\\$name.exit" 2> /dev/null | tr -dc '0-9-')"
exit "${code:-1}"
