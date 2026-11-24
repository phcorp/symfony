# Runs in the Windows user's session (a scheduled task started by vm/run.sh, which pushed it into the guest as
# tuiapp-test\guest-<Run>-<attempt>.ps1): fetches the host, PHP and
# the fake app from the Mac, runs the self-test, then a normal launch for a screenshot of the window.
# Everything lives in %USERPROFILE%\tuiapp-test; results in its out-<Run>\ folder, done.txt last. A folder of its
# own per run: the guest agent may keep files it read open, which an earlier run's results could not be replaced
# then (they passed for this run's).
param([string] $Base = 'http://192.168.64.1:8765', [string] $Run = '')
$ErrorActionPreference = 'Stop'
$root = Join-Path $env:USERPROFILE 'tuiapp-test'
if ($Run -notmatch '^[A-Za-z0-9]+$') { throw "a run id of letters and digits, not '$Run'" }
# Earlier runs' folders and scripts, those that can go
Get-ChildItem -Directory -ErrorAction SilentlyContinue $root | Where-Object { $_.Name -eq 'out' -or $_.Name -like 'out-*' } |
    ForEach-Object { Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $_.FullName }
Get-ChildItem -File -ErrorAction SilentlyContinue $root | Where-Object { ($_.Name -like 'guest-*.ps1' -and $_.Name -notlike "guest-$Run-*") -or ($_.Name -like 'task-*.txt' -and $_.Name -notlike "task-$Run-*") } |
    ForEach-Object { Remove-Item -Force -ErrorAction SilentlyContinue $_.FullName }
$out = Join-Path $root "out-$Run"
New-Item -ItemType Directory -Force -Path $out | Out-Null
Start-Transcript -Path (Join-Path $out 'guest.txt') -Force | Out-Null
try {
    function Fetch([string] $Name) {
        Invoke-WebRequest "$Base/$Name" -OutFile (Join-Path $root $Name) -UseBasicParsing
    }

    if (-not (Test-Path "$root\php\php.exe")) {
        Fetch 'php.zip'
        Expand-Archive "$root\php.zip" "$root\php" -Force
    }
    foreach ($part in 'host', 'kit') {
        Fetch "$part.zip"
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue "$root\$part"
        Expand-Archive "$root\$part.zip" "$root\$part" -Force
    }

    # No double quotes in the code: Windows PowerShell 5.1 drops them when it starts php.exe
    "php: " + (& "$root\php\php.exe" -n -r 'echo PHP_VERSION, chr(32), php_uname(chr(109));')
    "os: $([Environment]::OSVersion.VersionString) $env:PROCESSOR_ARCHITECTURE"
    $env:Path = "$root\php;$env:Path"
    Set-Location "$root\kit"
    $hostExe = "$root\host\TuiAppHost.exe"
    $log = Join-Path $env:LOCALAPPDATA 'dev.tuiapp.host\logs\app.log'
    Remove-Item -Force -ErrorAction SilentlyContinue $log

    # 1. The acceptance test.
    $clock = [Diagnostics.Stopwatch]::StartNew()
    $test = Start-Process $hostExe -ArgumentList '--self-test', '--dev', '--', 'php', 'tools\fake-app.php' `
        -RedirectStandardOutput "$out\selftest.txt" -RedirectStandardError "$out\selftest-err.txt" -PassThru -Wait
    # ASCII: Tee-Object writes UTF-16 in Windows PowerShell 5.1, which run.sh cannot grep
    $line = "self-test exit $($test.ExitCode) after $([int]$clock.Elapsed.TotalSeconds) s"
    Write-Output $line
    Set-Content -Encoding Ascii -Path "$out\selftest-exit.txt" -Value $line

    # 2. A normal launch: screenshot, then the close button.
    & "$root\kit\vm\shot.ps1" -HostExe $hostExe -Out "$out\screenshot.png"

    if (Test-Path $log) { Copy-Item $log "$out\app.log" }
} catch {
    "guest: FAIL: $_"
    $_.ScriptStackTrace
} finally {
    Stop-Transcript | Out-Null
    Set-Content -Path (Join-Path $out 'done.txt') -Value 'done'
}
