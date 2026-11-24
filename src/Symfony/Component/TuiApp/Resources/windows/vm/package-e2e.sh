#!/usr/bin/env bash
# The packages tui-app build made for Windows, end to end in the Windows VM, from the Mac: the portable
# .zip unzipped and self-tested; the setup .exe installed silently, the installed app self-tested, its
# file associations, URL schemes, uninstall entry and Start menu shortcut checked; then uninstalled, and
# all of them checked gone. The self-tests run the application of the package (its manifest, its PHP).
#
# Usage: vm/package-e2e.sh '<guest folder>'   e.g. 'C:\src\demo\build\app\windows-arm64', with one
# *-setup.exe and one .zip, built in the guest (Inno Setup runs on Windows). See vm/session1.sh for the VM.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
[ $# -eq 1 ] || { echo "usage: $0 '<guest folder with the setup .exe and the .zip>'" >&2; exit 2; }
# It goes into a PowerShell string and a scheduled task's command line (session1.sh checks it too)
case "$1" in '' | *[!A-Za-z0-9_.:\\/-]*) echo 'a folder of letters, digits and _ . : \ / - only (no spaces or quotes), please' >&2; exit 2 ;; esac
exec "$here/session1.sh" "$here/package-e2e.ps1" -Packages "$1"
