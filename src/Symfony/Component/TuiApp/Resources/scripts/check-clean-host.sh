#!/bin/sh
# Refuses to package a host built from uncommitted changes:
#   check-clean-host.sh <host source folder>
# A package must hold a host that a commit describes. Outside a git checkout, or
# without git, nothing is checked. TUI_APP_HOSTS_ALLOW_DIRTY=1 packages anyway,
# for development.
set -eu
dir=${1:?usage: check-clean-host.sh <host source folder>}
command -v git >/dev/null 2>&1 || exit 0
git -C "$dir" rev-parse --is-inside-work-tree >/dev/null 2>&1 || exit 0
changes=$(git -C "$dir" status --porcelain --untracked-files=all -- . 2>/dev/null) || exit 0
[ -z "$changes" ] && exit 0
if [ "${TUI_APP_HOSTS_ALLOW_DIRTY:-}" = 1 ]; then
    echo "warning: packaging a host with uncommitted changes in $dir (TUI_APP_HOSTS_ALLOW_DIRTY=1)" >&2
    exit 0
fi
echo "the host in $dir has uncommitted changes; commit or stash them, or set TUI_APP_HOSTS_ALLOW_DIRTY=1:" >&2
printf '%s\n' "$changes" | sed 's/^/  /' >&2
exit 1
