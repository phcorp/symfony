# The guest side of vm/package-e2e.sh, in the user's session (vm/session1.sh): the portable .zip and the
# setup .exe of -Packages, end to end. Writes package-e2e.txt and package-e2e.exit in -Out, done.txt last.
param(
    [string] $Out = 'C:\tuiapp-vm\out',
    [Parameter(Mandatory)] [string] $Packages
)
$ErrorActionPreference = 'Stop'
$log = Join-Path $Out 'package-e2e.txt'
$status = 1
$portable = $null
Remove-Item -Force -ErrorAction SilentlyContinue $log

function Say([string] $Line) { Add-Content -Encoding UTF8 -Path $log -Value $Line }
function Fail([string] $Why) { throw "package-e2e: FAILED: $Why" }
function Check([bool] $Condition, [string] $Ok, [string] $Failure) { if (-not $Condition) { Fail $Failure }; Say "package-e2e: $Ok" }

function Machine([string] $Exe) {
    $bytes = [IO.File]::ReadAllBytes($Exe)
    $pe = [BitConverter]::ToInt32($bytes, 0x3c)
    switch ([BitConverter]::ToUInt16($bytes, $pe + 4)) { 0xaa64 { 'arm64' } 0x8664 { 'x64' } default { 'other' } }
}

# The host's --self-test against the package's own application: PASS and exit 0.
function SelfTest([string] $Exe, [string] $Tag) {
    $clock = [Diagnostics.Stopwatch]::StartNew()
    $p = Start-Process $Exe -ArgumentList '--self-test' -RedirectStandardOutput "$Out\$Tag-selftest.txt" -RedirectStandardError "$Out\$Tag-selftest-err.txt" -PassThru
    $null = $p.Handle
    if (-not $p.WaitForExit(60000)) { $p.Kill(); Fail "${Tag}: the self-test still runs after 60 s" }
    $lines = @(Get-Content -Encoding UTF8 "$Out\$Tag-selftest.txt")
    $lines | Select-Object -Last 3 | ForEach-Object { Say "  $_" }
    Check ($p.ExitCode -eq 0 -and ($lines -match 'PASS')) "${Tag}: self-test PASS in $([int]$clock.Elapsed.TotalSeconds) s" "${Tag}: the self-test exited $($p.ExitCode)"
}

function Value([string] $Key, [string] $Name) {
    $item = Get-Item -Path $Key -ErrorAction SilentlyContinue
    if ($item) { $item.GetValue($Name) } else { $null }
}

try {
    Say "package-e2e: session $((Get-Process -Id $PID).SessionId), $([Environment]::OSVersion.VersionString) $env:PROCESSOR_ARCHITECTURE"
    $setup = @(Get-ChildItem -Path $Packages -Filter '*-setup.exe')
    $zip = @(Get-ChildItem -Path $Packages -Filter '*.zip')
    if ($setup.Count -ne 1 -or $zip.Count -ne 1) { Fail "$Packages holds $($setup.Count) setup .exe and $($zip.Count) .zip, not one of each" }
    $setup = $setup[0].FullName
    $zip = $zip[0].FullName

    # 1. The portable zip: everything in one folder, the manifest next to the host
    $portable = Join-Path $env:TEMP ('tuiapp-e2e-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
    Expand-Archive $zip $portable
    $manifest = Get-Content -Raw -Encoding UTF8 (Join-Path $portable 'app.manifest.json') | ConvertFrom-Json
    $name = $manifest.app.name
    $id = $manifest.app.id
    $exeName = "$name.exe"
    Say "package-e2e: $name ($id) from $(Split-Path -Leaf $zip) and $(Split-Path -Leaf $setup)"
    $portableExe = Join-Path $portable $exeName
    Check (Test-Path $portableExe) "portable: $exeName is there ($(Machine $portableExe))" "portable: no $exeName in the zip"
    SelfTest $portableExe 'portable'

    # 2. Installed, for the user: no elevation
    $app = Join-Path $env:LOCALAPPDATA "Programs\$name"
    $exe = Join-Path $app $exeName
    $clock = [Diagnostics.Stopwatch]::StartNew()
    $p = Start-Process $setup -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', "/LOG=$Out\setup.log" -PassThru -Wait
    Check ($p.ExitCode -eq 0 -and (Test-Path $exe)) "installed in $([int]$clock.Elapsed.TotalSeconds) s: $exe ($(Machine $exe))" "the installer exited $($p.ExitCode); $exe there: $(Test-Path $exe)"
    foreach ($file in $setup, $exe, (Join-Path $app 'unins000.exe')) {
        $signature = Get-AuthenticodeSignature $file
        Say "package-e2e: signature of $(Split-Path -Leaf $file): $($signature.Status) $($signature.SignerCertificate.Subject)"
    }
    SelfTest $exe 'installed'

    $classes = 'HKCU:\Software\Classes'
    $command = '"' + $exe + '" -- "%1"'
    $keys = @()
    foreach ($association in @($manifest.app.fileAssociations | Where-Object { $_ })) {
        $extension = ([string]$association.extension).TrimStart('.')
        $progId = "$id.$extension"
        Check ((Value "$classes\.$extension" '') -eq $progId) ".$extension opens as $progId" ".$extension does not point at $progId"
        Check ($null -ne (Value "$classes\.$extension\OpenWithProgids" $progId)) ".$extension lists $progId in Open with" ".$extension\OpenWithProgids has no $progId"
        Check ((Value "$classes\$progId\shell\open\command" '') -eq $command) "$progId runs $command" "$progId\shell\open\command is '$(Value "$classes\$progId\shell\open\command" '')'"
        $keys += "$classes\$progId"
    }
    foreach ($scheme in @($manifest.app.urlSchemes | Where-Object { $_ })) {
        Check ($null -ne (Value "$classes\$scheme" 'URL Protocol')) "$($scheme): is a URL protocol" "$scheme has no URL Protocol value"
        Check ((Value "$classes\$scheme\shell\open\command" '') -eq $command) "$($scheme): runs $command" "$scheme\shell\open\command is '$(Value "$classes\$scheme\shell\open\command" '')'"
        $keys += "$classes\$scheme"
    }
    $uninstallKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall'
    $entries = { @(Get-ChildItem $uninstallKey | Where-Object { $_.GetValue('DisplayName') -eq $name }) }
    Check ((& $entries).Count -eq 1) 'one entry in Installed apps' "$((& $entries).Count) uninstall entries named $name"
    $shortcut = Join-Path $env:APPDATA "Microsoft\Windows\Start Menu\Programs\$name.lnk"
    Check (Test-Path $shortcut) 'a Start menu shortcut' "no $shortcut"

    # 3. Uninstalled: the folder, the keys, the entry and the shortcut go
    $clock = [Diagnostics.Stopwatch]::StartNew()
    $p = Start-Process (Join-Path $app 'unins000.exe') -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART' -PassThru -Wait
    # The uninstaller hands over to a copy of itself in TEMP, which removes the folder a moment later
    $deadline = (Get-Date).AddSeconds(30)
    while ((Test-Path $app) -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 500 }
    Check ($p.ExitCode -eq 0 -and -not (Test-Path $app)) "uninstalled in $([int]$clock.Elapsed.TotalSeconds) s, the folder is gone" "the uninstaller exited $($p.ExitCode); folder left: $(Test-Path $app)"
    foreach ($key in $keys) { Check (-not (Test-Path $key)) "$(Split-Path -Leaf $key) is gone" "$key is left" }
    foreach ($association in @($manifest.app.fileAssociations | Where-Object { $_ })) {
        $extension = ([string]$association.extension).TrimStart('.')
        Check ((Value "$classes\.$extension" '') -ne "$id.$extension") ".$extension no longer points at the app" ".$extension still points at $id.$extension"
    }
    Check ((& $entries).Count -eq 0) 'no entry left in Installed apps' 'the uninstall entry is left'
    Check (-not (Test-Path $shortcut)) 'no shortcut left' "$shortcut is left"

    Say 'package-e2e: PASS'
    $status = 0
} catch {
    Say $_.Exception.Message
    if ($_.Exception.Message -notlike 'package-e2e: FAILED*') { Say $_.ScriptStackTrace }
} finally {
    if ($portable -and (Test-Path $portable) -and $portable.StartsWith($env:TEMP)) { Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $portable }
    Set-Content -Path (Join-Path $Out 'package-e2e.exit') -Value $status
    Set-Content -Path (Join-Path $Out 'done.txt') -Value 'done'
}
