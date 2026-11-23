#!/usr/bin/env bash
# The Windows host's --canvas-test (HOSTS.md) in the Windows VM, from the Mac: the stand-in app
# (tools/fake-app.php, FAKE_APP_CANVAS=1) places native controls over a canvas, and the test drives them,
# the canvas's input method path, Tab and a resize. The window is brought to the front, as the test needs
# the keyboard focus. It must pass and leave no process behind.
#
# Usage: vm/canvas.sh [arm64|x64]   (publish first: dotnet publish App -c Release -r win-<arch> --self-contained -o publish/win-<arch>)
# Needs php on the guest's PATH, or TUIAPP_VM_PHP (a php.exe in the guest). See vm/session1.sh for the VM.
# TUIAPP_VM_DARK=1: with the apps' dark mode on in the guest (put back at the end), for the captures.
# Results: vm/out/canvas/ (canvas.txt, the test's output, what the application got, canvas.png and inputs.png).
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
repo="$(cd "$here/.." && pwd)"
arch="${1:-arm64}"
target="${TUIAPP_VM_SSH:-user@192.168.64.3}"
key="${TUIAPP_VM_KEY:-$HOME/.ssh/utm_windows}"
ssh_opts=(-i "$key" -o IdentitiesOnly=yes -o ConnectTimeout=15 -o ServerAliveInterval=30)
[ -f "$here/publish/win-$arch/TuiAppHost.exe" ] || { echo "publish win-$arch first" >&2; exit 1; }

stage="$(mktemp -d "${TMPDIR:-/tmp}/tuiapp-canvas.XXXXXX")"
[ -n "$stage" ] && [ -d "$stage" ] || exit 1
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/kit/tools" "$stage/kit/contract/fixtures"
cp "$repo/tools/fake-app.php" "$stage/kit/tools/"
cp "$repo/contract/fixtures/messages.json" "$stage/kit/contract/fixtures/"
(cd "$here/publish/win-$arch" && zip -qr "$stage/host.zip" .)
(cd "$stage/kit" && zip -qr "$stage/kit.zip" .)
ssh "${ssh_opts[@]}" "$target" 'if not exist C:\tuiapp-vm mkdir C:\tuiapp-vm' > /dev/null
scp -q "${ssh_opts[@]}" "$stage/host.zip" "$target:C:/tuiapp-vm/host-$arch.zip"
scp -q "${ssh_opts[@]}" "$stage/kit.zip" "$target:C:/tuiapp-vm/kit.zip"

args=(-Arch "$arch")
[ -n "${TUIAPP_VM_PHP:-}" ] && args+=(-Php "$TUIAPP_VM_PHP")
[ "${TUIAPP_VM_DARK:-}" = 1 ] && args+=(-Dark 1)
set +e
"$here/vm/session1.sh" "$here/vm/canvas.ps1" "${args[@]}"
rc=$?
set -e
rm -rf -- "$here/vm/out/canvas"
mkdir -p "$here/vm/out"
scp -q -r "${ssh_opts[@]}" "$target:C:/tuiapp-vm/out/canvas" "$here/vm/out/" || true
exit "$rc"
