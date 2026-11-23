#!/bin/sh
# Holds what static-php-cli downloaded to the digests committed in php-sources.lock.
#
#   verify-sources.sh <downloads folder> <lock file>
#
# build-php.sh runs it between `spc download` and `spc build`; build-php.ps1 does the same
# in PowerShell, with the same lock format. A file's digest is its sha256. A folder's (a git
# checkout) is the sha256 of a listing of its entries: kind, executable bit, path, and
# content digest or link target.
#
# The sources are pinned in php-sources.pins, PHP itself in build-php.sh. To renew the lock
# after changing a pin, a PHP release, or adding an extension that needs a new library:
#   1. Pin every new source in php-sources.pins.
#   2. On each platform, build once with a fresh cache, recording instead of verifying:
#        TUI_APP_SOURCES_RECORD=/tmp/lock-$(uname -s) runtime/build-php.sh --version … \
#            --extensions … --out /tmp/php --cache /tmp/fresh-cache
#   3. Check the new digests against what upstream publishes where it does (php.net, GitHub
#      release digests), then merge: sort -u -k2 php-sources.lock /tmp/lock-* > new.lock
#      Two lines for one file name mean a source changed under a pinned URL: find out why.
#   4. Review the difference and commit it.
set -eu

[ $# -eq 2 ] || { echo 'usage: verify-sources.sh <downloads folder> <lock file>' >&2; exit 2; }
downloads=$1
lock=$2
export LC_ALL=C

refuse() {
    echo "verify-sources: $1" >&2
    exit 1
}

sha256() {
    if command -v sha256sum > /dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}

# Every entry counts: its kind, whether it is executable, and its content or link target.
describe() {
    root=$(CDPATH= cd -- "$1" && pwd -P)
    list=$(mktemp)
    (cd "$root" && find . ! -path './.git' ! -path './.git/*' ! -type d | sort) > "$list"
    while IFS= read -r file; do
        path=$root/${file#./}
        if [ -L "$path" ]; then
            target=$(readlink "$path")
            case $target in
                /*) refuse "$file links outside its source: $target" ;;
            esac
            parent=$(dirname "$path")/$(dirname "$target")
            if [ -d "$parent" ]; then
                case $(CDPATH= cd -- "$parent" && pwd -P)/ in
                    "$root"/*) ;;
                    *) refuse "$file links outside its source: $target" ;;
                esac
            fi
            printf 'link %s %s\n' "$file" "$target"
        elif [ -f "$path" ]; then
            mode=-
            [ ! -x "$path" ] || mode=x
            printf 'file %s %s %s\n' "$mode" "$file" "$(sha256 "$path")"
        else
            refuse "$file is neither a file, a folder nor a link"
        fi
    done < "$list"
    rm -f "$list"
}

digest() {
    if [ -d "$1" ] && [ ! -L "$1" ]; then
        description=$(mktemp)
        describe "$1" > "$description"
        sha256 "$description"
        rm -f "$description"
    elif [ -f "$1" ] && [ ! -L "$1" ]; then
        sha256 "$1"
    else
        refuse "$1 is neither a file nor a folder"
    fi
}

[ -d "$downloads" ] || refuse "$downloads is not a folder"
actual=$(mktemp)
for entry in "$downloads"/*; do
    [ -e "$entry" ] || [ -L "$entry" ] || continue
    printf '%s  %s\n' "$(digest "$entry")" "$(basename "$entry")" >> "$actual"
done
sort -k2 "$actual" -o "$actual"

if [ -n "${TUI_APP_SOURCES_RECORD:-}" ]; then
    cp "$actual" "$TUI_APP_SOURCES_RECORD"
    rm -f "$actual"
    echo "verify-sources: recorded $(wc -l < "$TUI_APP_SOURCES_RECORD" | tr -d ' ') sources in $TUI_APP_SOURCES_RECORD, verified nothing"
    exit 0
fi

[ -s "$lock" ] || refuse "$lock is empty: nothing to verify against"
expected=$(mktemp)
tr -d '\r' < "$lock" > "$expected"
unknown=$(grep -vxFf "$expected" "$actual" || true)
count=$(wc -l < "$actual" | tr -d ' ')
rm -f "$actual" "$expected"
if [ -n "$unknown" ]; then
    {
        echo 'verify-sources: not what php-sources.lock pinned:'
        printf '%s\n' "$unknown"
        echo 'A source that is not pinned yet (an extension that needs a new library) must be'
        echo 'pinned in php-sources.pins and recorded in the lock: see runtime/verify-sources.sh.'
    } >&2
    exit 1
fi
echo "verify-sources: $count sources match the lock"
