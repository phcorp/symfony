# The guest side of vm/features.sh, in the user's session (vm/session1.sh): runs the host's --feature-test
# three ways from C:\tuiapp-vm\host-<arch>.zip and kit.zip, and checks what only a second process can see.
# Writes features.txt and features.exit in -Out, the runs' output and PNGs in -Out\features, done.txt last.
param(
    [string] $Out = 'C:\tuiapp-vm\out',
    [string] $Arch = 'arm64',
    [string] $Autoload = '',
    [string] $Php = ''
)
$ErrorActionPreference = 'Stop'
$log = Join-Path $Out 'features.txt'
$results = Join-Path $Out 'features'
$status = 1
$work = $null
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $log, $results
New-Item -ItemType Directory -Force -Path $results | Out-Null

function Say([string] $Line) { Add-Content -Encoding UTF8 -Path $log -Value $Line }
function Fail([string] $Why) { throw "features: FAILED: $Why" }

# One run of the host: it must pass, name the application's process, and leave nothing running from $work.
function Run([string] $Name, [string[]] $HostArgs, [hashtable] $Environment, [string] $Directory) {
    foreach ($k in $Environment.Keys) { Set-Item "env:$k" $Environment[$k] }
    $env:TUI_APP_FEATURE_CAPTURE = Join-Path $results $Name
    try {
        $p = Start-Process (Join-Path $work 'host\TuiAppHost.exe') -ArgumentList $HostArgs -WorkingDirectory $Directory `
            -RedirectStandardOutput "$results\$Name.out" -RedirectStandardError "$results\$Name.err" -PassThru
        $null = $p.Handle
        if (-not $p.WaitForExit(150000)) { $p.Kill(); Fail "$Name still runs after 150 s" }
    } finally {
        foreach ($k in $Environment.Keys) { Remove-Item "env:$k" -ErrorAction SilentlyContinue }
        Remove-Item env:TUI_APP_FEATURE_CAPTURE -ErrorAction SilentlyContinue
    }
    $lines = @(Get-Content -Encoding UTF8 "$results\$Name.out")
    $lines | Where-Object { $_ -like 'feature-test*' } | ForEach-Object { Say "features: ${Name}: $_" }
    if ($p.ExitCode -ne 0) { Get-Content "$results\$Name.err" | ForEach-Object { Say $_ }; Fail "$Name exited with $($p.ExitCode)" }
    if (-not ($lines -match ': ok$')) { Fail "$Name did not say ok" }
    $closed = $lines | Select-String 'the window closed; process (\d+) is gone' | Select-Object -First 1
    if (-not $closed) { Fail "$Name did not name the application's process" }
    $id = [int]$closed.Matches[0].Groups[1].Value
    if (Get-Process -Id $id -ErrorAction SilentlyContinue) { Fail "${Name}: the application's process $id is still there" }
    $left = @(Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -and $_.CommandLine.Contains($work) })
    if ($left.Count -gt 0) { $left | ForEach-Object { Say "  left: $($_.ProcessId) $($_.CommandLine)" }; Fail "$Name left processes behind" }
    Say "features: ${Name}: passed; no process left behind"
    return $lines
}

try {
    Say "features: session $((Get-Process -Id $PID).SessionId), $([Environment]::OSVersion.VersionString) $env:PROCESSOR_ARCHITECTURE, host $Arch"
    $work = Join-Path $env:TEMP ('tuiapp-features-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Force -Path $work | Out-Null
    Expand-Archive "C:\tuiapp-vm\host-$Arch.zip" (Join-Path $work 'host')
    Expand-Archive 'C:\tuiapp-vm\kit.zip' $work
    if ($Php) { $env:Path = (Split-Path $Php) + ';' + $env:Path }
    $phpExe = (Get-Command php -ErrorAction SilentlyContinue).Source
    if (-not $phpExe) { Fail 'no php on PATH: give -Php' }
    Say "features: php $(& $phpExe -n -r 'echo PHP_VERSION;') at $phpExe"

    Say 'features: 1. --dev, stand-in app'
    $null = Run 'dev' @('--feature-test', '--dev', '--', 'php', 'tools\fake-app.php') @{ FAKE_APP_FEATURES = '1'; FAKE_APP_LOG = "$work\dev.app.log" } $work
    $closes = @(Select-String -Path "$work\dev.app.log" -SimpleMatch '"type":"close"').Count
    if ($closes -ne 3) { Fail "the stand-in app got $closes closes, not 3" }

    if ($Autoload -and (Test-Path $Autoload)) {
        Say "features: 2. --dev, a real TuiApp application ($Autoload)"
        $null = Run 'real' @('--feature-test', '--dev', '--', 'php', 'tools\feature-app.php', $Autoload) @{} $work
    } else {
        Say 'features: 2. skipped: no -Autoload of a checkout with the TuiApp component'
    }

    Say 'features: 3. singleInstance and a manifest, launched as the file handler does (-- <file>)'
    New-Item -ItemType Directory -Force -Path "$work\app" | Out-Null
    Copy-Item -Recurse "$work\tools", "$work\contract" "$work\app\"
    $manifest = Get-Content -Raw "$work\smoke-manifest.json" | ConvertFrom-Json
    $manifest.app.singleInstance = $true
    $manifest.launch.env.pass = @('FAKE_APP_LOG', 'FAKE_APP_FEATURES')
    [IO.File]::WriteAllText("$work\app\app.manifest.json", ($manifest | ConvertTo-Json -Depth 10))
    Set-Content -Path "$work\-launch.fake" -Value 'x'
    $lines = Run 'app' @('--feature-test', '--manifest', "$work\app\app.manifest.json", '--', '-launch.fake') @{ FAKE_APP_FEATURES = '1'; FAKE_APP_LOG = "$work\app.app.log" } $work
    if (-not ($lines -like "*: open at launch: $work\-launch.fake")) { Fail 'the file after -- did not arrive at launch' }
    if (-not ($lines -like '*node 2 reads "open: *\-feature-test.fake | fake-tuiapp://feature?id=42"')) { Fail 'the second launch did not hand its file and URL over' }
    if (-not ($lines -like '*set AppsUseLightTheme*')) { Fail 'the appearance did not flip' }

    Say 'features: PASS'
    $status = 0
} catch {
    Say $_.Exception.Message
    if ($_.Exception.Message -notlike 'features: FAILED*') { Say $_.ScriptStackTrace }
} finally {
    if ($work -and (Test-Path $work) -and $work.StartsWith($env:TEMP)) {
        Copy-Item -ErrorAction SilentlyContinue "$work\*.app.log" $results
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $work
    }
    Set-Content -Path (Join-Path $Out 'features.exit') -Value $status
    Set-Content -Path (Join-Path $Out 'done.txt') -Value 'done'
}
