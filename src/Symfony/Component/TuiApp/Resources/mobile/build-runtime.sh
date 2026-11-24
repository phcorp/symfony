#!/bin/sh
set -eu
target=${1:?Usage: build-runtime.sh TARGET OUTPUT}
out=${2:?Output directory}
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
case "$target" in
 ios-arm64) toolchain=$(xcrun --sdk iphoneos --show-sdk-version) ;;
 ios-simulator-arm64) toolchain=$(xcrun --sdk iphonesimulator --show-sdk-version) ;;
 android-arm64) : "${ANDROID_NDK_HOME:?Set ANDROID_NDK_HOME to the Android NDK}"; toolchain=$(cat "$ANDROID_NDK_HOME/source.properties") ;;
 *) echo "Unsupported mobile target: $target" >&2; exit 1 ;;
esac
case "$out" in /*) ;; *) out="$PWD/$out" ;; esac
key=$({ cat "$root/mobile/compile-runtime.sh" "$root/mobile/build-dependencies.sh" "$root/mobile/php-android.patch" "$root/runtime/php-sources.lock"; printf '%s\n' "$target" "$toolchain" "$(uname -sm)" "${CFLAGS:-}"; } | shasum -a 256 | awk '{print $1}')
cache="${TUI_APP_MOBILE_CACHE:-${XDG_CACHE_HOME:-$HOME/.cache}/tui-app/mobile}/$key"
mkdir -p "$(dirname "$cache")"
if [ ! -f "$cache/.ready" ]; then
 waited=0
 locked=0
 while :; do
  if mkdir "$cache.lock" 2>/dev/null; then locked=1; break; fi
  if [ -f "$cache/.ready" ]; then break; fi
  waited=$((waited+1))
  if [ "$waited" -ge 1800 ]; then echo "Mobile runtime cache lock timed out: $cache.lock" >&2; exit 1; fi
  sleep 1
 done
 if [ "$locked" = 1 ]; then
  trap 'rmdir "$cache.lock" 2>/dev/null || true' EXIT
  trap 'exit 1' HUP INT TERM
  if [ ! -f "$cache/.ready" ]; then
   "$root/mobile/compile-runtime.sh" "$target" "$cache"
   test -s "$cache/build/libs/libphp.a"
   printf '%s\n' "$target" > "$cache/.ready"
  fi
  rmdir "$cache.lock"
  trap - EXIT HUP INT TERM
 fi
fi
mkdir -p "$out"
# Keep headers and dependencies for linking the host, outside its bundled app payload.
for directory in php-8.5.13 build dependencies; do
 if [ -e "$out/$directory" ]; then rm -rf "${out:?}/${directory:?}"; fi
 cp -R "$cache/$directory" "$out/$directory"
done
