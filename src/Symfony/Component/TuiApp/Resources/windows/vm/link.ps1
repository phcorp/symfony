# The guest side of vm/link.sh, in the user's session (vm/session1.sh): the host from C:\tuiapp-vm\host-<arch>.zip
# runs vm/link-app.php, a real TuiApp application, with the kit vm/link-kit.php made (C:\tuiapp-vm\link-kit.zip). As a
# user: a right click on its link (the mouse, through Windows), Copy Link Address in the context menu the host shows
# (found by UI Automation, clicked), the clipboard read with Get-Clipboard; a right click again, Open Link, the
# application's log read for the link it followed. Then the close button; no process may be left.
# Writes link.txt and link.exit in -Out, the host's and the application's logs in -Out\link, done.txt last.
param(
    [string] $Out = 'C:\tuiapp-vm\out',
    [string] $Arch = 'arm64',
    [string] $Php = ''
)
$ErrorActionPreference = 'Stop'
$log = Join-Path $Out 'link.txt'
$results = Join-Path $Out 'link'
$status = 1
$work = $null
$p = $null
$href = 'https://example.com/docs'
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $log, $results
New-Item -ItemType Directory -Force -Path $results | Out-Null

function Say([string] $Line) { Add-Content -Encoding UTF8 -Path $log -Value $Line }
function Fail([string] $Why) { throw "link: FAILED: $Why" }

Add-Type -AssemblyName UIAutomationClient, UIAutomationTypes
Add-Type -Namespace Link -Name Win -MemberDefinition @'
[DllImport("user32.dll")] public static extern bool SetProcessDpiAwarenessContext(IntPtr context);
[DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr w);
[DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
[DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr w, out uint pid);
[DllImport("user32.dll")] public static extern bool AttachThreadInput(uint a, uint b, bool attach);
[DllImport("kernel32.dll")] public static extern uint GetCurrentThreadId();
[DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr w, int cmd);
[DllImport("user32.dll")] public static extern bool BringWindowToTop(IntPtr w);
[DllImport("user32.dll")] public static extern bool SetCursorPos(int x, int y);
[DllImport("user32.dll")] public static extern int GetSystemMetrics(int index);
[DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr w, out RECT r);
[DllImport("user32.dll")] public static extern void mouse_event(uint flags, int dx, int dy, int data, UIntPtr extra);
[StructLayout(LayoutKind.Sequential)] public struct RECT { public int Left, Top, Right, Bottom; }
'@
# Pixels, as UI Automation gives its rectangles
[void][Link.Win]::SetProcessDpiAwarenessContext([IntPtr](-4))

function Click([int] $X, [int] $Y, [string] $Button) {
    [void][Link.Win]::SetCursorPos($X, $Y)
    Start-Sleep -Milliseconds 200
    $down, $up = if ($Button -eq 'right') { 0x0008, 0x0010 } else { 0x0002, 0x0004 }
    [Link.Win]::mouse_event($down, 0, 0, 0, [UIntPtr]::Zero)
    Start-Sleep -Milliseconds 100
    [Link.Win]::mouse_event($up, 0, 0, 0, [UIntPtr]::Zero)
}

# A menu item of the host's context menu, by its name: its pop-up is a window of the host's process
function MenuItem([int] $ProcessId, [string] $Name) {
    $deadline = (Get-Date).AddSeconds(8)
    $condition = New-Object System.Windows.Automation.AndCondition(
        (New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, $Name)),
        (New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ControlTypeProperty, [System.Windows.Automation.ControlType]::MenuItem)))
    $mine = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ProcessIdProperty, $ProcessId)
    do {
        foreach ($window in [System.Windows.Automation.AutomationElement]::RootElement.FindAll([System.Windows.Automation.TreeScope]::Children, $mine)) {
            $item = $window.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $condition)
            if ($item) { return $item }
        }
        Start-Sleep -Milliseconds 250
    } until ((Get-Date) -gt $deadline)
    return $null
}

# A right click on the link, then a click on that item of the menu
function Choose([string] $Name) {
    $r = New-Object Link.Win+RECT
    [void][Link.Win]::GetWindowRect($p.MainWindowHandle, [ref]$r)
    $x = [int](($r.Left + $r.Right) / 2); $y = [int](($r.Top + $r.Bottom) / 2)
    Click $x $y 'right'
    $item = MenuItem $p.Id $Name
    if (-not $item) { Fail "no $Name in a context menu after a right click at $x, $y (the middle of the window)" }
    $box = $item.Current.BoundingRectangle
    Say "link: right click at $x, $y; $Name in the menu at $([int]$box.X), $([int]$box.Y) $([int]$box.Width)x$([int]$box.Height), clicked"
    Click ([int]($box.X + $box.Width / 2)) ([int]($box.Y + $box.Height / 2)) 'left'
    Start-Sleep -Milliseconds 1500
}

try {
    Say "link: session $((Get-Process -Id $PID).SessionId), $([Environment]::OSVersion.VersionString) $env:PROCESSOR_ARCHITECTURE, host $Arch"
    $work = Join-Path $env:TEMP ('tuiapp-link-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Force -Path $work | Out-Null
    Expand-Archive "C:\tuiapp-vm\host-$Arch.zip" (Join-Path $work 'host')
    Expand-Archive 'C:\tuiapp-vm\link-kit.zip' $work
    if ($Php) { $env:Path = (Split-Path $Php) + ';' + $env:Path }
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) { Fail 'no php on PATH: give -Php' }

    # Not what this run's Copy Link Address puts there
    Set-Clipboard -Value 'vm/link.ps1: before Copy Link Address'
    $env:LINK_APP_LOG = "$work\link-app.log"
    [void][Link.Win]::SetCursorPos([Link.Win]::GetSystemMetrics(0) - 1, [Link.Win]::GetSystemMetrics(1) - 1)
    $p = Start-Process (Join-Path $work 'host\TuiAppHost.exe') -ArgumentList '--dev', '--', 'php', 'link-app.php', 'kit\autoload.php' -WorkingDirectory $work `
        -RedirectStandardOutput "$results\host.out" -RedirectStandardError "$results\host.err" -PassThru
    $null = $p.Handle
    $deadline = (Get-Date).AddSeconds(30)
    do { Start-Sleep -Milliseconds 100; $p.Refresh() } until ($p.MainWindowHandle -ne 0 -or $p.HasExited -or (Get-Date) -gt $deadline)
    if ($p.MainWindowHandle -eq 0) { Fail "no window (exited: $($p.HasExited))" }
    $none = 0
    $foreground = [Link.Win]::GetWindowThreadProcessId([Link.Win]::GetForegroundWindow(), [ref]$none)
    $me = [Link.Win]::GetCurrentThreadId()
    [void][Link.Win]::AttachThreadInput($me, $foreground, $true)
    [void][Link.Win]::ShowWindow($p.MainWindowHandle, 9); [void][Link.Win]::BringWindowToTop($p.MainWindowHandle)
    [void][Link.Win]::SetForegroundWindow($p.MainWindowHandle)
    [void][Link.Win]::AttachThreadInput($me, $foreground, $false)
    # The application connected and drew its page
    Start-Sleep -Seconds 4
    Say "link: the window is in the foreground: $([Link.Win]::GetForegroundWindow() -eq $p.MainWindowHandle)"

    Choose 'Copy Link Address'
    $clipboard = Get-Clipboard -Raw
    if ($clipboard -ne $href) { Fail "after Copy Link Address, the clipboard has '$clipboard', not '$href'" }
    Say "link: Copy Link Address: the clipboard has '$clipboard' (Get-Clipboard)"

    Choose 'Open Link'
    $followed = if (Test-Path $env:LINK_APP_LOG) { (Get-Content $env:LINK_APP_LOG) -join ' | ' } else { '' }
    if ($followed -ne "followed $href") { Fail "after Open Link, the application logged '$followed', not 'followed $href'" }
    Say "link: Open Link: the application logged '$followed'"

    [void]$p.CloseMainWindow()
    if (-not $p.WaitForExit(20000)) { Fail 'the host did not exit within 20 s of its close button' }
    Start-Sleep -Milliseconds 500
    $left = @(Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -and $_.CommandLine.Contains($work) })
    if ($left.Count -gt 0) { $left | ForEach-Object { Say "  left: $($_.ProcessId) $($_.CommandLine)" }; Fail 'processes left behind' }
    Say "link: closed, the host exited $($p.ExitCode), no process left"
    Say 'link: PASS'
    $status = 0
} catch {
    Say $_.Exception.Message
    if ($_.Exception.Message -notlike 'link: FAILED*') { Say $_.ScriptStackTrace }
} finally {
    if ($p -and -not $p.HasExited) { $p.Kill() }
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { $work -and $_.CommandLine -and $_.CommandLine.Contains($work) } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    if ($work -and (Test-Path $work) -and $work.StartsWith($env:TEMP)) {
        Copy-Item -ErrorAction SilentlyContinue "$work\link-app.log" $results
        Copy-Item -ErrorAction SilentlyContinue (Join-Path $env:LOCALAPPDATA 'dev.tuiapp.host\logs\app.log') $results
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $work
    }
    Set-Content -Path (Join-Path $Out 'link.exit') -Value $status
    Set-Content -Path (Join-Path $Out 'done.txt') -Value 'done'
}
