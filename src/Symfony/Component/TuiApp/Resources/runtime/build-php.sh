#!/bin/sh
# Builds the static PHP an embedded TuiApp desktop app ships as runtime/php, for this machine
# (macOS or Linux), with static-php-cli. build-php.ps1 does the same on Windows.
#
#   build-php.sh --version <8.5|8.5.12> --extensions <csv> --out <file>
#                [--cache <dir>] [--work <dir>] [--no-doctor-fix]
#   build-php.sh --list-extensions
#
# Supply chain: the static-php-cli binary is pinned and its sha256 checked before it runs;
# every source is pinned (php-sources.pins, PHP by version below), and what was downloaded,
# the doctor's tools included (pkg-config, the musl toolchain and musl), must match
# php-sources.lock before any of it is unpacked, installed or built (verify-sources.sh).
set -euf
export LC_ALL=C

here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
spc_version=2.8.5
# The PHP release a minor version resolves to. A release can be built only when its tarball,
# php-<version>.tar.xz, is in php-sources.lock (see verify-sources.sh to add one).
php_releases='8.5=8.5.13 8.4=8.4.28'
# What TuiApp itself needs; always built in.
required='ctype filter iconv mbstring pcntl phar posix tokenizer'

usage() {
    cat <<'EOF'
Usage: build-php.sh --version <8.5|8.5.12> --extensions <csv> --out <file> [options]

Builds a static PHP CLI for this machine with static-php-cli and copies it to <file>.
TuiApp's own extensions (ctype, filter, iconv, mbstring, pcntl, phar, posix, tokenizer)
are always included.

Options:
  --version <v>         PHP minor version (resolved to its pinned release) or exact release
  --extensions <csv>    extra extensions, comma separated (may be empty)
  --out <file>          where to copy the binary
  --cache <dir>         cache root (default: ${XDG_CACHE_HOME:-~/.cache}/tui-app/php);
                        each build is kept in <dir>/<sha256 of its inputs>
  --work <dir>          static-php-cli working folder, kept afterwards
                        (default: <cache entry>/work, removed after a successful build)
  --no-doctor-fix       let `spc doctor` only report what the build environment lacks,
                        not install it (on Linux it installs packages and a musl
                        toolchain with sudo); the build then stops on what is missing
  --list-extensions     print the extensions that build statically on this platform
EOF
}

die() {
    printf 'build-php: %s\n' "$*" >&2
    exit 2
}

sha256_file() {
    if command -v sha256sum > /dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}

sha256_stdin() {
    if command -v sha256sum > /dev/null 2>&1; then
        sha256sum | cut -d' ' -f1
    else
        shasum -a 256 | cut -d' ' -f1
    fi
}

version= extensions= extensions_set= out= cache= work= doctor_fix=1 list=
while [ $# -gt 0 ]; do
    case $1 in
        --version | --extensions | --out | --cache | --work)
            [ $# -ge 2 ] || die "$1 needs a value"
            option=$1 value=$2
            shift 2
            ;;
        --version=* | --extensions=* | --out=* | --cache=* | --work=*)
            option=${1%%=*} value=${1#*=}
            shift
            ;;
        --no-doctor-fix) doctor_fix= && shift && continue ;;
        --list-extensions) list=1 && shift && continue ;;
        -h | --help) usage && exit 0 ;;
        *) die "unknown argument: $1 (see --help)" ;;
    esac
    case $option in
        --version) version=$value ;;
        --extensions) extensions=$value extensions_set=1 ;;
        --out) out=$value ;;
        --cache) cache=$value ;;
        --work) work=$value ;;
    esac
done

case $(uname -s)-$(uname -m) in
    Darwin-arm64) os=macos arch=aarch64 asset=spc-macos-aarch64 sum=acf2f25d56d0cbf8e65aa82e5054fef555f7be7c5c38046c6e0819f266d83225 ;;
    Darwin-x86_64) os=macos arch=x86_64 asset=spc-macos-x86_64 sum=e8b798048f62ca4960764196543b60ae703f7174aa418824cf542aeec1d2cd6a ;;
    Linux-x86_64) os=linux arch=x86_64 asset=spc-linux-x86_64 sum=523ba4279c54c7a377156c0dd3a36adf92ee64b01e9a7f5e9e2ec084b8e458e5 ;;
    Linux-aarch64 | Linux-arm64) os=linux arch=aarch64 asset=spc-linux-aarch64 sum=675a3840dcdc4ed041fe20eaa54310ce019a9984c1c03951df9ec66df5795213 ;;
    *) die "unsupported platform $(uname -s) $(uname -m): static-php-cli $spc_version builds on macOS and Linux (x86_64, arm64); use build-php.ps1 on Windows" ;;
esac
platform=$(uname -s)-$(uname -m)
known=$here/static-extensions.list

if [ -n "$list" ]; then
    awk -v os="$os" '!/^#/ && NF { if (index("," $2 ",", "," os ",")) print $1 }' "$known"
    exit 0
fi

[ -n "$version" ] || die '--version is required (for example --version 8.5)'
[ -n "$extensions_set" ] || die '--extensions is required (it may be empty: --extensions "")'
[ -n "$out" ] || die '--out is required'
[ ! -d "$out" ] || die "--out names a folder: $out"

# The PHP release.
printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+(\.[0-9]+)?$' || die "--version must look like 8.5 or 8.5.12, not $version"
case $version in
    *.*.*) php=$version ;;
    *)
        php=
        for release in $php_releases; do
            [ "${release%%=*}" != "$version" ] || php=${release#*=}
        done
        [ -n "$php" ] || die "PHP $version is not pinned: build-php.sh pins $(printf '%s' "$php_releases" | sed 's/=[^ ]*//g; s/ /, /g'). To add it, put its latest release in php_releases and its tarball in php-sources.lock (see verify-sources.sh)."
        ;;
esac
tr -d '\r' < "$here/php-sources.lock" | grep -q "  php-$php\.tar\.xz\$" \
    || die "PHP $php is not in php-sources.lock: pinned releases are $(sed -n 's/.*  php-\(.*\)\.tar\.xz$/\1/p' "$here/php-sources.lock" | paste -sd, - | sed 's/,/, /g'). Record php-$php.tar.xz in the lock first (see verify-sources.sh)."

# The extensions: TuiApp's own, plus the requested ones, each known to build statically here.
requested=$(printf '%s\n' "$extensions" | tr ',' '\n' | tr 'A-Z' 'a-z' | tr -d ' \t\r')
all=$(printf '%s\n' $required $requested | grep -v '^$' | sort -u)
unknown= elsewhere=
for extension in $all; do
    printf '%s\n' "$extension" | grep -Eq '^[a-z0-9_-]+$' || die "not an extension name: $extension"
    platforms=$(awk -v name="$extension" '!/^#/ && $1 == name { print $2 }' "$known")
    if [ -z "$platforms" ]; then
        unknown="$unknown $extension"
    else
        case ,$platforms, in
            *,$os,*) ;;
            *) elsewhere="$elsewhere $extension (only $platforms)" ;;
        esac
    fi
done
if [ -n "$unknown" ] || [ -n "$elsewhere" ]; then
    {
        [ -z "$unknown" ] || echo "build-php: unknown extension(s):$unknown"
        for name in $unknown; do
            like=$(awk -v name="$name" -v os="$os" '!/^#/ && NF && index("," $2 ",", "," os ",") && (index($1, name) || index(name, $1) || (length(name) > 3 && substr($1, 1, 4) == substr(name, 1, 4))) { printf " %s", $1 }' "$known")
            [ -z "$like" ] || echo "  $name: did you mean$like?"
        done
        [ -z "$elsewhere" ] || echo "build-php: not available as a static extension on $os:$elsewhere"
        echo "static-php-cli $spc_version builds $(awk -v os="$os" '!/^#/ && NF && index("," $2 ",", "," os ",")' "$known" | wc -l | tr -d ' ') extensions statically on $os: build-php.sh --list-extensions lists them."
    } >&2
    exit 2
fi
extension_list=$(printf '%s\n' $all | paste -sd, -)

# The cache entry: one per set of inputs.
key=$(printf 'spc %s\nphp %s\nextensions %s\npins %s\nplatform %s\n' \
    "$spc_version" "$php" "$extension_list" "$(sha256_file "$here/php-sources.pins")" "$platform" | sha256_stdin)
cache=${cache:-${XDG_CACHE_HOME:-$HOME/.cache}/tui-app/php}
entry=$cache/$key
mkdir -p "$(dirname -- "$out")"
out=$(CDPATH= cd -- "$(dirname -- "$out")" && pwd -P)/$(basename -- "$out")

install_binary() {
    cp "$1" "$out.partial.$$"
    chmod 755 "$out.partial.$$"
    mv -f "$out.partial.$$" "$out"
}

if [ -f "$entry/php" ] && [ -f "$entry/php.sha256" ]; then
    if [ "$(sha256_file "$entry/php")" = "$(cat "$entry/php.sha256")" ]; then
        install_binary "$entry/php"
        "$out" -n "$here/check-php.php" "$php" "$extension_list"
        echo "build-php: PHP $php from the cache ($entry) -> $out"
        exit 0
    fi
    echo "build-php: $entry/php does not match its digest: building it again" >&2
fi

# One build per cache entry at a time.
mkdir -p "$entry"
building=$entry/.building
if ! mkdir "$building" 2> /dev/null; then
    pid=$(cat "$building/pid" 2> /dev/null || true)
    if [ -n "$pid" ] && kill -0 "$pid" 2> /dev/null; then
        die "another build (process $pid) is filling $entry"
    fi
    rm -rf "$building"
    mkdir "$building"
fi
echo $$ > "$building/pid"
trap 'rm -rf "$building"' EXIT
trap 'exit 130' INT TERM

if [ -n "$work" ]; then
    keep_work=1
else
    work=$entry/work
    keep_work=
fi
mkdir -p "$work"
work=$(CDPATH= cd -- "$work" && pwd -P)
case $work in
    *[[:space:]]*) die "the work folder must not contain spaces (PHP's build does not support them): $work; choose one with --work or --cache" ;;
esac
started=$(date +%s)
echo "build-php: building PHP $php with $extension_list for $platform in $work"
cd "$work"

# The builder is pinned and checked before it runs: it writes the PHP every app ships.
if [ ! -f spc.tar.gz ] || [ "$(sha256_file spc.tar.gz)" != "$sum" ]; then
    rm -f spc.tar.gz
    curl -fsSL --retry 3 -o spc.tar.gz "https://github.com/crazywhalecc/static-php-cli/releases/download/$spc_version/$asset.tar.gz"
fi
actual=$(sha256_file spc.tar.gz)
[ "$actual" = "$sum" ] || { rm -f spc.tar.gz; die "$asset.tar.gz has sha256 $actual, expected $sum"; }
tar -xzf spc.tar.gz spc
chmod +x spc

# Every source at its pinned version (php-sources.pins): left to itself, static-php-cli fetches
# each project's latest, and the lock refuses it the day upstream releases.
duplicates=$(awk '!/^#/ && NF { n = split($2, part, "/"); print part[n] }' "$here/php-sources.pins" | sort | uniq -d)
[ -z "$duplicates" ] || die "two pinned sources share a file name: $duplicates"
set --
while read -r name url; do
    case $name in '' | '#'*) continue ;; esac
    set -- "$@" "--custom-url=$name:$url"
done < "$here/php-sources.pins"
./spc download --with-php="$php" --for-extensions="$extension_list" --without-suggestions --no-alt --retry=2 "$@"

# What `spc doctor --auto-fix` would download and then unpack, run or install with sudo at once:
# a static pkg-config (every build), and on Linux the musl toolchain and musl's source for its
# wrapper (once per machine, when missing; the checks are the doctor's). They are fetched here,
# from their pins, without unpacking, checked with the sources, and only then installed.
pinned() {
    awk -v name="$1" '!/^#/ && $1 == name { print $2 }' "$here/php-sources.pins"
}
fetch_package() {
    url=$(pinned "$1")
    [ -n "$url" ] || die "$1 is not pinned in php-sources.pins"
    ./spc install-pkg "$1" "--custom-url=$1:$url" --skip-extract --no-alt
    fetched=$(basename "$url")
}
fetch_package "pkg-config-$arch-$os"
pkg_config=$fetched
musl_toolchain=
if [ "$os" = linux ]; then
    if [ ! -f "/usr/local/musl/$arch-linux-musl/lib/libc.a" ] || [ ! -f "/usr/local/musl/bin/$arch-linux-musl-gcc" ]; then
        fetch_package "musl-toolchain-$arch-linux"
        musl_toolchain=$fetched
    fi
    if [ ! -f "/lib/ld-musl-$(uname -m).so.1" ] || [ ! -f /usr/local/musl/lib/libc.a ]; then
        # The doctor's fix skips its own download when static-php-cli's download lock has this name.
        url=$(pinned musl-1.2.5)
        [ -n "$url" ] || die 'musl-1.2.5 is not pinned in php-sources.pins'
        ./spc download musl-1.2.5 "--custom-url=musl-1.2.5:$url" --retry=2
    fi
fi
"$here/verify-sources.sh" downloads "$here/php-sources.lock"

# GNU tar unpacks .txz with xz, which the doctor would install only now.
if [ "$os" = linux ] && ! command -v xz > /dev/null 2>&1; then
    [ -n "$doctor_fix" ] || die 'xz is missing: install it (Debian and Ubuntu: xz-utils), or run again without --no-doctor-fix'
    if command -v apt-get > /dev/null 2>&1; then
        sudo apt-get install -y xz-utils
    elif command -v dnf > /dev/null 2>&1; then
        sudo dnf install -y xz
    elif command -v yum > /dev/null 2>&1; then
        sudo yum install -y xz
    elif command -v apk > /dev/null 2>&1; then
        sudo apk add xz
    else
        die 'xz is missing, and this script knows no package manager here to install it with: install xz'
    fi
fi
# Where static-php-cli looks for its pkg-config: <work>/pkgroot/<arch>-<os>/bin.
pkgroot=$work/pkgroot/$arch-$(uname -s | tr 'A-Z' 'a-z')
unpacked=$work/pkgroot/unpacked-pkg-config
rm -rf "$unpacked"
mkdir -p "$unpacked" "$pkgroot/bin"
tar -xf "downloads/$pkg_config" -C "$unpacked" --strip-components 1
[ -f "$unpacked/bin/pkg-config" ] || die "downloads/$pkg_config holds no bin/pkg-config"
cp "$unpacked/bin/pkg-config" "$pkgroot/bin/pkg-config.partial"
chmod 755 "$pkgroot/bin/pkg-config.partial"
mv -f "$pkgroot/bin/pkg-config.partial" "$pkgroot/bin/pkg-config"
rm -rf "$unpacked"
if [ -n "$musl_toolchain" ] && [ -n "$doctor_fix" ]; then
    # As the doctor does (its cp -rf of the archive's top folder), from the checked archive.
    echo "build-php: installing the musl toolchain into /usr/local/musl, with sudo"
    sudo mkdir -p /usr/local/musl
    sudo tar -xzf "downloads/$musl_toolchain" -C /usr/local/musl --strip-components 1 --no-same-owner
fi

# The doctor now installs system packages, and builds musl's wrapper from the checked source,
# with the system gcc. static-php-cli puts the musl toolchain's bin folders first on PATH, its
# plain `ld` too, which that gcc cannot use (no plugins in a static ld); it leaves PATH alone
# when they are on it already, so they go last here.
doctor_path=$PATH
[ "$os" != linux ] || doctor_path=$PATH:/usr/local/musl/bin:/usr/local/musl/$arch-linux-musl/bin
if [ -n "$doctor_fix" ]; then
    PATH=$doctor_path ./spc doctor --auto-fix
elif ! PATH=$doctor_path ./spc doctor --auto-fix=never; then
    # static-php-cli 2.8.5 reports and goes on with --auto-fix=never: it fails only on a crash.
    die "static-php-cli cannot build here yet. Install what it lists, or run again without --no-doctor-fix to let it do so (on Linux: system packages and a musl toolchain, with sudo)."
fi

# Reproducible: two builds of the same inputs in the same folder give the same bytes. The
# compiler takes __DATE__ and __TIME__ (PHP_BUILD_DATE, `php -v`) from SOURCE_DATE_EPOCH, by
# default the PHP release's (php_version.h in its tarball); PHP_UNAME and PHP_BUILD_SYSTEM
# would otherwise hold this machine's `uname -a`, its name included.
if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
    stamp=$work/php-version-stamp
    rm -rf "$stamp"
    mkdir -p "$stamp"
    tar -xf "downloads/php-$php.tar.xz" -C "$stamp" "php-$php/main/php_version.h"
    SOURCE_DATE_EPOCH=$(date -r "$stamp/php-$php/main/php_version.h" +%s)
    rm -rf "$stamp"
fi
PHP_UNAME="$(uname -s) $(uname -m)"
PHP_BUILD_SYSTEM=$PHP_UNAME
export SOURCE_DATE_EPOCH PHP_UNAME PHP_BUILD_SYSTEM
# macOS: the linker derives the binary's LC_UUID from what it writes, the debug map's object
# file times included (static-php-cli strips that map afterwards, not the UUID); it writes 0
# instead with ZERO_AR_DATE, as do ar and libtool in archives.
[ "$os" != macos ] || export ZERO_AR_DATE=1

# SQLite 3.48 and later: static-php-cli 2.8.5 runs its configure with autoconf's --with-pic and
# --enable-pic, which the new one (autosetup) refuses; it builds position-independent code
# anyway (-fPIC). Unpacked here from the checked archive, its configure drops them, and asks
# for the extensions 3.47.2's turned on by default and the new one leaves out (FTS4, FTS5,
# R*Tree, Geopoly). Without a tclsh, it first builds its own (jimsh0) with CC_FOR_BUILD, by
# default cc, which on Linux cannot link with the musl toolchain's ld first on PATH: the
# compiler static-php-cli chose (CC) can. static-php-cli leaves the folder as it is: its
# .spc-hash matches.
sqlite_archive=$(basename "$(pinned sqlite)")
if [ -f "downloads/$sqlite_archive" ]; then
    ./spc extract sqlite --source-only
    if [ -d source/sqlite/autosetup ] && [ ! -f source/sqlite/configure.autosetup ]; then
        mv source/sqlite/configure source/sqlite/configure.autosetup
        cat > source/sqlite/configure <<'EOF'
#!/bin/sh
# Written by build-php.sh: see there.
for arg; do
    shift
    case $arg in
        --with-pic | --enable-pic) ;;
        *) set -- "$@" "$arg" ;;
    esac
done
CC_FOR_BUILD=${CC_FOR_BUILD:-${CC:-cc}}
export CC_FOR_BUILD
exec "$(dirname "$0")/configure.autosetup" --fts4 --fts5 --rtree --geopoly "$@"
EOF
        chmod 755 source/sqlite/configure
    fi
fi
./spc build "$extension_list" --build-cli
"$work/buildroot/bin/php" -n "$here/check-php.php" "$php" "$extension_list"

# Into the cache, then out.
cp buildroot/bin/php "$entry/php.partial"
chmod 755 "$entry/php.partial"
sha256_file "$entry/php.partial" > "$entry/php.sha256"
mv -f "$entry/php.partial" "$entry/php"
printf 'spc %s\nphp %s\nextensions %s\npins %s\nplatform %s\n' \
    "$spc_version" "$php" "$extension_list" "$(sha256_file "$here/php-sources.pins")" "$platform" > "$entry/inputs.txt"
install_binary "$entry/php"
cd "$entry"
if [ -z "$keep_work" ] && [ -n "$entry" ] && [ "$work" = "$(pwd -P)/work" ]; then
    rm -rf "$work"
fi
echo "build-php: built PHP $php in $(($(date +%s) - started)) s -> $out (cached in $entry)"
