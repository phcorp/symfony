#!/bin/sh
# Makes <Name>.app around a TuiApp application:
#   build-app.sh [options] <app.manifest.json> <payload-dir> <out-dir>
# Options:
#   --icon <file>        a PNG of at least 1024 px (made into an .icns), or an .icns used as is
#   --sign <identity>    sign with this identity and the hardened runtime (default: ad hoc)
#   --notarize <profile> notarize with `xcrun notarytool` and this keychain profile, then staple
#                        (needs --sign)
#   --host <binary>      use this host binary instead of building one in release mode
# The payload's contents go to Contents/Resources/, the manifest to
# Contents/Resources/app.manifest.json. Prints the path of the bundle.
set -eu

usage() {
    sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//' >&2
    exit 2
}

icon=
identity=
profile=
host=
while [ $# -gt 0 ]; do
    case $1 in
        --icon) icon=${2:?}; shift 2 ;;
        --sign) identity=${2:?}; shift 2 ;;
        --notarize) profile=${2:?}; shift 2 ;;
        --host) host=${2:?}; shift 2 ;;
        --help|-h) usage ;;
        --) shift; break ;;
        -*) echo "build-app: unknown option $1" >&2; usage ;;
        *) break ;;
    esac
done
[ $# -eq 3 ] || usage
manifest=$1
payload=$2
out=$3
[ -f "$manifest" ] || { echo "build-app: no manifest at $manifest" >&2; exit 2; }
[ -d "$payload" ] || { echo "build-app: no payload directory at $payload" >&2; exit 2; }
if [ -n "$profile" ] && [ -z "$identity" ]; then
    echo "build-app: --notarize needs --sign <identity>" >&2
    exit 2
fi

here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
if [ -z "$host" ]; then
    "$here/../scripts/check-clean-host.sh" "$here" || { echo "build-app: the host has uncommitted changes" >&2; exit 1; }
    swift build --package-path "$here" -c release --product TuiAppHost >&2
    host=$(swift build --package-path "$here" -c release --show-bin-path)/TuiAppHost
fi
[ -x "$host" ] || { echo "build-app: no host binary at $host" >&2; exit 2; }

# The manifest is checked by parsing it: a bad one stops here, not at the user's first launch.
name=$("$host" --bundle-name "$manifest")
[ -n "$name" ] || { echo "build-app: the manifest gives no app name" >&2; exit 2; }

mkdir -p "$out"
app=$(CDPATH= cd -- "$out" && pwd -P)/$name.app
case $app in
    */"$name".app) rm -rf "$app" ;;
    *) echo "build-app: refusing to replace $app" >&2; exit 2 ;;
esac
contents=$app/Contents
mkdir -p "$contents/MacOS" "$contents/Resources"
cp "$host" "$contents/MacOS/$name"
chmod 755 "$contents/MacOS/$name"
ditto "$payload" "$contents/Resources"
cp "$manifest" "$contents/Resources/app.manifest.json"

iconfile=
if [ -n "$icon" ]; then
    iconfile=AppIcon.icns
    case $icon in
        *.icns)
            cp "$icon" "$contents/Resources/$iconfile"
            ;;
        *.png|*.PNG)
            width=$(sips -g pixelWidth "$icon" | awk '/pixelWidth/ {print $2}')
            height=$(sips -g pixelHeight "$icon" | awk '/pixelHeight/ {print $2}')
            if [ "${width:-0}" -lt 1024 ] || [ "${height:-0}" -lt 1024 ]; then
                echo "build-app: the icon must be at least 1024×1024 px, $icon is ${width}×${height}" >&2
                exit 2
            fi
            work=$(mktemp -d)
            iconset=$work/AppIcon.iconset
            mkdir -p "$iconset"
            for size in 16 32 128 256 512; do
                sips -z "$size" "$size" "$icon" --out "$iconset/icon_${size}x${size}.png" > /dev/null
                double=$((size * 2))
                sips -z "$double" "$double" "$icon" --out "$iconset/icon_${size}x${size}@2x.png" > /dev/null
            done
            iconutil -c icns "$iconset" -o "$contents/Resources/$iconfile"
            case $work in /*/tmp.*) rm -rf "$work" ;; esac
            ;;
        *)
            echo "build-app: the icon must be a .png or an .icns: $icon" >&2
            exit 2
            ;;
    esac
fi

if [ -n "$iconfile" ]; then
    "$host" --info-plist "$manifest" "$name" "$iconfile" > "$contents/Info.plist"
else
    "$host" --info-plist "$manifest" "$name" > "$contents/Info.plist"
fi
plutil -lint "$contents/Info.plist" > /dev/null
printf 'APPL????' > "$contents/PkgInfo"

# Every Mach-O in the payload (an embedded php, extensions) is signed before the bundle.
sign() {
    if [ -n "$identity" ]; then
        codesign --force --options runtime --timestamp --sign "$identity" "$1"
    else
        codesign --force --sign - "$1"
    fi
}
find "$contents/Resources" -type f | while IFS= read -r file; do
    case $(file -b "$file") in
        Mach-O*) sign "$file" ;;
    esac
done
sign "$contents/MacOS/$name"
sign "$app"
codesign --verify --deep --strict "$app"

if [ -n "$profile" ]; then
    zip=$(mktemp -d)/$name.zip
    ditto -c -k --keepParent "$app" "$zip"
    xcrun notarytool submit "$zip" --keychain-profile "$profile" --wait
    xcrun stapler staple "$app"
fi

echo "$app"
