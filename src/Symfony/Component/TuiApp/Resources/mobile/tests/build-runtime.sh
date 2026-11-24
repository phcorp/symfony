#!/bin/sh
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM
if "$root/build-runtime.sh" invalid "$work" > "$work/log" 2>&1; then
 echo 'Invalid target was accepted' >&2; exit 1
fi
grep -q 'Unsupported mobile target' "$work/log"
if (unset ANDROID_NDK_HOME; "$root/build-runtime.sh" android-arm64 "$work") > "$work/log" 2>&1; then
 echo 'Missing NDK was accepted' >&2; exit 1
fi
grep -q 'Set ANDROID_NDK_HOME' "$work/log"
echo 'Mobile build argument checks passed'
