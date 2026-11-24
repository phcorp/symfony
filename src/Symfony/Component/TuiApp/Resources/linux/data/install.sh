#!/bin/sh
# Installs the unpacked tarball for this user alone: nothing outside ~/.local, no root.
set -eu
here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
data=${XDG_DATA_HOME:-$HOME/.local/share}
bin=$HOME/.local/bin
id=$(ls "$here/bin")
[ -n "$id" ] || { echo 'install: no application in bin/' >&2; exit 1; }

rm -rf "${data:?}/$id"
mkdir -p "$bin" "$data/applications"
cp "$here/bin/$id" "$bin/$id"
# The host finds its manifest in ../share/<id>/ from its own directory.
cp -R "$here/share/." "$data/"
if [ "$data" != "$HOME/.local/share" ]; then
    mkdir -p "$HOME/.local/share"
    ln -sfn "$data/$id" "$HOME/.local/share/$id"
fi
sed "s|^Exec=$id |Exec=$bin/$id |" "$here/share/applications/$id.desktop" > "$data/applications/$id.desktop"
command -v update-desktop-database > /dev/null && update-desktop-database "$data/applications" || true
[ -d "$data/mime/packages" ] && command -v update-mime-database > /dev/null && update-mime-database "$data/mime" || true
command -v gtk-update-icon-cache > /dev/null && gtk-update-icon-cache -q -t "$data/icons/hicolor" || true
# Everything this copied, for the removal hint: icons and the mime type included.
extra=$(cd "$here/share" && find icons mime -type f 2> /dev/null | sed "s|^|'$data/|; s|\$|'|" | tr '\n' ' ')
echo "$id is in your applications menu. To remove it: rm -rf '$bin/$id' '$data/$id' '$data/applications/$id.desktop' $extra"
