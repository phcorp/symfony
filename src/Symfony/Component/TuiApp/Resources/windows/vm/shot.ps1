# Starts the host on the fake app, captures its window alone with PrintWindow, then closes it with
# WM_CLOSE (the close button's path: close, close-reply, bye, exit) and reports how it ended.
param([Parameter(Mandatory)] [string] $HostExe, [Parameter(Mandatory)] [string] $Out)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
Add-Type -Namespace Shot -Name Win -MemberDefinition @'
[DllImport("user32.dll")] public static extern bool SetProcessDPIAware();
[DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr window, out RECT rect);
[DllImport("user32.dll")] public static extern bool PrintWindow(IntPtr window, IntPtr dc, uint flags);
[DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr window);
[StructLayout(LayoutKind.Sequential)] public struct RECT { public int Left, Top, Right, Bottom; }
'@
[void][Shot.Win]::SetProcessDPIAware()

$app = Start-Process $HostExe -ArgumentList '--dev', '--', 'php', 'tools\fake-app.php' -PassThru
$deadline = (Get-Date).AddSeconds(30)
do {
    Start-Sleep -Milliseconds 300
    $app.Refresh()
} until ($app.MainWindowHandle -ne 0 -or (Get-Date) -gt $deadline -or $app.HasExited)
if ($app.MainWindowHandle -eq 0) { throw "no window (exited: $($app.HasExited))" }
# The patches, the menu and the focus.
Start-Sleep -Seconds 6
$app.Refresh()
"window title: $($app.MainWindowTitle)"
[void][Shot.Win]::SetForegroundWindow($app.MainWindowHandle)
Start-Sleep -Milliseconds 500

$rect = New-Object Shot.Win+RECT
[void][Shot.Win]::GetWindowRect($app.MainWindowHandle, [ref]$rect)
$width = $rect.Right - $rect.Left
$height = $rect.Bottom - $rect.Top
$bitmap = New-Object System.Drawing.Bitmap $width, $height
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$dc = $graphics.GetHdc()
# 2: PW_RENDERFULLCONTENT, for windows drawn by DirectX such as WPF's.
$printed = [Shot.Win]::PrintWindow($app.MainWindowHandle, $dc, 2)
$graphics.ReleaseHdc($dc)
$graphics.Dispose()
$bitmap.Save($Out, [System.Drawing.Imaging.ImageFormat]::Png)
$bitmap.Dispose()
"screenshot: ${width}x${height}, PrintWindow $printed"

$clock = [Diagnostics.Stopwatch]::StartNew()
[void]$app.CloseMainWindow()
if ($app.WaitForExit(20000)) {
    "close button: the host exited $($app.ExitCode) after $([int]$clock.Elapsed.TotalMilliseconds) ms"
} else {
    "close button: the host is still running after 20 s"
    $app.Kill()
}
