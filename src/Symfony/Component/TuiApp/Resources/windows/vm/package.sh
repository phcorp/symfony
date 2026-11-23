#!/usr/bin/env bash
# Builds a Windows package in the VM with package.ps1, from the Mac, and runs vm/package-e2e.sh on it: the stand-in
# application (tools/fake-app.php) with the smoke manifest (linux/tests/smoke-manifest.json), the host published for
# the architecture given only (-Arch), as an installer and a portable .zip; then installed, self-tested, its
# registrations checked, uninstalled. x64 on Windows on Arm: installed and run emulated.
#
# Usage: vm/package.sh [arm64|x64]   (publish first: dotnet publish App -c Release -r win-<arch> --self-contained -o publish/win-<arch>)
# Needs Inno Setup 6 in the guest (ISCC.exe, as package.ps1 finds it) and php on its PATH. See vm/session1.sh for the VM.
# Results: the packages in C:\tuiapp-vm\pkg-<arch> in the guest, vm/out/package-<arch>.txt (package.ps1's output).
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
repo="$(cd "$here/.." && pwd)"
arch="${1:-arm64}"
case "$arch" in arm64 | x64) ;; *) echo "usage: $0 [arm64|x64]" >&2; exit 2 ;; esac
target="${TUIAPP_VM_SSH:-user@192.168.64.3}"
key="${TUIAPP_VM_KEY:-$HOME/.ssh/utm_windows}"
ssh_opts=(-i "$key" -o IdentitiesOnly=yes -o ConnectTimeout=15 -o ServerAliveInterval=30)
[ -f "$here/publish/win-$arch/TuiAppHost.exe" ] || { echo "publish win-$arch first" >&2; exit 1; }

stage="$(mktemp -d "${TMPDIR:-/tmp}/tuiapp-package.XXXXXX")"
[ -n "$stage" ] && [ -d "$stage" ] || exit 1
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/kit/publish/win-$arch" "$stage/kit/payload/tools" "$stage/kit/payload/contract/fixtures"
cp "$here/package.ps1" "$stage/kit/"
cp -R "$here/Installer" "$stage/kit/"
cp -R "$here/publish/win-$arch/." "$stage/kit/publish/win-$arch/"
cp "$repo/linux/tests/smoke-manifest.json" "$stage/kit/app.manifest.json"
# The stand-in application and the contract's messages it sends
cp "$repo/tools/fake-app.php" "$stage/kit/payload/tools/"
cp "$repo/contract/fixtures/messages.json" "$stage/kit/payload/contract/fixtures/"
(cd "$stage/kit" && zip -qr "$stage/package-kit.zip" .)
ssh "${ssh_opts[@]}" "$target" 'if not exist C:\tuiapp-vm mkdir C:\tuiapp-vm' > /dev/null
scp -q "${ssh_opts[@]}" "$stage/package-kit.zip" "$target:C:/tuiapp-vm/package-kit-$arch.zip"

# Built over ssh (Inno Setup needs no window), into a folder of its own
build="\$ErrorActionPreference = 'Stop'
\$ProgressPreference = 'SilentlyContinue'
\$work = 'C:\\tuiapp-vm\\package-$arch'
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue \$work, 'C:\\tuiapp-vm\\pkg-$arch'
Expand-Archive 'C:\\tuiapp-vm\\package-kit-$arch.zip' \$work
& powershell -NoProfile -ExecutionPolicy Bypass -File \"\$work\\package.ps1\" -Manifest \"\$work\\app.manifest.json\" -Payload \"\$work\\payload\" -Out 'C:\\tuiapp-vm\\pkg-$arch' -Arch $arch -Formats 'exe,zip'
exit \$LASTEXITCODE"
mkdir -p "$here/vm/out"
set +e
ssh "${ssh_opts[@]}" "$target" "powershell -NoProfile -NonInteractive -EncodedCommand $(printf '%s' "$build" | iconv -t UTF-16LE | base64 | tr -d '\n')" 2>&1 | tr -d '\r' | tee "$here/vm/out/package-$arch.txt"
rc=${PIPESTATUS[0]}
set -e
[ "$rc" -eq 0 ] || { echo "package: package.ps1 failed in the guest ($rc)" >&2; exit 1; }
exec "$here/vm/package-e2e.sh" "C:\\tuiapp-vm\\pkg-$arch"
