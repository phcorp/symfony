# The guest side of vm/ime.sh, in the user's session (vm/session1.sh): the Microsoft IME (ja-JP) typed for
# real into the host on the stand-in app in canvas mode. Adds ja-JP to the user's input languages (put back
# at the end), brings the window to the front, switches its thread to Japanese, turns the IME on (VK_IME_ON)
# and types n i h o n n, Space, Space (the candidate list), Enter: in text field 3, then in the canvas
# (node 21, clicked). Checks what the application got: nothing while composing, the commit once, and that the
# candidate list opened next to the focused element. Then n i h o n n, Space, Enter in text field 30, 39
# characters of its maxLength 40 in it: the composition shown whole, nothing sent, the commit cut to fit.
# Part 2, with ime-app.php (which updates the other props of its fields every -Tick ms): n i h o n n, Space, then
# k a, which commits the kanji and composes ka in one go, into text field 3, text field 4 (maxLength 40) with 39
# characters, and canvas 5. The commit must go out once (cut to one kanji in 4, where the rest stays shown until the
# composition ends), the composition after it neither sent, cut nor wiped by the updates.
# Writes ime.txt and ime.exit in -Out, screen captures in -Out\ime, done.txt last.
param(
    [string] $Out = 'C:\tuiapp-vm\out',
    [string] $Arch = 'arm64',
    [string] $Php = '',
    # 1: the fake app's text field, canvas and maxLength; 2: typing on after a conversion, with ime-app.php
    [string] $Part = 'all',
    # How often ime-app.php updates the other props of its fields, in ms; 0: never
    [int] $Tick = 100
)
$ErrorActionPreference = 'Stop'
$log = Join-Path $Out 'ime.txt'
$results = Join-Path $Out 'ime'
$status = 1
$work = $null
$languages = $null
$app = $null
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $log, $results
New-Item -ItemType Directory -Force -Path $results | Out-Null

function Say([string] $Line) { Add-Content -Encoding UTF8 -Path $log -Value $Line }
function Fail([string] $Why) { throw "ime: FAILED: $Why" }
# A failed check that lets the next steps run: Fail at the end
$failures = 0
function Soft([string] $Why) { Say "ime: FAILED: $Why"; $script:failures++ }

Add-Type -AssemblyName System.Drawing, System.Windows.Forms, UIAutomationClient, UIAutomationTypes
Add-Type -Namespace Ime -Name Win -MemberDefinition @'
[DllImport("user32.dll")] public static extern bool SetProcessDPIAware();
[DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr w);
[DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
[DllImport("user32.dll")] public static extern void keybd_event(byte vk, byte scan, uint flags, UIntPtr extra);
[DllImport("user32.dll", CharSet=CharSet.Unicode)] public static extern IntPtr LoadKeyboardLayout(string id, uint flags);
[DllImport("user32.dll")] public static extern bool PostMessage(IntPtr w, uint m, IntPtr wp, IntPtr lp);
[DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr w, out uint pid);
[DllImport("user32.dll")] public static extern IntPtr GetKeyboardLayout(uint thread);
[DllImport("user32.dll")] public static extern uint MapVirtualKey(uint code, uint type);
[DllImport("user32.dll")] public static extern bool SetCursorPos(int x, int y);
[DllImport("user32.dll")] public static extern void mouse_event(uint f, uint x, uint y, uint d, UIntPtr e);
[DllImport("user32.dll")] public static extern bool AttachThreadInput(uint a, uint b, bool attach);
[DllImport("kernel32.dll")] public static extern uint GetCurrentThreadId();
[DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr w, int cmd);
[DllImport("user32.dll")] public static extern bool BringWindowToTop(IntPtr w);
'@
Add-Type -Namespace Ime -Name Diff -ReferencedAssemblies System.Drawing -MemberDefinition @'
// The bounding box of the largest connected patch of 4x4 blocks that changed between two captures of the
// screen, leaving out the rows from top to bottom: {left, top, right, bottom}, all 0 when nothing changed.
public static int[] Largest(string before, string after, int top, int bottom) {
    using (var a = new System.Drawing.Bitmap(before)) using (var b = new System.Drawing.Bitmap(after)) {
        int w = System.Math.Min(a.Width, b.Width) / 4, h = System.Math.Min(a.Height, b.Height) / 4;
        var changed = new bool[w, h];
        for (int by = 0; by < h; by++) {
            if (by * 4 + 3 >= top && by * 4 <= bottom) continue;
            for (int bx = 0; bx < w; bx++) {
                int count = 0;
                for (int y = 0; y < 4; y++) for (int x = 0; x < 4; x++) {
                    var p = a.GetPixel(bx * 4 + x, by * 4 + y); var q = b.GetPixel(bx * 4 + x, by * 4 + y);
                    if (System.Math.Abs(p.R - q.R) + System.Math.Abs(p.G - q.G) + System.Math.Abs(p.B - q.B) > 24) count++;
                }
                changed[bx, by] = count > 0;
            }
        }
        var seen = new bool[w, h];
        var best = new int[4]; int bestSize = 0;
        var queue = new System.Collections.Generic.Queue<int>();
        for (int sy = 0; sy < h; sy++) for (int sx = 0; sx < w; sx++) {
            if (!changed[sx, sy] || seen[sx, sy]) continue;
            int size = 0, l = sx, t = sy, r = sx, d = sy;
            seen[sx, sy] = true; queue.Enqueue(sy * w + sx);
            while (queue.Count > 0) {
                int i = queue.Dequeue(), x = i % w, y = i / w; size++;
                l = System.Math.Min(l, x); r = System.Math.Max(r, x); t = System.Math.Min(t, y); d = System.Math.Max(d, y);
                // Neighbours within two blocks: the list's text and its blank padding in one patch
                for (int dy = -2; dy <= 2; dy++) for (int dx = -2; dx <= 2; dx++) {
                    int nx = x + dx, ny = y + dy;
                    if (nx >= 0 && ny >= 0 && nx < w && ny < h && changed[nx, ny] && !seen[nx, ny]) { seen[nx, ny] = true; queue.Enqueue(ny * w + nx); }
                }
            }
            if (size > bestSize) { bestSize = size; best = new[] { l * 4, t * 4, r * 4 + 4, d * 4 + 4 }; }
        }
        return best;
    }
}
'@
[void][Ime.Win]::SetProcessDPIAware()

function Key([byte] $Vk) {
    $scan = [byte][Ime.Win]::MapVirtualKey($Vk, 0)
    [Ime.Win]::keybd_event($Vk, $scan, 0, [UIntPtr]::Zero); Start-Sleep -Milliseconds 70
    [Ime.Win]::keybd_event($Vk, $scan, 2, [UIntPtr]::Zero); Start-Sleep -Milliseconds 70
}

# The guest's screen (not the Mac's), for the input method's own windows
function Shot([string] $Name) {
    $screen = [System.Windows.Forms.SystemInformation]::VirtualScreen
    $bitmap = New-Object System.Drawing.Bitmap $screen.Width, $screen.Height
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.CopyFromScreen($screen.X, $screen.Y, 0, 0, $bitmap.Size)
    $graphics.Dispose()
    $bitmap.Save("$results\$Name.png", [System.Drawing.Imaging.ImageFormat]::Png)
    $bitmap.Dispose()
}

# The candidate list opens next to the focused element (the text field, or the canvas's entry over the cursor
# cell). The Microsoft IME of Windows 11 draws it in TextInputHost's window over the whole screen, which says
# nothing of where: it is found on the screen, as what changed most from before it came up to after, the
# largest patch of changed pixels (the stand-in app's progress bars move too, in thin strips), the focused
# element's row left out. Its left must be near the element's, and its top at the element's bottom, or its
# bottom at the element's top when there is no room below, within two cells.
function Candidates([string] $Where, [string] $Before, [string] $After, $Focus, [double] $Cell) {
    $patch = [Ime.Diff]::Largest($Before, $After, [int]$Focus.Top - 4, [int]$Focus.Bottom + 4)
    Say "ime:   ${Where}: the focused element is at ($([int]$Focus.Left),$([int]$Focus.Top))-($([int]$Focus.Right),$([int]$Focus.Bottom)); what came up on the screen: ($($patch -join ','))"
    if ($patch[2] - $patch[0] -lt 60 -or $patch[3] - $patch[1] -lt 60) { Fail "${Where}: no candidate list came up" }
    $left = [Math]::Abs($patch[0] - $Focus.Left) -le 4 * $Cell
    $below = [Math]::Abs($patch[1] - $Focus.Bottom) -le 2 * $Cell
    $above = [Math]::Abs($patch[3] - $Focus.Top) -le 2 * $Cell
    if (-not ($left -and ($below -or $above))) { Fail "${Where}: the candidate list is not next to the focused element" }
    Say "ime:   ${Where}: the candidate list is next to the focused element, $(if ($below) { 'below' } else { 'above' }) it"
}

$seen = 0
$appLog = $null
# What the application got since the last call, rects left out
function Got([string] $Step) {
    $lines = @(Get-Content -Encoding UTF8 $script:appLog)
    $new = @($lines | Select-Object -Skip $script:seen | Where-Object { $_ -notmatch '"type":"rects"' })
    $script:seen = $lines.Count
    Say "ime:   after ${Step}: $($new.Count) message(s)$(if ($new.Count -gt 0) { ': ' + ($new -join ' ') })"
    # An array, even empty or of one
    return ,$new
}

# Starts nothing: waits for the window of $App and for $Pattern in the application's log, brings the window to
# the front, and returns it, its thread and the hello the application got
function Front($App, [string] $Pattern) {
    $deadline = (Get-Date).AddSeconds(90)
    do { Start-Sleep -Milliseconds 300; $App.Refresh() } until (($App.MainWindowHandle -ne 0 -and (Test-Path $script:appLog) -and (Select-String -Path $script:appLog -Pattern $Pattern -SimpleMatch -Quiet)) -or $App.HasExited -or (Get-Date) -gt $deadline)
    if ($App.MainWindowHandle -eq 0) { Fail "no window (exited: $($App.HasExited))" }
    Start-Sleep -Seconds 2
    $window = $App.MainWindowHandle
    # Into the foreground: attached to the foreground window's input, as the shell lets a process do
    $none = 0
    $foregroundThread = [Ime.Win]::GetWindowThreadProcessId([Ime.Win]::GetForegroundWindow(), [ref]$none)
    $me = [Ime.Win]::GetCurrentThreadId()
    [void][Ime.Win]::AttachThreadInput($me, $foregroundThread, $true)
    [void][Ime.Win]::ShowWindow($window, 9); [void][Ime.Win]::BringWindowToTop($window); [void][Ime.Win]::SetForegroundWindow($window)
    [void][Ime.Win]::AttachThreadInput($me, $foregroundThread, $false)
    Start-Sleep -Milliseconds 700
    if ([Ime.Win]::GetForegroundWindow() -ne $window) { Fail 'the host is not in the foreground' }
    $hostId = 0
    $thread = [Ime.Win]::GetWindowThreadProcessId($window, [ref]$hostId)
    $start = Got 'start'
    $hello = $start | Where-Object { $_ -match '"type":"hello"' } | Select-Object -First 1 | ConvertFrom-Json
    return $window, $thread, $hello
}

function Close($App) {
    if ($App -and -not $App.HasExited) {
        [void]$App.CloseMainWindow()
        if (-not $App.WaitForExit(15000)) { $App.Kill() }
    }
}

function Click($Rect, [double] $X, [double] $Y) {
    [Ime.Win]::SetCursorPos([int]($Rect.X + $X), [int]($Rect.Y + $Y)); [Ime.Win]::mouse_event(2, 0, 0, 0, [UIntPtr]::Zero); [Ime.Win]::mouse_event(4, 0, 0, 0, [UIntPtr]::Zero)
}

# n i h o n n, Space: nihon converted to kanji, still composing; then k a: the kanji committed, ka composed, in one go
function TypeOn {
    foreach ($k in 0x4E, 0x49, 0x48, 0x4F, 0x4E, 0x4E) { Key $k }
    Key 0x20; Start-Sleep -Milliseconds 900
    $converted = Got 'n i h o n n, Space'
    Key 0x4B; Key 0x41; Start-Sleep -Milliseconds 1200
    return $converted, (Got 'k a')
}

function Values([object[]] $Lines, [int] $Node, [string] $Name = 'change') {
    return ,@($Lines | Where-Object { $_ -match ('"node":' + $Node + ',"name":"' + $Name + '"') } | ForEach-Object { ($_ | ConvertFrom-Json).value })
}

function Japanese([IntPtr] $Window, [uint32] $Thread) {
    $hkl = [Ime.Win]::LoadKeyboardLayout('00000411', 1)
    # WM_INPUTLANGCHANGEREQUEST: the host's thread switches to Japanese
    [void][Ime.Win]::PostMessage($Window, 0x50, [IntPtr]::Zero, $hkl)
    Start-Sleep -Milliseconds 1000
    # VK_IME_ON, the key of a Japanese keyboard: Hiragana
    Key 0x16
    Start-Sleep -Milliseconds 500
    Say ('ime:   keyboard layout of the host''s thread 0x{0:x}' -f [Ime.Win]::GetKeyboardLayout($Thread).ToInt64())
}

function Romaji([string] $Where, [double] $Cell) {
    $got = @{}
    foreach ($k in 0x4E, 0x49, 0x48, 0x4F, 0x4E, 0x4E) { Key $k }
    Start-Sleep -Milliseconds 900
    Shot "$Where-1-preedit"; $got.typed = Got 'n i h o n n'
    Key 0x20; Start-Sleep -Milliseconds 900
    Shot "$Where-2-converted"; $got.converted = Got 'Space'
    Key 0x20; Start-Sleep -Milliseconds 1200
    Shot "$Where-3-candidates"; $got.candidates = Got 'Space again (the candidate list)'
    Candidates $Where "$results\$Where-2-converted.png" "$results\$Where-3-candidates.png" ([Windows.Automation.AutomationElement]::FocusedElement.Current.BoundingRectangle) $Cell
    Key 0x0D; Start-Sleep -Milliseconds 900
    Shot "$Where-4-committed"; $got.committed = Got 'Enter'
    Start-Sleep -Milliseconds 800
    $got.later = Got 'a moment later'
    return $got
}

try {
    Say "ime: session $((Get-Process -Id $PID).SessionId), $([Environment]::OSVersion.VersionString) $env:PROCESSOR_ARCHITECTURE, host $Arch"
    $work = Join-Path $env:TEMP ('tuiapp-ime-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Force -Path $work | Out-Null
    Expand-Archive "C:\tuiapp-vm\host-$Arch.zip" (Join-Path $work 'host')
    Expand-Archive 'C:\tuiapp-vm\kit.zip' $work
    if ($Php) { $env:Path = (Split-Path $Php) + ';' + $env:Path }
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) { Fail 'no php on PATH: give -Php' }

    $languages = Get-WinUserLanguageList
    Say "ime: input languages: $(($languages | ForEach-Object { $_.LanguageTag + ' ' + ($_.InputMethodTips -join ',') }) -join ' | ')"
    $list = Get-WinUserLanguageList
    $list.Add('ja-JP')
    Set-WinUserLanguageList $list -Force
    $ja = (Get-WinUserLanguageList | Where-Object { $_.LanguageTag -like 'ja*' }).InputMethodTips -join ','
    if (-not $ja) { Fail 'ja-JP was not added (is the capability Language.Basic~~~ja-JP installed?)' }
    Say "ime: ja-JP added, with $ja"
    Start-Sleep -Seconds 2

    if ($Part -ne '2') {
        $env:FAKE_APP_CANVAS = '1'
        $env:FAKE_APP_LOG = "$work\ime.app.log"
        $script:appLog = $env:FAKE_APP_LOG; $script:seen = 0
        $app = Start-Process (Join-Path $work 'host\TuiAppHost.exe') -ArgumentList '--dev', '--', 'php', 'tools\fake-app.php' -WorkingDirectory $work -PassThru
        $window, $thread, $hello = Front $app '"21"'
        $cell = $hello.cell.height * $hello.scale

        Say 'ime: 1. text field 3, focused by the application'
        Japanese $window $thread
        $field = Romaji 'field' $cell
        $changes = @($field.typed + $field.converted + $field.candidates | Where-Object { $_ -match '"node":3,"name":"change"' })
        if ($changes.Count -gt 0) { Fail "the field sent its preedit: $($changes -join ' ')" }
        $commits = @($field.committed + $field.later | Where-Object { $_ -match '"node":3,"name":"change"' } | ForEach-Object { ($_ | ConvertFrom-Json).value })
        if ($commits.Count -ne 1 -or $commits[0] -match '[a-z]') { Fail "the field's commit went out as: $($commits -join ', ') (once, in kanji, expected)" }
        Say "ime: the field sent nothing while composing, then change `"$($commits[0])`" once"

        Say 'ime: 2. the canvas (node 21), clicked on its second line'
        $rects = (Get-Content -Encoding UTF8 $env:FAKE_APP_LOG | Where-Object { $_ -match '"type":"rects"' -and $_ -match '"21"' } | Select-Object -Last 1 | ConvertFrom-Json).rects
        $edit = [Windows.Automation.AutomationElement]::FromHandle($window).FindFirst([Windows.Automation.TreeScope]::Descendants, (New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::ControlTypeProperty, [Windows.Automation.ControlType]::Edit)))
        if (-not $edit) { Fail 'no text field found to place the canvas from' }
        $at = $edit.Current.BoundingRectangle
        # From text field 3's top (at row $rects.3[0]), the canvas's second line
        $x = [int]($at.X + 60); $y = [int]($at.Y + ($rects.'21'[0] - $rects.'3'[0] + 1.5) * $hello.cell.height)
        [Ime.Win]::SetCursorPos($x, $y); [Ime.Win]::mouse_event(2, 0, 0, 0, [UIntPtr]::Zero); [Ime.Win]::mouse_event(4, 0, 0, 0, [UIntPtr]::Zero)
        Start-Sleep -Milliseconds 700
        $focus = Got "a click at $x,$y"
        if (-not ($focus -match '"node":21,"name":"focus"')) { Fail 'the canvas did not take the focus' }
        Japanese $window $thread
        $canvas = Romaji 'canvas' $cell
        $early = @($canvas.typed + $canvas.converted + $canvas.candidates)
        if ($early.Count -gt 0) { Fail "the canvas sent its preedit: $($early -join ' ')" }
        $keys = @($canvas.committed + $canvas.later | Where-Object { $_ -match '"type":"key"' } | ForEach-Object { ($_ | ConvertFrom-Json).text })
        if ($keys.Count -ne 2 -or ($keys -join '') -match '[a-z]') { Fail "the canvas's commit went out as: $($keys -join ', ') (two kanji, once, expected)" }
        Say "ime: the canvas sent nothing while composing, then key $($keys -join ', key ') once"

        Say 'ime: 3. text field 30 over the canvas (maxLength 40), with 39 characters: the composition let in whole, its commit cut'
        $edits = [Windows.Automation.AutomationElement]::FromHandle($window).FindAll([Windows.Automation.TreeScope]::Descendants, (New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::ControlTypeProperty, [Windows.Automation.ControlType]::Edit)))
        # The form's field is named Name too: 30 is the lower one
        $named = @($edits | Where-Object { $_.Current.Name -eq 'Name' } | Sort-Object { $_.Current.BoundingRectangle.Y })
        if ($named.Count -eq 0) { Fail 'no text field 30 (Name)' }
        $limited = $named[-1]
        $value = $limited.GetCurrentPattern([Windows.Automation.ValuePattern]::Pattern)
        $base = 'Ada' + ('0123456789' * 4).Substring(0, 36)
        $value.SetValue($base)
        Start-Sleep -Milliseconds 500
        $set = Got 'its value set'
        if (-not ($set -like ('*"node":30,"name":"change","value":"' + $base + '"*'))) { Fail "setting node 30 to 39 characters sent: $($set -join ' ')" }
        $r = $limited.Current.BoundingRectangle
        [Ime.Win]::SetCursorPos([int]($r.Right - 4), [int]($r.Top + $r.Height / 2)); [Ime.Win]::mouse_event(2, 0, 0, 0, [UIntPtr]::Zero); [Ime.Win]::mouse_event(4, 0, 0, 0, [UIntPtr]::Zero)
        Start-Sleep -Milliseconds 500
        Key 0x23
        $null = Got 'a click on it, and End'
        Japanese $window $thread
        foreach ($k in 0x4E, 0x49, 0x48, 0x4F, 0x4E, 0x4E) { Key $k }
        Start-Sleep -Milliseconds 900
        Shot 'limit-1-preedit'
        $shown = $value.Current.Value
        $typed = Got 'n i h o n n'
        if ($typed.Count -gt 0) { Fail "node 30 sent its composition: $($typed -join ' ')" }
        if (-not $shown.StartsWith($base) -or $shown.Length - $base.Length -lt 3) { Fail "the composition in node 30 was cut: it shows `"$shown`"" }
        Say "ime:   composing, node 30 shows $($shown.Substring($base.Length)) after its 39 characters: whole, past maxLength 40, and nothing sent"
        Key 0x20; Start-Sleep -Milliseconds 900
        $converted = Got 'Space'
        if ($converted.Count -gt 0) { Fail "node 30 sent its conversion: $($converted -join ' ')" }
        Key 0x0D; Start-Sleep -Milliseconds 900
        Shot 'limit-2-committed'
        $after = @(Got 'Enter') + @(Start-Sleep -Milliseconds 800; Got 'a moment later')
        $commits = @($after | Where-Object { $_ -match '"node":30,"name":"change"' } | ForEach-Object { ($_ | ConvertFrom-Json).value })
        if ($commits.Count -ne 1 -or -not $commits[0].StartsWith($base) -or $commits[0].Length -ne 40 -or $commits[0].Substring(39) -match '[a-z]') {
            Fail "node 30's commit went out as: $($commits -join ', ') (once, its 39 characters and the first kanji, expected)"
        }
        if ($value.Current.Value -ne $commits[0]) { Fail "node 30 shows `"$($value.Current.Value)`", not what it sent" }
        Say "ime: node 30 sent nothing while composing, then its commit cut to $($commits[0].Substring(39)), 40 characters, once"

    }
    if ($Part -ne '1') {
        Close $app
        Say "ime: 4. typing on after a conversion (ime-app.php, which updates the fields' other props every $Tick ms)"
        $env:IME_APP_LOG = "$work\ime2.app.log"
        $env:IME_APP_TICK = "$Tick"
        $script:appLog = $env:IME_APP_LOG; $script:seen = 0
        $app = Start-Process (Join-Path $work 'host\TuiAppHost.exe') -ArgumentList '--dev', '--', 'php', 'ime-app.php' -WorkingDirectory $work -PassThru
        $window, $thread, $hello = Front $app '"type":"hello"'
        $root = [Windows.Automation.AutomationElement]::FromHandle($window)
        $edits = @($root.FindAll([Windows.Automation.TreeScope]::Descendants, (New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::ControlTypeProperty, [Windows.Automation.ControlType]::Edit))))
        $free = $edits | Where-Object { $_.Current.Name -eq 'Free' } | Select-Object -First 1
        $limited = $edits | Where-Object { $_.Current.Name -eq 'Limited' } | Select-Object -First 1
        $entry = $edits | Where-Object { $_.Current.Name -notin 'Free', 'Limited' } | Select-Object -First 1
        if (-not $free -or -not $limited -or -not $entry) { Fail "the fields and the canvas's entry not found: $(($edits | ForEach-Object { $_.Current.Name }) -join ', ')" }
        $freeValue = $free.GetCurrentPattern([Windows.Automation.ValuePattern]::Pattern)
        $limitedValue = $limited.GetCurrentPattern([Windows.Automation.ValuePattern]::Pattern)
        $entryValue = $entry.GetCurrentPattern([Windows.Automation.ValuePattern]::Pattern)

        # This file is read in the ANSI code page: the kana and kanji by code
        $nichi = [string][char]0x65E5; $hon = [string][char]0x672C; $ka = [string][char]0x304B
        Say 'ime: 4a. text field 3, no limit'
        $failed = $script:failures
        Japanese $window $thread
        $converted, $typedOn = TypeOn
        Shot 'on-field-1-typed-on'
        $shown = $freeValue.Current.Value
        $early = Values $converted 3
        if ($early.Count -gt 0) { Soft "field 3 sent its conversion: $($converted -join ' ')" }
        $sent = Values $typedOn 3
        Say "ime:   field 3 shows `"$shown`", sent $(if ($sent.Count) { 'change ' + ($sent -join ', change ') } else { 'nothing' })"
        if ($sent.Count -ne 1 -or $sent[0] -ne ($nichi + $hon)) { Soft "field 3 sent $($sent -join ', ') for its commit (change `"$nichi$hon`" once expected: never its new composition)" }
        if ($shown -ne ($nichi + $hon + $ka)) { Soft "field 3 shows `"$shown`" while composing $ka after $nichi$hon (the updates of its other props must keep it)" }
        Key 0x0D; Start-Sleep -Milliseconds 900
        $sent = Values (Got 'Enter') 3
        if ($sent.Count -ne 1 -or $sent[0] -ne ($nichi + $hon + $ka) -or $freeValue.Current.Value -ne ($nichi + $hon + $ka)) { Soft "field 3 sent $($sent -join ', ') for $ka and shows `"$($freeValue.Current.Value)`" (change `"$nichi$hon$ka`" once expected)" }
        if ($script:failures -eq $failed) { Say "ime: field 3 sent change $nichi$hon when typing on committed it, kept $ka composing through the updates, then change $nichi$hon$ka" }

        Say 'ime: 4b. text field 4, maxLength 40, with 39 characters'
        $failed = $script:failures
        $base = 'Ada' + ('0123456789' * 4).Substring(0, 36)
        $limitedValue.SetValue($base)
        Start-Sleep -Milliseconds 500
        $null = Got 'its value set'
        Click $limited.Current.BoundingRectangle ($limited.Current.BoundingRectangle.Width - 4) ($limited.Current.BoundingRectangle.Height / 2)
        Start-Sleep -Milliseconds 500
        Key 0x23
        $null = Got 'a click on it, and End'
        Japanese $window $thread
        $converted, $typedOn = TypeOn
        Shot 'on-limit-1-typed-on'
        $shown = $limitedValue.Current.Value
        $sent = Values $typedOn 4
        Say "ime:   field 4 shows `"$shown`", sent $(if ($sent.Count) { 'change ' + ($sent -join ', change ') } else { 'nothing' })"
        if ($sent.Count -ne 1 -or $sent[0] -ne ($base + $nichi)) { Soft "field 4 sent $($sent -join ', ') for its commit (change base+`"$nichi`" once expected: the commit cut, not the composition)" }
        # The field is not changed under a composition (the input method would end it): the cut hon stays until it ends
        if ($shown -ne ($base + $nichi + $hon + $ka)) { Soft "field 4 shows `"$shown`" while composing $ka (its 40 characters, the cut $hon and $ka whole expected)" }
        Key 0x0D; Start-Sleep -Milliseconds 900
        $sent = Values (Got 'Enter') 4
        if ($sent.Count -gt 0 -or $limitedValue.Current.Value -ne ($base + $nichi)) { Soft "field 4 sent $($sent -join ', ') for $ka, which does not fit, and shows `"$($limitedValue.Current.Value)`"" }
        # Full: the whole commit cut, then the composition after it taken back (Escape)
        $converted, $typedOn = TypeOn
        Shot 'on-limit-2-full'
        $shown = $limitedValue.Current.Value
        $sent = Values $typedOn 4
        if ($sent.Count -gt 0 -or $shown -ne ($base + $nichi + $nichi + $hon + $ka)) { Soft "field 4, full, sent $($sent -join ', ') for a commit that does not fit and shows `"$shown`" while composing $ka" }
        Key 0x1B; Start-Sleep -Milliseconds 900
        $sent = Values (Got 'Escape') 4
        if ($sent.Count -gt 0 -or $limitedValue.Current.Value -ne ($base + $nichi)) { Soft "field 4 sent $($sent -join ', ') once $ka was taken back, and shows `"$($limitedValue.Current.Value)`"" }
        if ($script:failures -eq $failed) { Say "ime: field 4 sent its commit cut to $nichi when typing on committed it, kept $ka whole while composing, refused it at the limit; full, sent nothing and showed its value once $ka was taken back" }

        Say 'ime: 4c. the canvas'
        $failed = $script:failures
        $canvas = $root.FindFirst([Windows.Automation.TreeScope]::Descendants, (New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::NameProperty, 'Canvas')))
        $at = if ($canvas) { $canvas.Current.BoundingRectangle } else { $limited.Current.BoundingRectangle }
        Click $at 30 ($(if ($canvas) { 1.5 } else { 3.5 }) * $hello.cell.height)
        Start-Sleep -Milliseconds 700
        if (-not ((Got 'a click on the canvas') -match '"node":5,"name":"focus"')) { Fail 'the canvas did not take the focus' }
        Japanese $window $thread
        $converted, $typedOn = TypeOn
        Shot 'on-canvas-1-typed-on'
        $keys = @($converted + $typedOn | Where-Object { $_ -match '"type":"key"' } | ForEach-Object { ($_ | ConvertFrom-Json).text })
        $held = $entryValue.Current.Value
        Say "ime:   the canvas sent key $($keys -join ', key '); its entry holds `"$held`""
        if (($keys -join ',') -ne ($nichi + ',' + $hon)) { Soft "the canvas sent $($keys -join ', ') for its commit ($nichi, $hon expected)" }
        if (-not $held.EndsWith($ka)) { Soft "the canvas's entry holds `"$held`": the composition of $ka is gone" }
        Key 0x0D; Start-Sleep -Milliseconds 900
        Shot 'on-canvas-2-committed'
        $keys = @((Got 'Enter') | Where-Object { $_ -match '"type":"key"' } | ForEach-Object { ($_ | ConvertFrom-Json).text })
        if (($keys -join ',') -ne $ka) { Soft "the canvas sent $($keys -join ', ') for $ka ($ka once expected)" }
        if ($script:failures -eq $failed) { Say "ime: the canvas sent $nichi, $hon when typing on committed them, kept $ka composing, then sent $ka once" }
        if ($script:failures -gt 0) { Fail "$($script:failures) check(s) of part 2 failed" }
    }

    Say 'ime: PASS'
    $status = 0
} catch {
    Say $_.Exception.Message
    if ($_.Exception.Message -notlike 'ime: FAILED*') { Say $_.ScriptStackTrace }
} finally {
    Close $app
    if ($languages) {
        Set-WinUserLanguageList $languages -Force
        Say "ime: input languages put back: $((Get-WinUserLanguageList | ForEach-Object { $_.LanguageTag + ' ' + ($_.InputMethodTips -join ',') }) -join ' | ')"
    }
    if ($work -and (Test-Path $work) -and $work.StartsWith($env:TEMP)) {
        Copy-Item -ErrorAction SilentlyContinue "$work\ime.app.log", "$work\ime2.app.log" $results
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $work
    }
    Set-Content -Path (Join-Path $Out 'ime.exit') -Value $status
    Set-Content -Path (Join-Path $Out 'done.txt') -Value 'done'
}
