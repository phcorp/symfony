#!/bin/sh
set -eu
target=ios-arm64
identity=-
profile=
icon=
while [ $# -gt 3 ]; do
 case "$1" in --target) target=$2; shift 2 ;; --sign) identity=$2; shift 2 ;; --profile) profile=$2; shift 2 ;; --icon) icon=$2; shift 2 ;; *) echo "Unknown option: $1" >&2; exit 1 ;; esac
done
manifest=${1:?Usage: build-app.sh [--target TARGET] MANIFEST PAYLOAD OUTPUT}
payload=${2:?Payload directory}
out=${3:?Output directory}
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
case "$out" in /*) ;; *) out="$PWD/$out" ;; esac
case "$target" in ios-arm64) sdk=iphoneos; triple=arm64-apple-ios18.0 ;; ios-simulator-arm64) sdk=iphonesimulator; triple=arm64-apple-ios18.0-simulator ;; *) echo "Unsupported iOS target: $target" >&2; exit 1 ;; esac
runtime="$payload/runtime"
source="$runtime/php-8.5.13"
build="$runtime/build"
app="$out/Application.app"
mkdir -p "$out"
rm -rf "$app"
mkdir -p "$app"
python3 "$root/mobile/prepare.py" "$manifest" "$payload" "$app"
if [ "${TUI_APP_HOST_TESTS:-0}" = 1 ]; then cp -R "$root/contract/fixtures" "$app/fixtures"; fi
xcrun --sdk "$sdk" clang -target "$triple" -isysroot "$(xcrun --sdk "$sdk" --show-sdk-path)" \
 -I"$source" -I"$source/main" -I"$source/Zend" -I"$source/TSRM" -I"$source/sapi/embed" -I"$build" -I"$build/main" -I"$build/Zend" \
 -c "$root/mobile/runtime.c" -o "$out/runtime.o"
xcrun --sdk "$sdk" swiftc -swift-version 5 -target "$triple" -sdk "$(xcrun --sdk "$sdk" --show-sdk-path)" \
 -import-objc-header "$root/mobile/runtime.h" \
 "$root"/macos/Sources/TuiAppHostCore/*.swift "$root"/ios/Sources/*.swift "$out/runtime.o" "$build/libs/libphp.a" \
 -framework UIKit -framework Foundation -framework Network -lresolv -liconv -lxml2 -o "$app/TuiApp"
if [ -n "$icon" ]; then
 "$root/mobile/icon.sh" "$icon" "$out/icon.png"
 for entry in '120:AppIcon60x60@2x' '180:AppIcon60x60@3x' '152:AppIcon76x76@2x' '167:AppIcon83.5x83.5@2x'; do
  size=${entry%%:*}; name=${entry#*:}
  sips -z "$size" "$size" "$out/icon.png" --out "$app/$name.png" >/dev/null
 done
fi
python3 - "$manifest" "$app/Info.plist" "$icon" <<'PY'
import json,plistlib,sys
app=json.load(open(sys.argv[1]))['app']
plist={'CFBundleIdentifier':app['id'],'CFBundleExecutable':'TuiApp','CFBundleName':app['name'],'CFBundleDisplayName':app['name'],'CFBundlePackageType':'APPL','CFBundleVersion':'1','CFBundleShortVersionString':app['version'].split('-')[0].split('+')[0],'MinimumOSVersion':'18.0','UILaunchScreen':{},'UIApplicationSceneManifest':{'UIApplicationSupportsMultipleScenes':False},'UIDeviceFamily':[1,2],'UISupportedInterfaceOrientations':['UIInterfaceOrientationPortrait','UIInterfaceOrientationLandscapeLeft','UIInterfaceOrientationLandscapeRight']}
if sys.argv[3]:
 plist['CFBundleIcons']={'CFBundlePrimaryIcon':{'CFBundleIconFiles':['AppIcon60x60']}}
 plist['CFBundleIcons~ipad']={'CFBundlePrimaryIcon':{'CFBundleIconFiles':['AppIcon60x60','AppIcon76x76','AppIcon83.5x83.5']}}
with open(sys.argv[2],'wb') as output:plistlib.dump(plist,output)
PY
if [ -n "$profile" ]; then
 cp "$profile" "$app/embedded.mobileprovision"
 security cms -D -i "$profile" > "$out/profile.plist"
 python3 "$root/ios/signing.py" "$out/profile.plist" --app "$app/Info.plist" --output "$out/entitlements.plist"
 codesign --force --sign "$identity" --entitlements "$out/entitlements.plist" "$app"
 codesign --display --extract-certificates "$out/signing-cert-" "$app"
 python3 "$root/ios/signing.py" "$out/profile.plist" --certificate "$out/signing-cert-0"

else
 codesign --force --sign "$identity" "$app"
fi
echo "$app"
