#!/bin/sh
set -eu
icon=
while [ $# -gt 3 ]; do
 case "$1" in --icon) icon=$2; shift 2 ;; *) echo "Unknown option: $1" >&2; exit 1 ;; esac
done
manifest=${1:?Usage: build-app.sh MANIFEST PAYLOAD OUTPUT}
payload=${2:?Payload directory}
out=${3:?Output directory}
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
: "${ANDROID_HOME:?Set ANDROID_HOME to the Android SDK}"
: "${ANDROID_NDK_HOME:?Set ANDROID_NDK_HOME to the Android NDK}"
case "$out" in /*) ;; *) out="$PWD/$out" ;; esac
runtime="$payload/runtime"
source="$runtime/php-8.5.13"
build="$runtime/build"
tools="$ANDROID_HOME/build-tools/${TUI_APP_ANDROID_BUILD_TOOLS:-35.0.0}"
android="$ANDROID_HOME/platforms/android-${TUI_APP_ANDROID_API:-34}/android.jar"
case "$(uname -s)" in Darwin) ndkhost=darwin-x86_64 ;; Linux) ndkhost=linux-x86_64 ;; *) echo 'Android packaging needs macOS or Linux' >&2; exit 1 ;; esac
work="$out/.android"
rm -rf "$work"
mkdir -p "$work/classes" "$work/dex" "$work/assets" "$work/lib/arm64-v8a"
python3 "$root/mobile/prepare.py" "$manifest" "$payload" "$work/assets"
if [ "${TUI_APP_HOST_TESTS:-0}" = 1 ]; then cp -R "$root/contract/fixtures" "$work/assets/fixtures"; fi
"$ANDROID_NDK_HOME/toolchains/llvm/prebuilt/$ndkhost/bin/aarch64-linux-android26-clang" -shared -fPIC -Wl,-Bsymbolic \
 -I"$source" -I"$source/main" -I"$source/Zend" -I"$source/TSRM" -I"$source/sapi/embed" -I"$build" -I"$build/main" -I"$build/Zend" \
 "$root/mobile/runtime.c" "$root/android/runtime-jni.c" "$build/libs/libphp.a" "$runtime/dependencies/prefix/lib/libxml2.a" "$runtime/dependencies/prefix/lib/libiconv.a" -lm -ldl -o "$work/lib/arm64-v8a/libtuiapp.so"
find "$root/android/src" -name '*.java' > "$work/java-sources"
if [ "${TUI_APP_HOST_TESTS:-0}" = 1 ]; then find "$root/android/tests" -name '*.java' >> "$work/java-sources"; fi
javac -source 8 -target 8 -classpath "$android" -d "$work/classes" @"$work/java-sources"
find "$work/classes" -name '*.class' > "$work/class-files"
"$tools/d8" --lib "$android" --min-api 26 --output "$work/dex" @"$work/class-files"
if [ -n "$icon" ]; then
 mkdir -p "$work/res/mipmap"
 "$root/mobile/icon.sh" "$icon" "$work/res/mipmap/app_icon.png"
 "$tools/aapt2" compile --dir "$work/res" -o "$work/compiled-res.zip"
fi
python3 - "$manifest" "$work/AndroidManifest.xml" "$icon" "${TUI_APP_ANDROID_API:-34}" <<'PY'
import json,re,sys,xml.etree.ElementTree as ET
app=json.load(open(sys.argv[1]))['app']
if not re.fullmatch(r'[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+', app['id']):
 raise SystemExit('Android app.id must have dot-separated Java identifiers (letters, digits and underscores).')
ns='http://schemas.android.com/apk/res/android'
ET.register_namespace('android',ns)
def attr(**kw): return {'{'+ns+'}'+key:str(value) for key,value in kw.items()}
manifest=ET.Element('manifest',dict(package=app['id'],**attr(versionCode=1,versionName=app['version'])))
ET.SubElement(manifest,'uses-sdk',attr(minSdkVersion=26,targetSdkVersion=int(sys.argv[4])))
ET.SubElement(manifest,'uses-permission',attr(name='android.permission.INTERNET'))
application=ET.SubElement(manifest,'application',attr(label=app['name'],debuggable='true',extractNativeLibs='true'))
if sys.argv[3]:application.set('{'+ns+'}icon','@mipmap/app_icon')
activity=ET.SubElement(application,'activity',attr(name='org.symfony.tui.HostActivity',exported='true',launchMode='singleTask',configChanges='orientation|screenSize|keyboardHidden|uiMode',windowSoftInputMode='adjustResize'))
intent=ET.SubElement(activity,'intent-filter')
ET.SubElement(intent,'action',attr(name='android.intent.action.MAIN'))
ET.SubElement(intent,'category',attr(name='android.intent.category.LAUNCHER'))
ET.ElementTree(manifest).write(sys.argv[2],encoding='utf-8',xml_declaration=True)
PY
if [ -n "$icon" ]; then
 "$tools/aapt2" link --manifest "$work/AndroidManifest.xml" -I "$android" -A "$work/assets" -R "$work/compiled-res.zip" -o "$work/unsigned.apk"
else
 "$tools/aapt2" link --manifest "$work/AndroidManifest.xml" -I "$android" -A "$work/assets" -o "$work/unsigned.apk"
fi
(cd "$work/dex" && zip -q "$work/unsigned.apk" classes.dex)
(cd "$work" && zip -q unsigned.apk lib/arm64-v8a/libtuiapp.so)
"$tools/zipalign" -f 4 "$work/unsigned.apk" "$work/aligned.apk"
keystore="${TUI_APP_ANDROID_DEBUG_KEYSTORE:-${XDG_CACHE_HOME:-$HOME/.cache}/tui-app/signing/android-debug.keystore}"
if [ ! -f "$keystore" ]; then
 mkdir -p "$(dirname "$keystore")"
 keywork=$(mktemp -d "$(dirname "$keystore")/.key.XXXXXX")
 (umask 077; keytool -genkeypair -keystore "$keywork/debug.keystore" -storepass android -keypass android -alias androiddebugkey -dname 'CN=Android Debug' -keyalg RSA -validity 3650 >/dev/null 2>&1)
 ln "$keywork/debug.keystore" "$keystore" 2>/dev/null || [ -f "$keystore" ]
 rm -rf "$keywork"
fi
"$tools/apksigner" sign --ks "$keystore" --ks-pass pass:android --key-pass pass:android --out "$out/application.apk" "$work/aligned.apk"
echo "$out/application.apk"
