#!/bin/sh
set -eu
target=${1:?Usage: build-probe.sh TARGET PHP_SOURCE PHP_BUILD OUTPUT}
source=${2:?PHP source directory}
build=${3:?PHP build directory}
out=${4:?Output directory}
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
mkdir -p "$out"
case "$target" in
 ios-simulator-arm64|ios-arm64)
  sdk=iphonesimulator; triple=arm64-apple-ios18.0-simulator
  if [ "$target" = ios-arm64 ]; then sdk=iphoneos; triple=arm64-apple-ios18.0; fi
  app="$out/RuntimeProbe.app"
  mkdir -p "$app"
  xcrun --sdk "$sdk" clang -target "$triple" -isysroot "$(xcrun --sdk "$sdk" --show-sdk-path)" \
   -I"$root/tests" -I"$source" -I"$source/main" -I"$source/Zend" -I"$source/TSRM" -I"$source/sapi/embed" \
   -I"$build" -I"$build/main" -I"$build/Zend" \
   "$root/runtime.c" "${TUI_APP_PROBE_SOURCE:-$root/tests/probe.c}" "$root/tests/ProbeApp.m" "$build/libs/libphp.a" \
   -framework UIKit -framework Foundation -lresolv -liconv -lxml2 -o "$app/RuntimeProbe"
  cp "$root/tests/probe.php" "$app/probe.php"
  cat > "$app/Info.plist" <<'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
<key>CFBundleIdentifier</key><string>org.symfony.tui.runtimeprobe</string>
<key>CFBundleExecutable</key><string>RuntimeProbe</string>
<key>CFBundleName</key><string>RuntimeProbe</string>
<key>CFBundlePackageType</key><string>APPL</string>
<key>CFBundleVersion</key><string>1</string>
<key>CFBundleShortVersionString</key><string>1.0</string>
<key>MinimumOSVersion</key><string>18.0</string>
<key>UILaunchScreen</key><dict/>
<key>UIApplicationSceneManifest</key><dict><key>UIApplicationSupportsMultipleScenes</key><false/></dict>
<key>UIDeviceFamily</key><array><integer>1</integer><integer>2</integer></array>
</dict></plist>
PLIST
  codesign --force --sign - "$app"
  echo "$app" ;;
 android-arm64)
  : "${ANDROID_NDK_HOME:?Set ANDROID_NDK_HOME}"
  case "$(uname -s)" in Darwin) ndkhost=darwin-x86_64 ;; Linux) ndkhost=linux-x86_64 ;; *) exit 1 ;; esac
  "$ANDROID_NDK_HOME/toolchains/llvm/prebuilt/$ndkhost/bin/aarch64-linux-android26-clang" \
   -DTUI_PROBE_MAIN -I"$root/tests" -I"$source" -I"$source/main" -I"$source/Zend" -I"$source/TSRM" -I"$source/sapi/embed" \
   -I"$build" -I"$build/main" -I"$build/Zend" \
   "$root/runtime.c" "${TUI_APP_PROBE_SOURCE:-$root/tests/probe.c}" "$build/libs/libphp.a" "$build/../dependencies/prefix/lib/libxml2.a" "$build/../dependencies/prefix/lib/libiconv.a" -lm -ldl -o "$out/runtime-probe"
  echo "$out/runtime-probe" ;;
 *) echo "Unsupported probe target: $target" >&2; exit 1 ;;
esac
