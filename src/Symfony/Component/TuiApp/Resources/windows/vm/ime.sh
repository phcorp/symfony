#!/usr/bin/env bash
# A real input method in the Windows host, in the Windows VM, from the Mac: the Microsoft IME (ja-JP) types
# "nihonn", Space, Space (the candidate list), Enter, into text field 3 and into the canvas (node 21) of the
# host on the stand-in app (tools/fake-app.php, FAKE_APP_CANVAS=1). Each must send nothing while composing
# and the commit once: change "日本" (or the candidate chosen) for the field, one key per kanji for the canvas;
# the candidate list must open next to the focused element. Then text field 30 (maxLength 40) with 39
# characters: the composition shown whole, nothing sent, the commit cut to its first character. Then, with
# vm/ime-app.php, which updates its fields' other props every 100 ms: n i h o n n, Space, then k a, which commits
# 日本 and composes か in one go, into a text field, a text field with room for one character, and a canvas: the
# commit sent once (cut to 日 in the second), the new composition neither sent nor cut nor wiped by the updates.
# TUIAPP_VM_IME_PART=1 or 2 runs one part only; TUIAPP_VM_IME_TICK sets the updates' period in ms (0: none).
# The guest needs the capability Language.Basic~~~ja-JP; the test adds ja-JP to the user's input languages
# and puts the list back. It brings the host's window to the front and types with keybd_event.
#
# Usage: vm/ime.sh [arm64|x64]   (publish first: dotnet publish App -c Release -r win-<arch> --self-contained -o publish/win-<arch>)
# Needs php on the guest's PATH, or TUIAPP_VM_PHP (a php.exe in the guest). See vm/session1.sh for the VM.
# Results: vm/out/ime/ (ime.txt, what the application got, and captures of the guest's screen at each step).
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
repo="$(cd "$here/.." && pwd)"
arch="${1:-arm64}"
target="${TUIAPP_VM_SSH:-user@192.168.64.3}"
key="${TUIAPP_VM_KEY:-$HOME/.ssh/utm_windows}"
ssh_opts=(-i "$key" -o IdentitiesOnly=yes -o ConnectTimeout=15 -o ServerAliveInterval=30)
[ -f "$here/publish/win-$arch/TuiAppHost.exe" ] || { echo "publish win-$arch first" >&2; exit 1; }

stage="$(mktemp -d "${TMPDIR:-/tmp}/tuiapp-ime.XXXXXX")"
[ -n "$stage" ] && [ -d "$stage" ] || exit 1
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/kit/tools" "$stage/kit/contract/fixtures"
cp "$repo/tools/fake-app.php" "$stage/kit/tools/"
cp "$repo/contract/fixtures/messages.json" "$stage/kit/contract/fixtures/"
cp "$here/vm/ime-app.php" "$stage/kit/"
(cd "$here/publish/win-$arch" && zip -qr "$stage/host.zip" .)
(cd "$stage/kit" && zip -qr "$stage/kit.zip" .)
ssh "${ssh_opts[@]}" "$target" 'if not exist C:\tuiapp-vm mkdir C:\tuiapp-vm' > /dev/null
scp -q "${ssh_opts[@]}" "$stage/host.zip" "$target:C:/tuiapp-vm/host-$arch.zip"
scp -q "${ssh_opts[@]}" "$stage/kit.zip" "$target:C:/tuiapp-vm/kit.zip"

args=(-Arch "$arch")
[ -n "${TUIAPP_VM_IME_PART:-}" ] && args+=(-Part "$TUIAPP_VM_IME_PART")
[ -n "${TUIAPP_VM_IME_TICK:-}" ] && args+=(-Tick "$TUIAPP_VM_IME_TICK")
[ -n "${TUIAPP_VM_PHP:-}" ] && args+=(-Php "$TUIAPP_VM_PHP")
set +e
"$here/vm/session1.sh" "$here/vm/ime.ps1" "${args[@]}"
rc=$?
set -e
rm -rf -- "$here/vm/out/ime"
mkdir -p "$here/vm/out"
scp -q -r "${ssh_opts[@]}" "$target:C:/tuiapp-vm/out/ime" "$here/vm/out/" || true
exit "$rc"
