#!/usr/bin/env bash
# A real TuiApp application end to end in the Windows VM, from the Mac: vm/link-app.php, whose page is one link, run
# by the host in the user's session; a right click on the link through Windows, Copy Link Address and Open Link chosen
# in the host's context menu, the clipboard and what the application followed checked (vm/link.ps1).
#
# The application needs the TuiApp component: vm/link-kit.php makes a kit of what it uses from a checkout
# (TUIAPP_CHECKOUT, default the containing Git checkout, with its vendor), checks the kit alone runs it here, and that kit
# goes to the guest, not the checkout.
#
# Usage: vm/link.sh [arm64|x64]   (publish first: dotnet publish App -c Release -r win-<arch> --self-contained -o publish/win-<arch>)
# Needs php here and in the guest (on its PATH, or TUIAPP_VM_PHP). See vm/session1.sh for the VM.
# Results: vm/out/link/ (link.txt, the host's and the application's logs).
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
arch="${1:-arm64}"
checkout="${TUIAPP_CHECKOUT:-$(git -C "$here" rev-parse --show-toplevel)}"
target="${TUIAPP_VM_SSH:-user@192.168.64.3}"
key="${TUIAPP_VM_KEY:-$HOME/.ssh/utm_windows}"
ssh_opts=(-i "$key" -o IdentitiesOnly=yes -o ConnectTimeout=15 -o ServerAliveInterval=30)
[ -f "$here/publish/win-$arch/TuiAppHost.exe" ] || { echo "publish win-$arch first" >&2; exit 1; }
[ -f "$checkout/vendor/autoload.php" ] || { echo "no $checkout/vendor/autoload.php: TUIAPP_CHECKOUT names a checkout with the TuiApp component and its vendor" >&2; exit 1; }

stage="$(mktemp -d "${TMPDIR:-/tmp}/tuiapp-link.XXXXXX")"
[ -n "$stage" ] && [ -d "$stage" ] || exit 1
trap 'rm -rf -- "$stage"' EXIT
php "$here/vm/link-kit.php" "$checkout" "$stage/kit/kit"
php "$here/vm/link-kit.php" --verify "$stage/kit/kit/autoload.php"
cp "$here/vm/link-app.php" "$stage/kit/"
(cd "$here/publish/win-$arch" && zip -qr "$stage/host.zip" .)
(cd "$stage/kit" && zip -qr "$stage/link-kit.zip" .)
ssh "${ssh_opts[@]}" "$target" 'if not exist C:\tuiapp-vm mkdir C:\tuiapp-vm' > /dev/null
scp -q "${ssh_opts[@]}" "$stage/host.zip" "$target:C:/tuiapp-vm/host-$arch.zip"
scp -q "${ssh_opts[@]}" "$stage/link-kit.zip" "$target:C:/tuiapp-vm/link-kit.zip"

args=(-Arch "$arch")
[ -n "${TUIAPP_VM_PHP:-}" ] && args+=(-Php "$TUIAPP_VM_PHP")
set +e
"$here/vm/session1.sh" "$here/vm/link.ps1" "${args[@]}"
rc=$?
set -e
rm -rf -- "$here/vm/out/link"
mkdir -p "$here/vm/out"
scp -q -r "${ssh_opts[@]}" "$target:C:/tuiapp-vm/out/link" "$here/vm/out/" || true
exit "$rc"
