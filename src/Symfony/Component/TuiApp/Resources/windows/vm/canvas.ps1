# The guest side of vm/canvas.sh, in the user's session (vm/session1.sh): the host's --canvas-test from
# C:\tuiapp-vm\host-<arch>.zip and kit.zip, its window brought to the front. Writes canvas.txt and canvas.exit
# in -Out, the test's output, what the application got and the PNGs in -Out\canvas, done.txt last.
# -Dark 1: with the apps' dark mode on (AppsUseLightTheme 0, put back at the end), for the captures.
param(
    [string] $Out = 'C:\tuiapp-vm\out',
    [string] $Arch = 'arm64',
    [string] $Php = '',
    [string] $Dark = ''
)
$ErrorActionPreference = 'Stop'
$log = Join-Path $Out 'canvas.txt'
$results = Join-Path $Out 'canvas'
$status = 1
$work = $null
$p = $null
$personalize = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Themes\Personalize'
$light = $null
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $log, $results
New-Item -ItemType Directory -Force -Path $results | Out-Null

function Say([string] $Line) { Add-Content -Encoding UTF8 -Path $log -Value $Line }
function Fail([string] $Why) { throw "canvas: FAILED: $Why" }

Add-Type -Namespace Canvas -Name Win -MemberDefinition @'
[DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr w);
[DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
[DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr w, out uint pid);
[DllImport("user32.dll")] public static extern bool AttachThreadInput(uint a, uint b, bool attach);
[DllImport("kernel32.dll")] public static extern uint GetCurrentThreadId();
[DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr w, int cmd);
[DllImport("user32.dll")] public static extern bool BringWindowToTop(IntPtr w);
[DllImport("user32.dll")] public static extern bool SetCursorPos(int x, int y);
[DllImport("user32.dll")] public static extern int GetSystemMetrics(int index);
'@

try {
    Say "canvas: session $((Get-Process -Id $PID).SessionId), $([Environment]::OSVersion.VersionString) $env:PROCESSOR_ARCHITECTURE, host $Arch"
    $work = Join-Path $env:TEMP ('tuiapp-canvas-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Force -Path $work | Out-Null
    Expand-Archive "C:\tuiapp-vm\host-$Arch.zip" (Join-Path $work 'host')
    Expand-Archive 'C:\tuiapp-vm\kit.zip' $work
    if ($Php) { $env:Path = (Split-Path $Php) + ';' + $env:Path }
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) { Fail 'no php on PATH: give -Php' }

    if ($Dark -eq '1') {
        $light = (Get-ItemProperty -Path $personalize -Name AppsUseLightTheme -ErrorAction SilentlyContinue).AppsUseLightTheme
        if ($null -eq $light) { $light = 'none' }
        Set-ItemProperty -Path $personalize -Name AppsUseLightTheme -Value 0 -Type DWord
        Say "canvas: apps in dark mode (AppsUseLightTheme was $light)"
    }
    $env:FAKE_APP_CANVAS = '1'
    $env:FAKE_APP_LOG = "$work\canvas.app.log"
    $env:TUI_APP_CANVAS_CAPTURE = $results
    # The cursor out of the way, in the screen's bottom right corner: a run that failed may have left it where the
    # window opens, over the canvas, which would send a move before "canvas ready"
    [void][Canvas.Win]::SetCursorPos([Canvas.Win]::GetSystemMetrics(0) - 1, [Canvas.Win]::GetSystemMetrics(1) - 1)
    $p = Start-Process (Join-Path $work 'host\TuiAppHost.exe') -ArgumentList '--canvas-test', '--dev', '--', 'php', 'tools\fake-app.php' -WorkingDirectory $work `
        -RedirectStandardOutput "$results\canvas.out" -RedirectStandardError "$results\canvas.err" -PassThru
    $null = $p.Handle
    $deadline = (Get-Date).AddSeconds(30)
    do { Start-Sleep -Milliseconds 100; $p.Refresh() } until ($p.MainWindowHandle -ne 0 -or $p.HasExited -or (Get-Date) -gt $deadline)
    if ($p.MainWindowHandle -ne 0) {
        # Into the foreground: attached to the foreground window's input, as the shell lets a process do
        $none = 0
        $foreground = [Canvas.Win]::GetWindowThreadProcessId([Canvas.Win]::GetForegroundWindow(), [ref]$none)
        $me = [Canvas.Win]::GetCurrentThreadId()
        [void][Canvas.Win]::AttachThreadInput($me, $foreground, $true)
        [void][Canvas.Win]::ShowWindow($p.MainWindowHandle, 9); [void][Canvas.Win]::BringWindowToTop($p.MainWindowHandle)
        [void][Canvas.Win]::SetForegroundWindow($p.MainWindowHandle)
        [void][Canvas.Win]::AttachThreadInput($me, $foreground, $false)
        Say "canvas: the window is in the foreground: $([Canvas.Win]::GetForegroundWindow() -eq $p.MainWindowHandle)"
    }
    if (-not $p.WaitForExit(120000)) { $p.Kill(); Fail 'still running after 120 s' }
    Get-Content -Encoding UTF8 "$results\canvas.out" | ForEach-Object { Say $_ }
    if ($p.ExitCode -ne 0) { Get-Content "$results\canvas.err" | ForEach-Object { Say $_ }; Fail "the canvas test exited with $($p.ExitCode)" }
    Start-Sleep -Milliseconds 500
    $left = @(Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -and $_.CommandLine.Contains($work) })
    if ($left.Count -gt 0) { $left | ForEach-Object { Say "  left: $($_.ProcessId) $($_.CommandLine)" }; Fail 'processes left behind' }
    # The password typed is never in what the application logged
    if (Select-String -Path $env:FAKE_APP_LOG -SimpleMatch 's3cret' -Quiet) { Fail 'the password reached the log' }
    # The clipboard message's text is on the guest's clipboard, read by another process once the host is gone; the
    # context menu's choice reached the application
    $clipboard = Get-Clipboard -Raw
    if ($clipboard -ne 'tuiapp canvas clipboard: ok_1') { Fail "the clipboard has '$clipboard', not the clipboard message's text" }
    Say "canvas: the guest's clipboard has '$clipboard' (Get-Clipboard, after the host exited)"
    $chosen = Select-String -Path $env:FAKE_APP_LOG -Pattern '"type":"contextmenu-select","id":"1:2"' -Quiet
    $pasted = Select-String -Path $env:FAKE_APP_LOG -Pattern '"type":"paste","text":"tuiapp canvas clipboard: ok_1"' -Quiet
    if (-not ($chosen -and $pasted)) { Fail "the application did not get contextmenu-select 1:2 ($chosen) and the paste of the clipboard ($pasted)" }
    Say 'canvas: the application got contextmenu-select 1:2 and the paste of the clipboard text'
    Say 'canvas: PASS'
    $status = 0
} catch {
    Say $_.Exception.Message
    if ($_.Exception.Message -notlike 'canvas: FAILED*') { Say $_.ScriptStackTrace }
} finally {
    if ($p -and -not $p.HasExited) { $p.Kill() }
    if ($null -ne $light) {
        if ($light -eq 'none') { Remove-ItemProperty -Path $personalize -Name AppsUseLightTheme -ErrorAction SilentlyContinue }
        else { Set-ItemProperty -Path $personalize -Name AppsUseLightTheme -Value $light -Type DWord }
        Say "canvas: AppsUseLightTheme put back: $((Get-ItemProperty -Path $personalize -Name AppsUseLightTheme -ErrorAction SilentlyContinue).AppsUseLightTheme)"
    }
    if ($work -and (Test-Path $work) -and $work.StartsWith($env:TEMP)) {
        Copy-Item -ErrorAction SilentlyContinue "$work\*.app.log" $results
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $work
    }
    Set-Content -Path (Join-Path $Out 'canvas.exit') -Value $status
    Set-Content -Path (Join-Path $Out 'done.txt') -Value 'done'
}
