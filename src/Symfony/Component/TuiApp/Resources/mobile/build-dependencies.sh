#!/bin/sh
set -eu
: "${CC:?Set CC to the target compiler}"
: "${TUI_MOBILE_TARGET:?Set TUI_MOBILE_TARGET}"
: "${TUI_MOBILE_HOST:?Set TUI_MOBILE_HOST}"
out=${1:?Dependency output directory}
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
mkdir -p "$out/sources" "$out/prefix"
prefix="$out/prefix"
fetch() {
 name=$1; url=$2
 if [ ! -f "$out/sources/$name" ]; then curl -fsSL --retry 3 "$url" -o "$out/sources/$name"; fi
 expected=$(awk -v name="$name" '$2 == name {print $1}' "$root/runtime/php-sources.lock")
 actual=$(shasum -a 256 "$out/sources/$name" | awk '{print $1}')
 [ -n "$expected" ] && [ "$expected" = "$actual" ] || { echo "Checksum mismatch: $name" >&2; exit 1; }
}
if [ "$TUI_MOBILE_TARGET" = android-arm64 ]; then
 fetch libiconv-1.19.1.tar.gz https://ftp.gnu.org/gnu/libiconv/libiconv-1.19.1.tar.gz
 [ -d "$out/sources/libiconv-1.19.1" ] || tar -xf "$out/sources/libiconv-1.19.1.tar.gz" -C "$out/sources"
 if [ ! -f "$prefix/lib/libiconv.a" ]; then
  mkdir -p "$out/iconv-build"
  (cd "$out/iconv-build" && ../sources/libiconv-1.19.1/configure --host="$TUI_MOBILE_HOST" --prefix="$prefix" --disable-shared --enable-static --disable-nls && make -j "${TUI_APP_BUILD_JOBS:-4}" && make install)
 fi
 fetch v2.15.4.tar.gz https://github.com/GNOME/libxml2/archive/refs/tags/v2.15.4.tar.gz
 [ -d "$out/sources/libxml2-2.15.4" ] || tar -xf "$out/sources/v2.15.4.tar.gz" -C "$out/sources"
 if [ ! -f "$prefix/lib/libxml2.a" ]; then
  cmake -S "$out/sources/libxml2-2.15.4" -B "$out/xml-build" \
   -DCMAKE_TOOLCHAIN_FILE="$ANDROID_NDK_HOME/build/cmake/android.toolchain.cmake" \
   -DANDROID_ABI=arm64-v8a -DANDROID_PLATFORM=android-26 -DCMAKE_INSTALL_PREFIX="$prefix" \
   -DCMAKE_BUILD_TYPE=Release -DCMAKE_POSITION_INDEPENDENT_CODE=ON -DBUILD_SHARED_LIBS=OFF \
   -DLIBXML2_WITH_PYTHON=OFF -DLIBXML2_WITH_PROGRAMS=OFF -DLIBXML2_WITH_TESTS=OFF \
   -DLIBXML2_WITH_ICONV=OFF -DLIBXML2_WITH_ZLIB=OFF -DLIBXML2_WITH_LZMA=OFF
  cmake --build "$out/xml-build" -j "${TUI_APP_BUILD_JOBS:-4}"
  cmake --install "$out/xml-build"
 fi
fi
