#!/bin/sh
# After the deb or rpm installs or removes the application: refresh the desktop caches.
command -v update-desktop-database > /dev/null 2>&1 && update-desktop-database -q /usr/share/applications || true
command -v update-mime-database > /dev/null 2>&1 && update-mime-database /usr/share/mime || true
command -v gtk-update-icon-cache > /dev/null 2>&1 && gtk-update-icon-cache -q -t /usr/share/icons/hicolor || true
exit 0
