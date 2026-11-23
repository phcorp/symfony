#!/bin/sh
set -eu
source=${1:?PHP source directory}
build=${2:?PHP build directory}
out=${3:?Output directory}
: "${ANDROID_HOME:?Set ANDROID_HOME}"
: "${ANDROID_NDK_HOME:?Set ANDROID_NDK_HOME}"
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
case "$(uname -s)" in Darwin) ndkhost=darwin-x86_64 ;; Linux) ndkhost=linux-x86_64 ;; *) exit 1 ;; esac
tools="$ANDROID_HOME/build-tools/35.0.0"
android="$ANDROID_HOME/platforms/android-34/android.jar"
mkdir -p "$out/classes" "$out/dex" "$out/assets" "$out/lib/arm64-v8a"
"$ANDROID_NDK_HOME/toolchains/llvm/prebuilt/$ndkhost/bin/aarch64-linux-android26-clang" -shared -fPIC -Wl,-Bsymbolic \
 -I"$root/tests" -I"$source" -I"$source/main" -I"$source/Zend" -I"$source/TSRM" -I"$source/sapi/embed" \
 -I"$build" -I"$build/main" -I"$build/Zend" \
 "$root/runtime.c" "${TUI_APP_PROBE_SOURCE:-$root/tests/probe.c}" "$root/tests/probe-jni.c" "$build/libs/libphp.a" "$build/../dependencies/prefix/lib/libxml2.a" "$build/../dependencies/prefix/lib/libiconv.a" -lm -ldl -o "$out/lib/arm64-v8a/libtuiprobe.so"
javac -source 8 -target 8 -classpath "$android" -d "$out/classes" "$root/tests/ProbeActivity.java"
"$tools/d8" --lib "$android" --output "$out/dex" "$out/classes/org/symfony/tui/runtimeprobe/ProbeActivity.class"
cp "$root/tests/probe.php" "$out/assets/probe.php"
cat > "$out/AndroidManifest.xml" <<'MANIFEST'
<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="org.symfony.tui.runtimeprobe" android:versionCode="1" android:versionName="1.0">
<uses-sdk android:minSdkVersion="26" android:targetSdkVersion="34"/>
<uses-permission android:name="android.permission.INTERNET"/>
<application android:label="Tui Runtime Probe" android:debuggable="true" android:extractNativeLibs="true">
<activity android:name=".ProbeActivity" android:exported="true"><intent-filter><action android:name="android.intent.action.MAIN"/><category android:name="android.intent.category.LAUNCHER"/></intent-filter></activity>
</application></manifest>
MANIFEST
"$tools/aapt2" link --manifest "$out/AndroidManifest.xml" -I "$android" -A "$out/assets" -o "$out/unsigned.apk"
(cd "$out/dex" && zip -q "$out/unsigned.apk" classes.dex)
(cd "$out" && zip -q unsigned.apk lib/arm64-v8a/libtuiprobe.so)
"$tools/zipalign" -f 4 "$out/unsigned.apk" "$out/aligned.apk"
if [ ! -f "$out/debug.keystore" ]; then
 keytool -genkeypair -keystore "$out/debug.keystore" -storepass android -keypass android -alias androiddebugkey -dname 'CN=Android Debug' -keyalg RSA -validity 30 >/dev/null 2>&1
fi
"$tools/apksigner" sign --ks "$out/debug.keystore" --ks-pass pass:android --key-pass pass:android --out "$out/RuntimeProbe.apk" "$out/aligned.apk"
echo "$out/RuntimeProbe.apk"
