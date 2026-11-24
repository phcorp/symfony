#!/usr/bin/env bash
# The Windows host's --feature-test (HOSTS.md) end to end in the Windows VM, from the Mac:
#  1. --dev against the stand-in app (tools/fake-app.php, FAKE_APP_FEATURES=1)
#  2. --dev against a real TuiApp application (tools/feature-app.php), when the guest has the autoloader
#     of a checkout with the TuiApp component (TUIAPP_VM_AUTOLOAD, a path in the guest)
#  3. a manifest with singleInstance, launched as the file and URL handlers do (-- <file>): the file
#     arrives at launch, then a second launch hands a file and a URL over
# Each run must say ok, leave no process behind, and the stand-in app must have got three closes.
# The test flips AppsUseLightTheme (and puts it back), as Settings does.
#
# Usage: vm/features.sh [arm64|x64]   (publish first: dotnet publish App -c Release -r win-<arch> --self-contained -o publish/win-<arch>)
# Needs php on the guest's PATH, or TUIAPP_VM_PHP (a php.exe in the guest). See vm/session1.sh for the VM.
# Results: vm/out/features/ (features.txt, the runs' output and the PNGs the host drew).
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
repo="$(cd "$here/.." && pwd)"
arch="${1:-arm64}"
target="${TUIAPP_VM_SSH:-user@192.168.64.3}"
key="${TUIAPP_VM_KEY:-$HOME/.ssh/utm_windows}"
ssh_opts=(-i "$key" -o IdentitiesOnly=yes -o ConnectTimeout=15 -o ServerAliveInterval=30)
[ -f "$here/publish/win-$arch/TuiAppHost.exe" ] || { echo "publish win-$arch first" >&2; exit 1; }

stage="$(mktemp -d "${TMPDIR:-/tmp}/tuiapp-features.XXXXXX")"
[ -n "$stage" ] && [ -d "$stage" ] || exit 1
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/kit/tools" "$stage/kit/contract/fixtures"
cp "$repo/tools/fake-app.php" "$repo/tools/feature-app.php" "$stage/kit/tools/"
cp "$repo/contract/fixtures/messages.json" "$stage/kit/contract/fixtures/"
cp "$repo/linux/tests/smoke-manifest.json" "$stage/kit/"
(cd "$here/publish/win-$arch" && zip -qr "$stage/host.zip" .)
(cd "$stage/kit" && zip -qr "$stage/kit.zip" .)
ssh "${ssh_opts[@]}" "$target" 'if not exist C:\tuiapp-vm mkdir C:\tuiapp-vm' > /dev/null
scp -q "${ssh_opts[@]}" "$stage/host.zip" "$target:C:/tuiapp-vm/host-$arch.zip"
scp -q "${ssh_opts[@]}" "$stage/kit.zip" "$target:C:/tuiapp-vm/kit.zip"

args=(-Arch "$arch")
[ -n "${TUIAPP_VM_AUTOLOAD:-}" ] && args+=(-Autoload "$TUIAPP_VM_AUTOLOAD")
[ -n "${TUIAPP_VM_PHP:-}" ] && args+=(-Php "$TUIAPP_VM_PHP")
set +e
"$here/vm/session1.sh" "$here/vm/features.ps1" "${args[@]}"
rc=$?
set -e
rm -rf -- "$here/vm/out/features"
mkdir -p "$here/vm/out"
scp -q -r "${ssh_opts[@]}" "$target:C:/tuiapp-vm/out/features" "$here/vm/out/" || true
exit "$rc"
