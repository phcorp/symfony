<#
Builds the static PHP an embedded TuiApp desktop app ships as runtime\php.exe, for Windows x64,
with static-php-cli. build-php.sh does the same on macOS and Linux.

  build-php.ps1 --version <8.5|8.5.12> --extensions <csv> --out <file>
                [--cache <dir>] [--work <dir>] [--no-doctor-fix]
  build-php.ps1 --list-extensions

Supply chain: spc-windows-x64.exe, php-sdk-binary-tools, nasm and Strawberry Perl are pinned and
their sha256 checked before they run; every source is pinned (php-sources.pins, PHP by version
below) and what was downloaded must match php-sources.lock before anything is built. The lock
format and digests are those of verify-sources.sh.

Needs Windows PowerShell 5.1 or PowerShell 7, Visual Studio 2019 or 2022 with the C++ workload,
and Git for Windows with its usr\bin (patch.exe) in PATH: static-php-cli's doctor checks them.
#>
Set-StrictMode -Version 3.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$SpcVersion = '2.8.5'
$SpcAsset = 'spc-windows-x64.exe'
$SpcSum = '425b54ab857e21409c1fd9b818899ffebabd1e2817ef0a0ed5ae8a3d9f5b463b'
# The PHP release a minor version resolves to. A release can be built only when its tarball,
# php-<version>.tar.xz, is in php-sources.lock (see verify-sources.sh to add one).
$PhpReleases = [ordered]@{ '8.5' = '8.5.13'; '8.4' = '8.4.28' }
# php-sdk-binary-tools, which static-php-cli's doctor would otherwise clone from master.
$SdkCommit = '73f9b80a55bf82cb4d3218c1e985a426ce289367'
# What TuiApp itself needs on Windows (no pcntl, no posix); always built in.
$Required = @('ctype', 'filter', 'iconv', 'mbstring', 'phar', 'tokenizer')
$Os = 'windows'
$Platform = 'Windows-x64'

function Fail([string]$Message, [int]$Code = 2) {
    [Console]::Error.WriteLine("build-php: $Message")
    exit $Code
}

function Show-Usage {
    @'
Usage: build-php.ps1 --version <8.5|8.5.12> --extensions <csv> --out <file> [options]

Builds a static PHP CLI for Windows x64 with static-php-cli and copies it to <file>.
TuiApp's own extensions (ctype, filter, iconv, mbstring, phar, tokenizer) are always included.

Options:
  --version <v>         PHP minor version (resolved to its pinned release) or exact release
  --extensions <csv>    extra extensions, comma separated (may be empty)
  --out <file>          where to copy php.exe
  --cache <dir>         cache root (default: %XDG_CACHE_HOME%\tui-app\php if set, else
                        %LOCALAPPDATA%\tui-app\php); each build is kept in <dir>\<sha256 of its inputs>
  --work <dir>          static-php-cli working folder, kept afterwards
                        (default: <cache>\w\<16 hex>, removed after a successful build)
  --no-doctor-fix       let `spc doctor` only report what is missing (Visual Studio, Git),
                        not try to fix it; the build then stops on it. nasm and Strawberry
                        Perl come from their pins either way
  --list-extensions     print the extensions that build statically on Windows
'@ | Write-Output
}

function Get-FileSha256([string]$Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}

function Get-TextSha256([string]$Text) {
    $bytes = (New-Object System.Text.UTF8Encoding($false)).GetBytes($Text)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        return (($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join '')
    } finally {
        $sha.Dispose()
    }
}

# Runs a native program, its output to the console, and fails on a non-zero exit code. Windows
# PowerShell 5.1 turns native stderr into errors under 'Stop', hence the local 'Continue'.
function Invoke-Native([string]$File, [string[]]$Arguments, [string]$What) {
    $saved = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $File @Arguments 2>&1 | ForEach-Object { Write-Host ([string]$_) }
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $saved
    }
    if ($code -ne 0) {
        Fail "$What failed (exit code $code)" 1
    }
}

function Get-Download([string]$Url, [string]$Path) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $partial = "$Path.partial"
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            Invoke-WebRequest -UseBasicParsing -Uri $Url -OutFile $partial
            Move-Item -Force -LiteralPath $partial -Destination $Path
            return
        } catch {
            if ($attempt -eq 3) {
                Fail "cannot download ${Url}: $($_.Exception.Message)" 1
            }
            Start-Sleep -Seconds (2 * $attempt)
        }
    }
}

function Read-Lines([string]$Path) {
    return @(Get-Content -LiteralPath $Path | ForEach-Object { $_.TrimEnd("`r") })
}

# --- verify-sources.sh, in PowerShell: same digests, same lock -------------------------------

function Get-SourceDigest([string]$Path) {
    $item = Get-Item -LiteralPath $Path -Force
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
        Fail "verify-sources: $($item.Name) is a link" 1
    }
    if (-not $item.PSIsContainer) {
        return Get-FileSha256 $item.FullName
    }
    # A folder (a git checkout): the digest of a listing of its entries, as verify-sources.sh
    # writes it. Windows has no executable bit, so a folder recorded on Unix with executable
    # files does not match: pin sources as archives.
    $root = $item.FullName.TrimEnd('\')
    $lines = New-Object System.Collections.Generic.List[string]
    foreach ($entry in Get-ChildItem -LiteralPath $root -Recurse -Force) {
        $relative = './' + $entry.FullName.Substring($root.Length + 1).Replace('\', '/')
        if ($relative -eq './.git' -or $relative.StartsWith('./.git/')) {
            continue
        }
        if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            Fail "verify-sources: $relative in $($item.Name) is a link, which this script does not verify" 1
        }
        if (-not $entry.PSIsContainer) {
            $lines.Add($relative)
        }
    }
    $sorted = $lines.ToArray()
    [Array]::Sort($sorted, [StringComparer]::Ordinal)
    $description = New-Object System.Text.StringBuilder
    foreach ($relative in $sorted) {
        $file = Join-Path $root ($relative.Substring(2).Replace('/', '\'))
        [void]$description.Append("file - $relative $(Get-FileSha256 $file)`n")
    }
    return Get-TextSha256 $description.ToString()
}

function Test-Sources([string]$Downloads, [string]$Lock) {
    $actual = New-Object System.Collections.Generic.List[string]
    foreach ($entry in Get-ChildItem -LiteralPath $Downloads -Force | Where-Object { -not $_.Name.StartsWith('.') }) {
        $actual.Add("$(Get-SourceDigest $entry.FullName)  $($entry.Name)")
    }
    $sorted = $actual.ToArray()
    $names = @($sorted | ForEach-Object { $_.Substring(66) })
    [Array]::Sort($names, $sorted, [StringComparer]::Ordinal)

    if ($env:TUI_APP_SOURCES_RECORD) {
        [IO.File]::WriteAllText($env:TUI_APP_SOURCES_RECORD, (($sorted | ForEach-Object { "$_`n" }) -join ''), (New-Object System.Text.UTF8Encoding($false)))
        Write-Host "verify-sources: recorded $($sorted.Count) sources in $($env:TUI_APP_SOURCES_RECORD), verified nothing"
        return
    }
    $expected = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
    foreach ($line in Read-Lines $Lock) {
        [void]$expected.Add($line)
    }
    $unknown = @($sorted | Where-Object { -not $expected.Contains($_) })
    if ($unknown.Count -gt 0) {
        [Console]::Error.WriteLine('verify-sources: not what php-sources.lock pinned:')
        $unknown | ForEach-Object { [Console]::Error.WriteLine($_) }
        [Console]::Error.WriteLine('A source that is not pinned yet (an extension that needs a new library) must be')
        [Console]::Error.WriteLine('pinned in php-sources.pins and recorded in the lock: see runtime/verify-sources.sh.')
        exit 1
    }
    Write-Host "verify-sources: $($sorted.Count) sources match the lock"
}

function Assert-Locked([string]$Path, [string]$Lock) {
    $line = "$(Get-FileSha256 $Path)  $(Split-Path -Leaf $Path)"
    if ($env:TUI_APP_SOURCES_RECORD) {
        Write-Host "verify-sources: not verified (recording): $line"
        return
    }
    if ((Read-Lines $Lock) -notcontains $line) {
        Fail "verify-sources: not what php-sources.lock pinned: $line" 1
    }
}

# The URL php-sources.pins gives a source or a tool.
function Get-Pinned([string]$Name) {
    foreach ($line in Read-Lines $Pins) {
        if ($line -match '^\s*(#|$)') { continue }
        $fields = $line.Trim() -split '\s+'
        if ($fields[0] -eq $Name) { return $fields[1] }
    }
    Fail "$Name is not pinned in php-sources.pins"
}

# A tool static-php-cli's doctor would fetch and unpack: fetched from its pin, not unpacked, and
# checked against the lock. Returns the archive.
function Install-PinnedTool([string]$Name) {
    $url = Get-Pinned $Name
    Invoke-Native $spc @('install-pkg', $Name, "--custom-url=${Name}:$url", '--skip-extract', '--no-alt') "spc install-pkg $Name"
    $archive = Join-Path $Work ('downloads\' + $url.Substring($url.LastIndexOf('/') + 1))
    Assert-Locked $archive $Lock
    return $archive
}

# --- Arguments ----------------------------------------------------------------------------

$Version = ''; $Extensions = $null; $Out = ''; $Cache = ''; $Work = ''; $DoctorFix = $true; $List = $false
# Case-insensitive, as PowerShell's own parameters.
$PowerShellNames = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([StringComparer]::OrdinalIgnoreCase)
foreach ($pair in @(@('-Version', '--version'), @('-Extensions', '--extensions'), @('-Out', '--out'), @('-Cache', '--cache'), @('-Work', '--work'), @('-NoDoctorFix', '--no-doctor-fix'), @('-ListExtensions', '--list-extensions'))) {
    $PowerShellNames[$pair[0]] = $pair[1]
}
$i = 0
while ($i -lt $args.Count) {
    $argument = [string]$args[$i]
    $name = $argument; $value = $null
    if ($argument -match '^(--[a-z-]+)=(.*)$') {
        $name = $Matches[1]; $value = $Matches[2]
    } elseif ($PowerShellNames.ContainsKey($argument)) {
        # PowerShell's spelling, as TuiApp's builder passes it: -Version 8.5 -Extensions a,b -Out x
        $name = $PowerShellNames[$argument]
    }
    switch -CaseSensitive ($name) {
        { $_ -in @('--version', '--extensions', '--out', '--cache', '--work') } {
            if ($null -eq $value) {
                if ($i + 1 -ge $args.Count) { Fail "$name needs a value" }
                $i++
                $value = [string]$args[$i]
            }
            switch ($name) {
                '--version' { $Version = $value }
                '--extensions' { $Extensions = $value }
                '--out' { $Out = $value }
                '--cache' { $Cache = $value }
                '--work' { $Work = $value }
            }
            break
        }
        '--no-doctor-fix' { $DoctorFix = $false; break }
        '--list-extensions' { $List = $true; break }
        { $_ -in @('-h', '--help') } { Show-Usage; exit 0 }
        default { Fail "unknown argument: $argument (see --help)" }
    }
    $i++
}

if (-not [Environment]::Is64BitOperatingSystem) {
    Fail "static-php-cli $SpcVersion builds 64-bit PHP only; this Windows is 32-bit"
}

$Known = [ordered]@{}
foreach ($line in Read-Lines (Join-Path $Here 'static-extensions.list')) {
    if ($line -match '^\s*(#|$)') { continue }
    $fields = $line -split '\s+'
    $Known[$fields[0]] = @($fields[1] -split ',')
}
$Available = @($Known.Keys | Where-Object { $Known[$_] -contains $Os })

if ($List) {
    $Available | Write-Output
    exit 0
}

if (-not $Version) { Fail '--version is required (for example --version 8.5)' }
if ($null -eq $Extensions) { Fail '--extensions is required (it may be empty: --extensions "")' }
if (-not $Out) { Fail '--out is required' }
if (Test-Path -LiteralPath $Out -PathType Container) { Fail "--out names a folder: $Out" }

# The PHP release.
$Lock = Join-Path $Here 'php-sources.lock'
if ($Version -notmatch '^\d+\.\d+(\.\d+)?$') { Fail "--version must look like 8.5 or 8.5.12, not $Version" }
if ($Version -match '^\d+\.\d+$') {
    if (-not $PhpReleases.Contains($Version)) {
        Fail "PHP $Version is not pinned: build-php.ps1 pins $(@($PhpReleases.Keys) -join ', '). To add it, put its latest release in `$PhpReleases and its tarball in php-sources.lock (see verify-sources.sh)."
    }
    $Php = $PhpReleases[$Version]
} else {
    $Php = $Version
}
$lockLines = Read-Lines $Lock
if (-not ($lockLines | Where-Object { $_.EndsWith("  php-$Php.tar.xz") })) {
    $pinned = @($lockLines | Where-Object { $_ -match '  php-(.+)\.tar\.xz$' } | ForEach-Object { $_ -replace '^.*  php-(.+)\.tar\.xz$', '$1' }) -join ', '
    Fail "PHP $Php is not in php-sources.lock: pinned releases are $pinned. Record php-$Php.tar.xz in the lock first (see verify-sources.sh)."
}

# The extensions: TuiApp's own, plus the requested ones, each known to build statically here.
$requested = @($Extensions -split ',' | ForEach-Object { $_.Trim().ToLowerInvariant() } | Where-Object { $_ })
# Unix only, and in TuiApp's default extensions: an app configured once for every platform asks
# for them here too. TuiApp does without them on Windows.
$unix = @($requested | Where-Object { $_ -in @('pcntl', 'posix') })
if ($unix.Count -gt 0) {
    Write-Host "build-php: left out, Unix only: $($unix -join ', ')"
    $requested = @($requested | Where-Object { $_ -notin $unix })
}
$all = @(@($Required) + $requested | Sort-Object -Unique)
[Array]::Sort($all, [StringComparer]::Ordinal)
$unknown = @(); $elsewhere = @()
foreach ($extension in $all) {
    if ($extension -notmatch '^[a-z0-9_-]+$') { Fail "not an extension name: $extension" }
    if (-not $Known.Contains($extension)) {
        $unknown += $extension
    } elseif ($Known[$extension] -notcontains $Os) {
        $elsewhere += "$extension (only $($Known[$extension] -join ','))"
    }
}
if ($unknown.Count -gt 0 -or $elsewhere.Count -gt 0) {
    if ($unknown.Count -gt 0) {
        [Console]::Error.WriteLine("build-php: unknown extension(s): $($unknown -join ' ')")
        foreach ($name in $unknown) {
            $like = @($Available | Where-Object {
                $_.Contains($name) -or $name.Contains($_) -or ($name.Length -gt 3 -and $_.Length -gt 3 -and $_.Substring(0, 4) -eq $name.Substring(0, 4))
            })
            if ($like.Count -gt 0) { [Console]::Error.WriteLine("  ${name}: did you mean $($like -join ' ')?") }
        }
    }
    if ($elsewhere.Count -gt 0) {
        [Console]::Error.WriteLine("build-php: not available as a static extension on ${Os}: $($elsewhere -join ' ')")
    }
    [Console]::Error.WriteLine("static-php-cli $SpcVersion builds $($Available.Count) extensions statically on ${Os}: build-php.ps1 --list-extensions lists them.")
    exit 2
}
$ExtensionList = $all -join ','

# The cache entry: one per set of inputs, keyed as build-php.sh keys it.
$Pins = Join-Path $Here 'php-sources.pins'
$inputs = "spc $SpcVersion`nphp $Php`nextensions $ExtensionList`npins $(Get-FileSha256 $Pins)`nplatform $Platform`n"
$Key = Get-TextSha256 $inputs
if (-not $Cache) {
    if ($env:XDG_CACHE_HOME) { $Cache = Join-Path $env:XDG_CACHE_HOME 'tui-app\php' } else { $Cache = Join-Path $env:LOCALAPPDATA 'tui-app\php' }
}
$Entry = Join-Path $Cache $Key
$outDirectory = Split-Path -Parent $Out
if ($outDirectory) { New-Item -ItemType Directory -Force -Path $outDirectory | Out-Null }
$Out = Join-Path (Resolve-Path -LiteralPath $(if ($outDirectory) { $outDirectory } else { '.' })).ProviderPath (Split-Path -Leaf $Out)
$Check = Join-Path $Here 'check-php.php'

function Install-Binary([string]$From) {
    $partial = "$Out.partial"
    Copy-Item -Force -LiteralPath $From -Destination $partial
    Move-Item -Force -LiteralPath $partial -Destination $Out
}

$cached = Join-Path $Entry 'php.exe'
$cachedSum = Join-Path $Entry 'php.exe.sha256'
if ((Test-Path -LiteralPath $cached) -and (Test-Path -LiteralPath $cachedSum)) {
    if ((Get-FileSha256 $cached) -eq (Get-Content -LiteralPath $cachedSum -Raw).Trim()) {
        Install-Binary $cached
        Invoke-Native $Out @('-n', $Check, $Php, $ExtensionList) 'check-php.php'
        Write-Host "build-php: PHP $Php from the cache ($Entry) -> $Out"
        exit 0
    }
    [Console]::Error.WriteLine("build-php: $cached does not match its digest: building it again")
}

# One build per cache entry at a time.
New-Item -ItemType Directory -Force -Path $Entry | Out-Null
$building = Join-Path $Entry '.building'
try {
    New-Item -ItemType Directory -Path $building -ErrorAction Stop | Out-Null
} catch {
    $holder = Get-Content -LiteralPath (Join-Path $building 'pid') -ErrorAction SilentlyContinue
    if ($holder -and (Get-Process -Id ([int]$holder) -ErrorAction SilentlyContinue)) {
        Fail "another build (process $holder) is filling $Entry"
    }
    Remove-Item -Recurse -Force -LiteralPath $building
    New-Item -ItemType Directory -Path $building | Out-Null
}
Set-Content -LiteralPath (Join-Path $building 'pid') -Value $PID

$started = Get-Date
$keepWork = [bool]$Work
try {
    if (-not $Work) {
        # Short: MSVC and php-sdk builds break past MAX_PATH.
        $Work = Join-Path (Join-Path $Cache 'w') $Key.Substring(0, 16)
    }
    New-Item -ItemType Directory -Force -Path $Work | Out-Null
    $Work = (Resolve-Path -LiteralPath $Work).ProviderPath
    if ($Work -match '\s') {
        Fail "the work folder must not contain spaces (PHP's build does not support them): $Work; choose one with --work or --cache"
    }
    Write-Host "build-php: building PHP $Php with $ExtensionList for $Platform in $Work"
    Set-Location -LiteralPath $Work

    # The builder is pinned and checked before it runs: it writes the PHP every app ships.
    $spc = Join-Path $Work 'spc.exe'
    if (-not (Test-Path -LiteralPath $spc) -or (Get-FileSha256 $spc) -ne $SpcSum) {
        Get-Download "https://github.com/crazywhalecc/static-php-cli/releases/download/$SpcVersion/$SpcAsset" $spc
    }
    $actual = Get-FileSha256 $spc
    if ($actual -ne $SpcSum) {
        Remove-Item -Force -LiteralPath $spc
        Fail "$SpcAsset has sha256 $actual, expected $SpcSum"
    }

    # php-sdk-binary-tools at a pinned commit, where static-php-cli looks for it, so its doctor
    # does not clone master.
    $env:PHP_SDK_PATH = Join-Path $Work 'php-sdk-binary-tools'
    if (-not (Test-Path -LiteralPath (Join-Path $env:PHP_SDK_PATH 'phpsdk-starter.bat'))) {
        $tools = Join-Path $Work 'tools'
        New-Item -ItemType Directory -Force -Path $tools | Out-Null
        $sdkZip = Join-Path $tools "php-sdk-binary-tools-$SdkCommit.zip"
        if (-not (Test-Path -LiteralPath $sdkZip)) {
            Get-Download "https://github.com/php/php-sdk-binary-tools/archive/$SdkCommit.zip" $sdkZip
        }
        Assert-Locked $sdkZip $Lock
        $unpacked = Join-Path $tools 'sdk'
        if (Test-Path -LiteralPath $unpacked) { Remove-Item -Recurse -Force -LiteralPath $unpacked }
        Expand-Archive -LiteralPath $sdkZip -DestinationPath $unpacked
        if (Test-Path -LiteralPath $env:PHP_SDK_PATH) { Remove-Item -Recurse -Force -LiteralPath $env:PHP_SDK_PATH }
        Move-Item -LiteralPath (Join-Path $unpacked "php-sdk-binary-tools-$SdkCommit") -Destination $env:PHP_SDK_PATH
    }
    # On Windows on Arm, phpsdk_setshell.bat names the host after the target: vcvarsall.bat x64_amd64,
    # which it refuses. The host is the OS's: arm64_amd64, Visual Studio's ARM64-hosted x64 compiler.
    # (An x64 Windows takes the branch above it and is unchanged.)
    $setshell = Join-Path $env:PHP_SDK_PATH 'bin\phpsdk_setshell.bat'
    $latin1 = [Text.Encoding]::GetEncoding(28591)
    $shellText = [IO.File]::ReadAllText($setshell, $latin1)
    $wrongHost = "`tset HOST_ARCH_NAME=%PHP_SDK_ARCH%`r`n"
    if ($shellText.Contains($wrongHost)) {
        [IO.File]::WriteAllText($setshell, $shellText.Replace($wrongHost, "`tset HOST_ARCH_NAME=%PHP_SDK_OS_ARCH%`r`n"), $latin1)
    }

    # nasm and Strawberry Perl, which `spc doctor --auto-fix` would download and unpack at once:
    # fetched here from their pins, without unpacking, checked against the lock, and only then
    # unpacked where the doctor looks for them (nasm.exe in php-sdk-binary-tools\bin, Perl in
    # pkgroot\x86_64-windows: static-php-cli runs as x64, even on Windows on Arm). The doctor
    # then finds both and fetches nothing.
    $nasm = Install-PinnedTool 'nasm-x86_64-win'
    $sdkBin = Join-Path $env:PHP_SDK_PATH 'bin'
    if (-not (Test-Path -LiteralPath (Join-Path $sdkBin 'nasm.exe')) -or -not (Test-Path -LiteralPath (Join-Path $sdkBin 'ndisasm.exe'))) {
        $unpacked = Join-Path $Work 'tools\nasm'
        if (Test-Path -LiteralPath $unpacked) { Remove-Item -Recurse -Force -LiteralPath $unpacked }
        Expand-Archive -LiteralPath $nasm -DestinationPath $unpacked
        foreach ($tool in @('nasm.exe', 'ndisasm.exe')) {
            $found = @(Get-ChildItem -LiteralPath $unpacked -Recurse -Filter $tool)
            if ($found.Count -ne 1) { Fail "$(Split-Path -Leaf $nasm) holds $($found.Count) $tool" 1 }
            Move-Item -Force -LiteralPath $found[0].FullName -Destination (Join-Path $sdkBin $tool)
        }
        Remove-Item -Recurse -Force -LiteralPath $unpacked
    }
    $perl = Install-PinnedTool 'strawberry-perl-x86_64-win'
    $perlRoot = Join-Path $Work 'pkgroot\x86_64-windows\strawberry-perl-x86_64-win'
    if (-not (Test-Path -LiteralPath (Join-Path $perlRoot 'perl\bin\perl.exe'))) {
        $partial = "$perlRoot.partial"
        if (Test-Path -LiteralPath $partial) { Remove-Item -Recurse -Force -LiteralPath $partial }
        if (Test-Path -LiteralPath $perlRoot) { Remove-Item -Recurse -Force -LiteralPath $perlRoot }
        Expand-Archive -LiteralPath $perl -DestinationPath $partial
        Move-Item -LiteralPath $partial -Destination $perlRoot
    }

    if ($DoctorFix) {
        Invoke-Native $spc @('doctor', '--auto-fix') 'spc doctor'
    } else {
        $saved = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        & $spc doctor '--auto-fix=never' 2>&1 | ForEach-Object { Write-Host ([string]$_) }
        $code = $LASTEXITCODE
        $ErrorActionPreference = $saved
        if ($code -ne 0) {
            Fail 'static-php-cli cannot build here yet. Install what it lists (Visual Studio 2019/2022 with C++, Git for Windows with usr\bin in PATH).'
        }
    }

    # Every source at its pinned version (php-sources.pins).
    $customUrls = New-Object System.Collections.Generic.List[string]
    $fileNames = @{}
    foreach ($line in Read-Lines $Pins) {
        if ($line -match '^\s*(#|$)') { continue }
        $fields = $line.Trim() -split '\s+'
        $file = $fields[1].Substring($fields[1].LastIndexOf('/') + 1)
        if ($fileNames.ContainsKey($file)) { Fail "two pinned sources share a file name: $file" }
        $fileNames[$file] = $true
        $customUrls.Add("--custom-url=$($fields[0]):$($fields[1])")
    }
    Invoke-Native $spc (@('download', "--with-php=$Php", "--for-extensions=$ExtensionList", '--without-suggestions', '--no-alt', '--retry=2') + $customUrls.ToArray()) 'spc download'
    Test-Sources (Join-Path $Work 'downloads') $Lock

    # Reproducible: two builds of the same inputs in the same work folder give the same bytes.
    # MSVC ignores SOURCE_DATE_EPOCH: PHP's __DATE__ and __TIME__ (PHP_BUILD_DATE, `php -v`, the
    # system id of opcache) become that date's literals in the unpacked source, by default the
    # PHP release's (its main\php_version.h). static-php-cli keeps the folder: its .spc-hash is
    # the archive's. /Brepro, added to PHP's flags, makes the compiler and linker write no time
    # stamps (the PE header's among them), and PHP_BUILD_SYSTEM replaces the Windows edition and
    # build configure.js would record.
    Invoke-Native $spc @('extract', 'php-src', '--source-only') 'spc extract php-src'
    $phpSource = Join-Path $Work 'source\php-src'
    if ($env:SOURCE_DATE_EPOCH) {
        if ($env:SOURCE_DATE_EPOCH -notmatch '^\d+$') { Fail "SOURCE_DATE_EPOCH must be a number of seconds, not $($env:SOURCE_DATE_EPOCH)" }
        $buildTime = [DateTimeOffset]::FromUnixTimeSeconds([long]$env:SOURCE_DATE_EPOCH).UtcDateTime
    } else {
        $buildTime = (Get-Item -LiteralPath (Join-Path $phpSource 'main\php_version.h')).LastWriteTimeUtc
    }
    $invariant = [Globalization.CultureInfo]::InvariantCulture
    # As __DATE__ and __TIME__ write it: "Nov  1 2026", "09:05:00".
    $dateLiteral = '"' + $buildTime.ToString('MMM', $invariant) + ' ' + $buildTime.Day.ToString($invariant).PadLeft(2) + ' ' + $buildTime.ToString('yyyy', $invariant) + '"'
    $timeLiteral = '"' + $buildTime.ToString('HH:mm:ss', $invariant) + '"'
    $stampFile = Join-Path $phpSource '.tui-app-build-date'
    $stamp = "$dateLiteral $timeLiteral"
    if ((Test-Path -LiteralPath $stampFile) -and ([IO.File]::ReadAllText($stampFile, $latin1) -ne $stamp)) {
        # Written for another date: unpacked again.
        Remove-Item -Recurse -Force -LiteralPath $phpSource
        Invoke-Native $spc @('extract', 'php-src', '--source-only') 'spc extract php-src'
    }
    # Written first: a run stopped before the end does not leave the source dated otherwise.
    [IO.File]::WriteAllText($stampFile, $stamp, $latin1)
    # Where PHP uses them: 8.5 in main.c and zend_system_id.c, its master branch in info.c too.
    $dated = @()
    foreach ($relative in @('main\main.c', 'Zend\zend_system_id.c', 'ext\standard\info.c')) {
        $file = Join-Path $phpSource $relative
        if (-not (Test-Path -LiteralPath $file)) { continue }
        $text = [IO.File]::ReadAllText($file, $latin1)
        if ($text.Contains('__DATE__') -or $text.Contains('__TIME__')) {
            [IO.File]::WriteAllText($file, $text.Replace('__DATE__', $dateLiteral).Replace('__TIME__', $timeLiteral), $latin1)
            $dated += $relative
        }
    }
    Write-Host "build-php: build date $stamp$(if ($dated.Count -gt 0) { ' in ' + ($dated -join ', ') })"
    $confutils = Join-Path $phpSource 'win32\build\confutils.js'
    $confText = [IO.File]::ReadAllText($confutils, $latin1)
    $buildSystem = 'AC_DEFINE("PHP_BUILD_SYSTEM", os.Caption + " [" + os.Version + "]"'
    if ($confText.Contains($buildSystem)) {
        [IO.File]::WriteAllText($confutils, $confText.Replace($buildSystem, 'AC_DEFINE("PHP_BUILD_SYSTEM", WshShell.Environment("Process").Item("PHP_BUILD_SYSTEM") || (os.Caption + " [" + os.Version + "]")'), $latin1)
    }
    $env:PHP_BUILD_SYSTEM = 'Windows x64'
    # PHP's own compile and link only: with CL and LINK set to /Brepro for the whole build, the
    # libraries' msbuild projects fail (libiconv-win: LNK1103, debugging information corrupt).
    $configW32 = Join-Path $phpSource 'win32\build\config.w32'
    $configText = [IO.File]::ReadAllText($configW32, $latin1)
    if (-not $configText.Contains('/Brepro')) {
        [IO.File]::WriteAllText($configW32, $configText + "`r`n// build-php.ps1: no time stamps`r`nADD_FLAG(`"CFLAGS`", `"/Brepro `");`r`nADD_FLAG(`"LDFLAGS`", `"/Brepro `");`r`nADD_FLAG(`"ARFLAGS`", `"/Brepro `");`r`n", $latin1)
    }
    Invoke-Native $spc @('build', $ExtensionList, '--build-cli') 'spc build'
    $built = Join-Path $Work 'buildroot\bin\php.exe'
    Invoke-Native $built @('-n', $Check, $Php, $ExtensionList) 'check-php.php'

    # Into the cache, then out.
    $partial = Join-Path $Entry 'php.exe.partial'
    Copy-Item -Force -LiteralPath $built -Destination $partial
    Set-Content -LiteralPath $cachedSum -Value (Get-FileSha256 $partial) -Encoding Ascii
    Move-Item -Force -LiteralPath $partial -Destination $cached
    [IO.File]::WriteAllText((Join-Path $Entry 'inputs.txt'), $inputs, (New-Object System.Text.UTF8Encoding($false)))
    Install-Binary $cached
    Set-Location -LiteralPath $Entry
    if (-not $keepWork) {
        Remove-Item -Recurse -Force -LiteralPath $Work
    }
    Write-Host "build-php: built PHP $Php in $([int]((Get-Date) - $started).TotalSeconds) s -> $Out (cached in $Entry)"
} finally {
    Remove-Item -Recurse -Force -LiteralPath $building -ErrorAction SilentlyContinue
}
