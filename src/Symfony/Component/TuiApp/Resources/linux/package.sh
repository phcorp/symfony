#!/bin/sh
# Packages a TuiApp application with the Linux host:
#   package.sh [--icon <file>] [--arch <x86_64|arm64>] [--formats <csv of tar,deb,rpm>] <manifest> <payload-dir> <out-dir>
# The payload goes to share/<id>/ next to the manifest (share/<id>/app.manifest.json),
# so the manifest's relative paths resolve against it. The host is bin/<id>.
#   --icon     a PNG (256 px or more) or an SVG, installed into hicolor as <id>; without it, a drawn letter.
#   --arch     the target architecture; it must be this machine's (no cross-building).
#   --formats  tar (<id>-<version>-linux-<arch>.tar.gz), deb, rpm; all by default. deb and rpm need nfpm.
# stdout lists the packages written, one path per line; everything else goes to stderr.
# TUI_HOST_BUILD=<meson build dir> reuses a built host instead of building one.
set -eu

usage() {
    echo 'usage: package.sh [--icon <file>] [--arch <x86_64|arm64>] [--formats <tar,deb,rpm>] <manifest> <payload-dir> <out-dir>' >&2
    exit 2
}
fail() {
    echo "package: $*" >&2
    exit 1
}

icon=
arch=
formats=tar,deb,rpm
while [ $# -gt 0 ]; do
    case $1 in
        --icon) [ $# -ge 2 ] || usage; icon=$2; shift 2 ;;
        --arch) [ $# -ge 2 ] || usage; arch=$2; shift 2 ;;
        --formats) [ $# -ge 2 ] || usage; formats=$2; shift 2 ;;
        --) shift; break ;;
        -*) echo "package: unknown option $1" >&2; usage ;;
        *) break ;;
    esac
done
[ $# -eq 3 ] || usage
manifest=$1
payload=$2
out=$3
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
[ -f "$manifest" ] || fail "no manifest at $manifest"
[ -d "$payload" ] || fail "no payload directory at $payload"

case $(uname -m) in
    x86_64 | amd64) machine=x86_64 ;;
    aarch64 | arm64) machine=arm64 ;;
    *) machine=$(uname -m) ;;
esac
case ${arch:-$machine} in
    x86_64 | arm64) ;;
    *) fail "--arch must be x86_64 or arm64, not ${arch}" ;;
esac
[ -z "$arch" ] || [ "$arch" = "$machine" ] || fail "--arch $arch does not match this machine ($(uname -m), that is $machine); build on a $arch machine, cross-building is not supported"
arch=$machine
case $arch in
    x86_64) pkg_arch=amd64 ;;
    arm64) pkg_arch=arm64 ;;
esac

want_tar=false
want_deb=false
want_rpm=false
for format in $(printf '%s' "$formats" | tr ',' ' '); do
    case $format in
        tar) want_tar=true ;;
        deb) want_deb=true ;;
        rpm) want_rpm=true ;;
        *) fail "unknown format $format (tar, deb, rpm)" ;;
    esac
done
$want_tar || $want_deb || $want_rpm || fail '--formats names no format'
if { $want_deb || $want_rpm; } && ! command -v nfpm > /dev/null 2>&1; then
    fail 'deb and rpm need nfpm (https://nfpm.goreleaser.com/install/), which is not installed'
fi

if [ -n "$icon" ]; then
    [ -f "$icon" ] || fail "no icon at $icon"
    case $icon in
        *.svg | *.SVG) icon_kind=svg ;;
        *.png | *.PNG)
            icon_kind=png
            [ "$(od -An -tx1 -N8 "$icon" | tr -d ' \n')" = 89504e470d0a1a0a ] || fail "$icon is not a PNG"
            # Width and height from the IHDR chunk: bytes 16-23, big-endian.
            icon_width=$(od -An -tu1 -j16 -N4 "$icon" | awk '{print $1*16777216 + $2*65536 + $3*256 + $4}')
            icon_height=$(od -An -tu1 -j20 -N4 "$icon" | awk '{print $1*16777216 + $2*65536 + $3*256 + $4}')
            [ "$icon_width" -ge 256 ] && [ "$icon_height" -ge 256 ] || fail "$icon is ${icon_width}x${icon_height}; the icon needs 256 px or more"
            [ "$icon_width" -eq "$icon_height" ] || fail "$icon is ${icon_width}x${icon_height}; the icon must be square"
            ;;
        *) fail "the icon must be a PNG or an SVG: $icon" ;;
    esac
fi

work=$(mktemp -d)
[ -n "$work" ] && [ -d "$work" ] || exit 1
trap 'rm -rf "$work"' EXIT

build=${TUI_HOST_BUILD:-}
if [ -z "$build" ]; then
    "$here/../scripts/check-clean-host.sh" "$here" || fail 'the host has uncommitted changes'
    build=$work/build
    meson setup "$build" "$here" --buildtype=release >&2 || fail 'meson setup failed'
    meson compile -C "$build" >&2 || fail 'the build failed'
fi
tool=$build/tui-app-manifest
host=$build/tui-app-host
[ -x "$tool" ] && [ -x "$host" ] || fail "no built host in $build"

id=$("$tool" "$manifest" id)
version=$("$tool" "$manifest" version)
name=$("$tool" "$manifest" name)
mode=$("$tool" "$manifest" mode)
base=$out/$id-$version-linux-$arch

stage=$work/stage
mkdir -p "$stage/bin" "$stage/share/$id" "$stage/share/applications" "$out"
cp "$host" "$stage/bin/$id"
chmod 755 "$stage/bin/$id"
cp -R "$payload/." "$stage/share/$id/"
cp "$manifest" "$stage/share/$id/app.manifest.json"
"$tool" "$manifest" desktop "$id" > "$stage/share/applications/$id.desktop"
if "$tool" "$manifest" mime > "$work/mime.xml"; then
    mkdir -p "$stage/share/mime/packages"
    cp "$work/mime.xml" "$stage/share/mime/packages/$id.xml"
fi

if [ "${icon_kind:-}" = svg ]; then
    mkdir -p "$stage/share/icons/hicolor/scalable/apps"
    cp "$icon" "$stage/share/icons/hicolor/scalable/apps/$id.svg"
elif [ "${icon_kind:-}" = png ]; then
    mkdir -p "$stage/share/icons/hicolor/${icon_width}x${icon_width}/apps"
    cp "$icon" "$stage/share/icons/hicolor/${icon_width}x${icon_width}/apps/$id.png"
else
    letter=$(printf '%s' "$name" | cut -c1 | tr '[:lower:]' '[:upper:]' | sed 's/[&<>]/?/')
    mkdir -p "$stage/share/icons/hicolor/scalable/apps"
    cat > "$stage/share/icons/hicolor/scalable/apps/$id.svg" <<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
  <rect x="8" y="8" width="112" height="112" rx="24" fill="#3a6ea5"/>
  <text x="64" y="86" font-family="sans-serif" font-size="64" font-weight="bold" text-anchor="middle" fill="#ffffff">$letter</text>
</svg>
SVG
fi
cp "$here/data/install.sh" "$stage/install.sh"
chmod 755 "$stage/install.sh"

if $want_tar; then
    tar -czf "$base.tar.gz" -C "$stage" .
    echo "$base.tar.gz"
fi

if $want_deb || $want_rpm; then
    package=$(printf '%s' "$id" | tr '[:upper:]' '[:lower:]')
    # The deb and rpm install under /usr: <id> on the PATH, its files in /usr/share/<id>.
    {
        sed -e "s|@NAME@|$package|g" -e "s|@ARCH@|$pkg_arch|g" -e "s|@VERSION@|$version|g" -e "s|@SUMMARY@|$name|g" "$here/data/nfpm.yaml"
        if [ "$mode" = system ]; then
            printf '  deb:\n    depends: [libgtk-4-1, libjson-glib-1.0-0, php-cli]\n  rpm:\n    depends: [gtk4, json-glib, php-cli]\n'
        else
            printf '  deb:\n    depends: [libgtk-4-1, libjson-glib-1.0-0]\n  rpm:\n    depends: [gtk4, json-glib]\n'
        fi
        printf 'scripts:\n  postinstall: %s\n  postremove: %s\ncontents:\n' "$here/data/postinstall.sh" "$here/data/postinstall.sh"
        printf '  - src: %s\n    dst: /usr/bin/%s\n    file_info: {mode: 0755}\n' "$stage/bin/$id" "$id"
        printf '  - src: %s\n    dst: /usr/share/%s\n    type: tree\n' "$stage/share/$id" "$id"
        printf '  - src: %s\n    dst: /usr/share/applications/%s.desktop\n' "$stage/share/applications/$id.desktop" "$id"
        (cd "$stage" && find share/icons share/mime -type f 2> /dev/null) | while read -r file; do
            printf '  - src: %s\n    dst: /usr/%s\n' "$stage/$file" "$file"
        done
    } > "$work/nfpm.yaml"
    for format in deb rpm; do
        eval "wanted=\$want_$format"
        $wanted || continue
        nfpm package --config "$work/nfpm.yaml" --packager "$format" --target "$base.$format" >&2 || fail "nfpm could not make the $format"
        echo "$base.$format"
    done
fi
