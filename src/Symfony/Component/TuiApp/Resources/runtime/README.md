# Embedded PHP runtime

An app built in `embedded` mode ships a static PHP CLI as `runtime/php`
(`runtime\php.exe` on Windows) next to its manifest; the host starts it as
`runtime/php -n -d k=v… ./app/<entry>`. These scripts build that binary with
[static-php-cli](https://github.com/crazywhalecc/static-php-cli) 2.8.5, for the
machine they run on.

## Usage

```sh
runtime/build-php.sh --version 8.5 --extensions intl,sqlite3 --out build/runtime/php
```

```powershell
powershell -ExecutionPolicy Bypass -File runtime\build-php.ps1 --version 8.5 --extensions intl,sqlite3 --out build\runtime\php.exe
```

- `--version`: `8.5` or `8.4` resolve to the pinned release (8.5.13, 8.4.28).
  An exact release builds only if its tarball is in `php-sources.lock`
  (8.5.12, 8.5.13, 8.4.28).
- `--extensions`: extra extensions, comma separated, may be empty
  (`--extensions=`). TuiApp's own are always built in: ctype, filter, iconv,
  mbstring, phar, tokenizer, and on macOS and Linux pcntl and posix.
  `--list-extensions` prints what builds statically on this platform
  (`static-extensions.list`). An unknown name fails with suggestions.
- `--out`: where the binary goes.
- `--cache <dir>`: cache root, default `${XDG_CACHE_HOME:-~/.cache}/tui-app/php`
  (`%LOCALAPPDATA%\tui-app\php` on Windows). Each build is kept in
  `<dir>/<sha256>` of the static-php-cli version, the PHP release, the sorted
  extensions, `php-sources.pins` and the platform. A cache hit copies the binary
  and runs the check, in about a second.
- `--work <dir>`: static-php-cli's working folder, kept. By default it lives in
  the cache entry and is removed after a successful build. A failed build keeps it
  (logs in `work/log`), and the next run resumes from it.
- `--no-doctor-fix`: `spc doctor` only reports what is missing. By default it
  installs it: on Linux, system packages and a musl toolchain in `/usr/local/musl`,
  with `sudo`. On macOS it needs Homebrew and Xcode's command line tools.
- `SOURCE_DATE_EPOCH`: the build date PHP reports (`PHP_BUILD_DATE`, `php -v`). By
  default, the date of the PHP release (its `main/php_version.h`). MSVC ignores
  it: build-php.ps1 writes that date in place of `__DATE__` and `__TIME__` in
  PHP's source.

Every build ends with `check-php.php`, run as `php -n check-php.php <release>
<extensions>`. It checks the version, that each extension is loaded, a TCP
round trip with `stream_socket_client` and `stream_select`, and that `-n -d k=v`
works. Only a binary that passes is cached.

Linux (checked on Ubuntu 24.04 arm64 and x86_64) needs `curl`, `tar`, `gzip` and
`sudo`, on a distribution whose packages static-php-cli's doctor knows how to
install (apt, dnf, yum, apk). The rest it installs itself, with `sudo`, once per
machine: `xz-utils` (build-php.sh, to unpack the doctor's pkg-config), then
`make bison re2c flex git autoconf automake autopoint unzip gcc g++ bzip2 cmake
patch libtool` (the doctor, `apt-get install -y`), the musl toolchain into
`/usr/local/musl`, and musl itself, built from source, into `/usr/local/musl` and
`/lib/ld-musl-<arch>.so.1`. A bare Ubuntu 24.04 (OrbStack's image: curl, tar, perl
and sudo, no compiler) builds this way unattended; with `--no-doctor-fix`, install
those packages first (`sudo apt install xz-utils make bison re2c flex git autoconf
automake autopoint unzip gcc g++ bzip2 cmake patch libtool`) and the doctor still
needs `sudo` for musl. The result is a fully static musl binary (`file`: statically
linked; `ldd`: not a dynamic executable), about 12 MB with the default extensions
and 51 MB with intl (ICU's data), sqlite3 and pdo_sqlite. A cold build takes about
three minutes on an idle 16-core arm64 machine; measured on a busy host (load
average 100 to 370): 7.5 to 10 minutes on arm64, 12 to 14 with intl, 20 on a bare
machine with the doctor installing everything first, and 49 for x86_64 under
Rosetta on an Apple silicon Mac.

### Reproducible builds

Two cold builds of the same inputs in the same work folder give the same bytes.
Checked on Linux arm64: two builds one after the other in one machine, and two
builds in the default cache of two Ubuntu 24.04 machines (one freshly set up by
the doctor, one long-lived), each pair with the same sha256; and on macOS arm64
(Apple clang 21), two builds one after the other with `--work` naming the same
folder and two fresh `--cache` folders. Without `ZERO_AR_DATE=1`, which
build-php.sh sets on macOS, those two differed in 47 bytes: the `LC_UUID` (16
bytes), which the linker computes over its output, debug map included, whose
`N_OSO` entries hold each object file's modification time (static-php-cli strips
the map with `strip -S` afterwards, but not the UUID), and the hash of that first
page in the ad hoc signature (32 bytes). build-php.sh sets
`SOURCE_DATE_EPOCH` (see above), which the compiler uses for `__DATE__` and
`__TIME__`, and `PHP_UNAME` and `PHP_BUILD_SYSTEM` to `<kernel> <machine>`
(`Linux aarch64`) instead of `uname -a`, which names the machine. What differs
between two work folders: their path, which PHP keeps in its configure command
(`php -i`) and in 13 `__FILE__` strings, and with it the binary's layout; between
two machines, the compilers too when their distributions' or musl toolchains
differ. The default work folder, `~/.cache/tui-app/php/<key>/work`, is the same
for the same user name and inputs.

Windows needs Visual Studio 2019 or 2022 with the C++ workload, and Git for
Windows with `C:\Program Files\Git\usr\bin` in `PATH`. build-php.ps1 fetches
nasm and Strawberry Perl from their pins (see Supply chain), where the doctor
looks for them. Keep the cache and work paths short and without spaces.
build-php.ps1 also takes PowerShell's spelling (`-Version 8.5 -Extensions a,b
-Out x`), as TuiApp's builder passes it, and leaves out pcntl and posix, which
TuiApp's default extensions list and Windows does not have.

- static-php-cli 2.8.5 finds Visual Studio 2022 only as Community, Professional
  or Enterprise in `C:\Program Files\Microsoft Visual Studio\2022`, and 2019 in
  `C:\Program Files (x86)`: not the Build Tools. With the Build Tools alone, link
  them there once, as an administrator: `mklink /J "C:\Program Files\Microsoft
  Visual Studio\2022\Community" "C:\Program Files (x86)\Microsoft Visual
  Studio\2022\BuildTools"` (only the path is checked; php-sdk-binary-tools finds
  the compiler with vswhere).
- static-php-cli builds x64 binaries only (`spc-windows-x64.exe`; it has no
  Windows ARM64 build). On Windows on Arm, spc runs emulated and builds an x64
  PHP with Visual Studio's ARM64-hosted x64 compiler (`vcvarsall arm64_amd64`):
  build-php.ps1 fixes php-sdk-binary-tools' `phpsdk_setshell.bat`, which would
  ask for `x64_amd64`, an architecture vcvarsall refuses. The app's x64 PHP then
  runs emulated next to the ARM64 host; check-php.php passes under emulation.
- Checked on Windows 11 ARM64 (26300, 12 cores, 6 GB, UTM on Apple silicon) with
  the Build Tools 2022 (VCTools workload with the x64 and ARM64 compilers, and
  the Windows 11 SDK 10.0.26100: 5 GB, plus 1.5 GB for the SDK, 14 minutes) and
  Git for Windows 2.56.1 arm64: a cold build of the default extensions takes
  about ten minutes (`spc build`: 575 s, of which five minutes of nmake for PHP
  itself), a cache hit three seconds. The result is an 11 MB x64 `php.exe` that
  needs only system DLLs (no Visual C++ runtime). With intl, sqlite3 and
  pdo_sqlite it is 50 MB (ICU's data). Measured on 21 November 2026 with the Mac
  hosting the VM busy (load average 380 to 560), two or three builds at once: 954
  to 956 s for the default extensions, 1356 s with intl, sqlite3 and pdo_sqlite;
  unpacking Strawberry Perl and the doctor take about three minutes of it.
- Reproducible: two cold builds with `--work` naming the same folder and two
  fresh `--cache` folders gave the same bytes (sha256 `888bd859…b363e5`, `fc /b`:
  no differences). MSVC has no `SOURCE_DATE_EPOCH`: build-php.ps1 unpacks PHP
  (`spc extract`, after the lock has matched it) and writes the date as
  `__DATE__` and `__TIME__` would (`"Nov 17 2026"`, `"14:30:51"`) in their
  place in `main\main.c` and `Zend\zend_system_id.c`; adds `/Brepro` to PHP's
  CFLAGS, LDFLAGS and ARFLAGS (`win32\build\config.w32`), so the compiler and
  the linker write no time stamps (the PE header's is a hash of the content);
  and has `configure.js` take `PHP_BUILD_SYSTEM` (`Windows x64`) instead of the
  Windows edition and build. Without them, the binary held the time it was
  built (its PE time stamp and `php -v`). `/Brepro` for the whole build (`CL`
  and `LINK`) breaks libiconv-win's msbuild projects (LNK1103), so the libraries
  are built without it; what PHP links from them is code, not their time
  stamps. The work folder's path stays in the binary, as on macOS and Linux.

## Supply chain

- The static-php-cli binary is pinned by sha256 in both scripts.
  `php-sdk-binary-tools` (Windows) is pinned to a commit and checked against the
  lock.
- Every source is pinned in `php-sources.pins`, and PHP by release. What was
  downloaded must match `php-sources.lock` before anything is built. An extension
  that needs a library nobody pinned yet fails there: pin it and record the lock,
  as `verify-sources.sh` explains.
- On macOS and Linux, the doctor's own downloads are pinned in
  `php-sources.pins` by package name (pkg-config, the musl toolchain, musl) and
  fetched by build-php.sh, without unpacking, before the doctor runs. They are
  checked against the lock with the sources, and only then unpacked and
  installed: pkg-config into the work folder, the musl toolchain into
  `/usr/local/musl` with `sudo`; the doctor then builds musl from the checked
  source (it finds it already downloaded). Nothing static-php-cli downloads runs,
  or is installed, before the lock has matched it. The doctor's system packages
  come from the distribution's package manager, which checks its own signatures.
- On Windows, the doctor's downloads, nasm and Strawberry Perl, are pinned the
  same way and fetched by build-php.ps1 (`spc install-pkg --skip-extract`),
  checked against the lock, and only then unpacked where the doctor looks for
  them: `nasm.exe` and `ndisasm.exe` into php-sdk-binary-tools' `bin`, Perl into
  `pkgroot\x86_64-windows\strawberry-perl-x86_64-win` (x86_64 also on Windows on
  Arm, where static-php-cli runs emulated). The doctor then finds both and
  fetches nothing.
- Extensions with libraries: intl builds ICU 78.3 and sqlite3 and pdo_sqlite
  SQLite 3.54.1, which fixes the CVEs published against 3.47.2 to 3.53 (concat_ws
  overflow CVE-2025-29087/3277, CVE-2025-6965, FTS5 CVE-2025-7709 and
  CVE-2026-11822/11824, and the ten CVE-2026-1193xx/119591 fixed in 3.54.0).
  static-php-cli 2.8.5, and its main and v3 branches as of November 2026, still
  run SQLite's `configure` with autoconf's `--with-pic --enable-pic`, which the
  autosetup `configure` of 3.48 and later refuses. build-php.sh unpacks SQLite
  (`spc extract`, after the lock has matched it) and puts a `configure` in front
  of SQLite's that drops these two options, builds autosetup's jimsh with the
  compiler static-php-cli chose, and turns on FTS4, FTS5, R*Tree and Geopoly,
  which 3.47.2 built by default and 3.48 no longer does. check-php.php checks FTS5
  when pdo_sqlite is in. On Windows, static-php-cli builds SQLite with its own
  Makefile (FTS3, FTS4, FTS5), which needs no such fix.
- Windows lock entries (nasm, Strawberry Perl, php-sdk-binary-tools,
  libiconv-win) were recorded from their pinned URLs on macOS and checked against
  the upstream digests. A Windows build of the default extensions matched them
  (`verify-sources: 7 sources match the lock`), and one with intl, sqlite3 and
  pdo_sqlite (`9 sources`): ICU 79.1, SQLite 3.54.1, check-php.php passing.
- intl on Windows does not build ICU: static-php-cli 2.8.5 takes its own build,
  `icu-static-win`, from an unversioned URL
  (`dl.static-php.dev/static-php-cli/deps/icu-static-windows-x64/icu-static-windows-x64.zip`).
  The lock holds the one of 20 November 2026 (ICU 79.1, sha256
  `dcd8f806…6e6891`). The day static-php-cli rebuilds it, the lock refuses the
  build (`not what php-sources.lock pinned`): check what changed, then record
  the new digest (`verify-sources.sh`).
