#!/bin/sh
set -eu
# Builds the pinned PHP embed SAPI with PCRE and OPcache JIT disabled.
target=${1:?Usage: build-runtime.sh TARGET OUTPUT}
out=${2:?Usage: build-runtime.sh TARGET OUTPUT}
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
version=8.5.13
case "$out" in /*) ;; *) out="$PWD/$out" ;; esac
mkdir -p "$out"
case "$target" in
 ios-arm64) sdk=iphoneos; triple=arm64-apple-ios18.0; host=aarch64-apple-darwin ;;
 ios-simulator-arm64) sdk=iphonesimulator; triple=arm64-apple-ios18.0-simulator; host=aarch64-apple-darwin ;;
 android-arm64)
  : "${ANDROID_NDK_HOME:?Set ANDROID_NDK_HOME to the Android NDK}"
  case "$(uname -s)" in Darwin) ndkhost=darwin-x86_64 ;; Linux) ndkhost=linux-x86_64 ;; *) echo 'Unsupported NDK build host' >&2; exit 1 ;; esac
  CC="$ANDROID_NDK_HOME/toolchains/llvm/prebuilt/$ndkhost/bin/aarch64-linux-android26-clang"
  AR="$ANDROID_NDK_HOME/toolchains/llvm/prebuilt/$ndkhost/bin/llvm-ar"
  RANLIB="$ANDROID_NDK_HOME/toolchains/llvm/prebuilt/$ndkhost/bin/llvm-ranlib"
  export AR RANLIB
  host=aarch64-linux-android ;;
 *) echo "Unsupported mobile target: $target" >&2; exit 1 ;;
esac
if [ "$target" != android-arm64 ]; then
 CC="$(xcrun --sdk "$sdk" --find clang) -isysroot $(xcrun --sdk "$sdk" --show-sdk-path) -target $triple"
fi
CFLAGS="${CFLAGS:--O2} -fPIC"
export CC CFLAGS
archive="$out/php-$version.tar.xz"
if [ ! -f "$archive" ]; then
 curl -fsSL --retry 3 "https://www.php.net/distributions/php-$version.tar.xz" -o "$archive"
fi
expected=$(awk -v name="php-$version.tar.xz" '$2 == name {print $1}' "$root/runtime/php-sources.lock")
actual=$(shasum -a 256 "$archive" | awk '{print $1}')
[ -n "$expected" ] && [ "$actual" = "$expected" ] || { echo 'PHP source checksum mismatch' >&2; exit 1; }
[ -d "$out/php-$version" ] || tar -xf "$archive" -C "$out"
if [ "$target" = android-arm64 ] && ! grep -q 'long limit = sysconf(_SC_OPEN_MAX)' "$out/php-$version/ext/standard/php_fopen_wrapper.c"; then
 patch -d "$out/php-$version" -p1 < "$root/mobile/php-android.patch"
fi
TUI_MOBILE_TARGET="$target" TUI_MOBILE_HOST="$host" "$root/mobile/build-dependencies.sh" "$out/dependencies"
if [ "$target" = android-arm64 ]; then
 LIBXML_CFLAGS="-I$out/dependencies/prefix/include/libxml2"
 LIBXML_LIBS="$out/dependencies/prefix/lib/libxml2.a -lm"
 iconv="$out/dependencies/prefix"
else
 LIBXML_CFLAGS="-I$(xcrun --sdk "$sdk" --show-sdk-path)/usr/include/libxml2"
 LIBXML_LIBS="-lxml2"
 iconv="$(xcrun --sdk "$sdk" --show-sdk-path)/usr"
fi
export LIBXML_CFLAGS LIBXML_LIBS
mkdir -p "$out/build"
cd "$out/build"
"../php-$version/configure" --host="$host" --disable-all --disable-cli --disable-cgi --disable-phpdbg --without-pcre-jit --disable-opcache-jit --enable-embed=static --enable-ctype --enable-filter --enable-mbstring --disable-mbregex --enable-tokenizer --enable-phar --with-libxml --enable-dom --with-iconv="$iconv"
if [ "$target" = android-arm64 ]; then
 # Bionic exports private resolver symbols which its public NDK headers omit.
 sed -e 's/^#define HAVE_RES_SEARCH 1$/\/\* #undef HAVE_RES_SEARCH *\//' -e 's/^#define HAVE_RES_NSEARCH 1$/\/\* #undef HAVE_RES_NSEARCH *\//' -e 's/^#define HAVE_GETDTABLESIZE 1$/\/\* #undef HAVE_GETDTABLESIZE *\//' -e 's/^#define HAVE_DN_SKIPNAME 1$/\/\* #undef HAVE_DN_SKIPNAME *\//' main/php_config.h > main/php_config.h.mobile
 mv main/php_config.h.mobile main/php_config.h
fi
if [ "$target" != android-arm64 ]; then
 # The simulator SDK exports desktop process APIs unavailable to iOS apps.
 sed -e 's/^#define HAVE_FORK 1$/\/\* #undef HAVE_FORK *\//' -e 's/^#define HAVE_POSIX_SPAWN_FILE_ACTIONS_ADDCHDIR 1$/\/\* #undef HAVE_POSIX_SPAWN_FILE_ACTIONS_ADDCHDIR *\//' -e 's/^#define HAVE_POSIX_SPAWN_FILE_ACTIONS_ADDCHDIR_NP 1$/\/\* #undef HAVE_POSIX_SPAWN_FILE_ACTIONS_ADDCHDIR_NP *\//' -e 's/^#define PHP_CAN_SUPPORT_PROC_OPEN 1$/\/\* #undef PHP_CAN_SUPPORT_PROC_OPEN *\//' main/php_config.h > main/php_config.h.mobile
 mv main/php_config.h.mobile main/php_config.h
fi
make -j "${TUI_APP_BUILD_JOBS:-4}" libphp.la
