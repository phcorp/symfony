#!/bin/sh
set -eu
source=${1:?Source PNG or SVG}
output=${2:?Output PNG}
case "$source" in
 *.[pP][nN][gG]) cp "$source" "$output" ;;
 *.[sS][vV][gG])
  command -v rsvg-convert >/dev/null 2>&1 || { echo 'SVG mobile icons require rsvg-convert (librsvg); use a PNG otherwise.' >&2; exit 1; }
  rsvg-convert --width 1024 --height 1024 --output "$output" -- "$source" ;;
 *) echo 'Mobile icons must be PNG or SVG files.' >&2; exit 1 ;;
esac
