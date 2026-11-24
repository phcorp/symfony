<#
.SYNOPSIS
Packages a TuiApp application as an Inno Setup installer (.exe) and/or a portable .zip.

.DESCRIPTION
The installer holds the published host (renamed <app name>.exe), the payload (runtime\, app\, … as
the manifest's launch section expects them), app.manifest.json next to the .exe, and app.ico.
It registers app.fileAssociations (a ProgID plus an extension key each) and app.urlSchemes (URL
protocols) under HKCU\Software\Classes. app.category has no Windows equivalent and is ignored.

The icon: -Icon, else app.icon from the manifest (relative to it; not in the schema yet), else
app.ico or icon.png in the payload. A PNG becomes a multi-size .ico (16 to 256 px).

Signing, when a certificate is configured (-CertificateFile, or $env:TUIAPP_SIGN_CERT; password in
-CertificatePassword or $env:TUIAPP_SIGN_PASSWORD; or a store certificate by -CertificateThumbprint
or $env:TUIAPP_SIGN_THUMBPRINT): signtool signs the host .exe files, the installer and its
uninstaller, with an RFC 3161 timestamp ($env:TUIAPP_SIGN_TIMESTAMP). -PasswordEnv names the
environment variable that holds the password. A certificate file is imported into the user's store
for the run and removed after it, and signtool signs by thumbprint: the password never shows in a
command line.

-Formats is a comma-separated list of exe (the installer) and zip (one portable folder per
architecture). Each package path is printed alone on a line of stdout.

-Arch x64 or arm64 packages that architecture's host only (publish\win-<arch>), as the windows-x64 and
windows-arm64 targets do: an arm64 installer refuses other computers, an x64 one installs on x64 and,
emulated, on Arm. Without -Arch, the installer carries both hosts and installs the one that matches.

.EXAMPLE
.\package.ps1 -Manifest build\app.manifest.json -Payload build\payload -Out dist
#>
param(
    [Parameter(Mandatory)] [string] $Manifest,
    [Parameter(Mandatory)] [string] $Payload,
    [Parameter(Mandatory)] [string] $Out,
    # The self-contained host builds: dotnet publish App -c Release -r win-x64 / win-arm64 --self-contained -o publish\<rid>.
    # Default: publish\win-x64 and publish\win-arm64 next to this script.
    [string] $HostDir,
    [string] $ArmHostDir,
    [ValidateSet('', 'x64', 'arm64')] [string] $Arch = '',
    [string] $Icon,
    [string] $Iscc,
    [string] $Formats = 'exe',
    [Alias('Certificate')] [string] $CertificateFile = $env:TUIAPP_SIGN_CERT,
    [string] $CertificatePassword = $env:TUIAPP_SIGN_PASSWORD,
    [string] $PasswordEnv,
    [string] $CertificateThumbprint = $env:TUIAPP_SIGN_THUMBPRINT,
    [string] $TimestampUrl = $(if ($env:TUIAPP_SIGN_TIMESTAMP) { $env:TUIAPP_SIGN_TIMESTAMP } else { 'http://timestamp.digicert.com' })
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3
# Not in the param defaults: Windows PowerShell 5.1 sets $PSScriptRoot only once they are bound.
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $HostDir) { $HostDir = Join-Path $here 'publish\win-x64' }
if (-not $ArmHostDir) { $ArmHostDir = Join-Path $here 'publish\win-arm64' }

function Fail([string] $Message) { Write-Error "package: $Message"; exit 1 }

function Get-Prop($Object, [string] $Name) {
    if ($null -ne $Object -and $Object.PSObject.Properties.Name -contains $Name) { return $Object.$Name }
    return $null
}

# Inno Setup reads {, " and ; specially in [Registry] and defines.
function Escape-Inno([string] $Value) { return $Value.Replace('"', '""').Replace('{', '{{') }

function Find-Iscc {
    if ($Iscc) { return $Iscc }
    $command = Get-Command ISCC.exe -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    foreach ($root in @($env:LOCALAPPDATA + '\Programs', ${env:ProgramFiles(x86)}, $env:ProgramFiles)) {
        if (-not $root) { continue }
        $candidate = Join-Path $root 'Inno Setup 6\ISCC.exe'
        if (Test-Path $candidate) { return $candidate }
    }
    Fail 'ISCC.exe (Inno Setup 6) was not found: install it from https://jrsoftware.org/isdl.php or pass -Iscc.'
}

function Find-SignTool {
    $command = Get-Command signtool.exe -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    $kits = Join-Path ${env:ProgramFiles(x86)} 'Windows Kits\10\bin'
    $kitArch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { 'arm64' } else { 'x64' }
    $found = Get-ChildItem $kits -Filter signtool.exe -Recurse -ErrorAction SilentlyContinue |
        Where-Object { $_.Directory.Name -eq $kitArch } | Sort-Object FullName | Select-Object -Last 1
    if ($found) { return $found.FullName }
    Fail 'signing is configured but signtool.exe was not found (Windows SDK).'
}

# A folder's files into a .zip, with / in the entry names as the format wants (Compress-Archive and
# ZipFile.CreateFromDirectory of Windows PowerShell 5.1 write \), and three to four times as fast as
# Compress-Archive.
function New-Zip([string] $Folder, [string] $Zip) {
    Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
    $root = (Resolve-Path $Folder).Path.TrimEnd('\') + '\'
    $archive = [System.IO.Compression.ZipFile]::Open($Zip, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in Get-ChildItem -LiteralPath $root -Recurse -File -Force) {
            $entry = $file.FullName.Substring($root.Length).Replace('\', '/')
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $entry, [System.IO.Compression.CompressionLevel]::Optimal)
        }
    } finally {
        $archive.Dispose()
    }
}

# A PNG to a .ico of PNG-compressed images, 16 to 256 px: what Explorer picks from at every DPI.
function Convert-PngToIco([string] $Png, [string] $Ico) {
    Add-Type -AssemblyName System.Drawing
    $source = [System.Drawing.Image]::FromFile($Png)
    try {
        $images = foreach ($size in 16, 24, 32, 48, 64, 128, 256) {
            $bitmap = New-Object System.Drawing.Bitmap $size, $size
            $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
            $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
            $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
            $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
            $graphics.DrawImage($source, 0, 0, $size, $size)
            $graphics.Dispose()
            $stream = New-Object System.IO.MemoryStream
            $bitmap.Save($stream, [System.Drawing.Imaging.ImageFormat]::Png)
            $bitmap.Dispose()
            [pscustomobject]@{ Size = $size; Bytes = $stream.ToArray() }
        }
    } finally {
        $source.Dispose()
    }
    $file = [System.IO.File]::Create($Ico)
    $writer = New-Object System.IO.BinaryWriter $file
    try {
        $writer.Write([uint16]0); $writer.Write([uint16]1); $writer.Write([uint16]$images.Count)
        $offset = 6 + 16 * $images.Count
        foreach ($image in $images) {
            $dimension = if ($image.Size -ge 256) { 0 } else { $image.Size }
            $writer.Write([byte]$dimension); $writer.Write([byte]$dimension)
            $writer.Write([byte]0); $writer.Write([byte]0)
            $writer.Write([uint16]1); $writer.Write([uint16]32)
            $writer.Write([uint32]$image.Bytes.Length); $writer.Write([uint32]$offset)
            $offset += $image.Bytes.Length
        }
        foreach ($image in $images) { $writer.Write($image.Bytes) }
    } finally {
        $writer.Dispose()
    }
}

# --- Inputs -------------------------------------------------------------------------------------
if (-not (Test-Path $Manifest -PathType Leaf)) { Fail "no manifest at $Manifest" }
if (-not (Test-Path $Payload -PathType Container)) { Fail "no payload folder at $Payload" }
$Manifest = (Resolve-Path $Manifest).Path
$Payload = (Resolve-Path $Payload).Path
New-Item -ItemType Directory -Force -Path $Out | Out-Null
$Out = (Resolve-Path $Out).Path
$manifestDir = Split-Path $Manifest
$formatList = @($Formats -split '[,\s]+' | Where-Object { $_ })
foreach ($format in $formatList) { if ($format -notin @('exe', 'zip')) { Fail "unknown format $format (exe, zip)" } }
if (-not $formatList) { Fail 'no format (exe, zip)' }
if ($PasswordEnv) {
    $fromEnv = [Environment]::GetEnvironmentVariable($PasswordEnv)
    if ($fromEnv) { $CertificatePassword = $fromEnv }
}

$data = Get-Content -Raw -Encoding UTF8 $Manifest | ConvertFrom-Json
if ((Get-Prop $data 'manifest') -ne 1) { Fail 'manifest must be 1' }
$app = Get-Prop $data 'app'
$name = Get-Prop $app 'name'
$id = Get-Prop $app 'id'
$version = Get-Prop $app 'version'
if (-not $name -or -not $id -or -not $version) { Fail 'app.name, app.id and app.version are required' }
$publisher = Get-Prop $app 'publisher'; if (-not $publisher) { $publisher = "The $name authors" }
$copyright = Get-Prop $app 'copyright'; if (-not $copyright) { $copyright = '' }
$homepage = Get-Prop $app 'homepage'; if (-not $homepage) { $homepage = '' }
$description = Get-Prop $app 'description'; if (-not $description) { $description = '' }
# Windows PowerShell 5.1 too: no ?? operator.
$numeric = '0.0.0.0'
if ($version -match '^(\d+)(?:\.(\d+))?(?:\.(\d+))?') {
    $parts = foreach ($i in 1, 2, 3) { if ($Matches[$i]) { $Matches[$i] } else { '0' } }
    $numeric = ($parts + '0') -join '.'
}

if ($Arch -eq 'arm64') {
    # One host, the Arm one: what follows treats it as the only one.
    $HostDir = $ArmHostDir
}
if (-not (Test-Path (Join-Path $HostDir 'TuiAppHost.exe'))) {
    $rid = if ($Arch -eq 'arm64') { 'win-arm64' } else { 'win-x64' }
    Fail "no published host in $HostDir (dotnet publish App -c Release -r $rid --self-contained -o publish\$rid)"
}
$withArm = -not $Arch -and (Test-Path (Join-Path $ArmHostDir 'TuiAppHost.exe'))
$hostArch = if ($Arch) { $Arch } else { 'x64' }

$importedCertificate = $null
$work = Join-Path ([System.IO.Path]::GetTempPath()) ('tuiapp-package-' + [guid]::NewGuid().ToString('n'))
New-Item -ItemType Directory -Path $work | Out-Null
try {
    # The host, renamed after the application: Task Manager and Explorer show the .exe name.
    $exeName = (($name -replace '[\\/:*?"<>|]', '') -replace '\s+', ' ').Trim() + '.exe'
    $hosts = @(@{ From = $HostDir; To = Join-Path $work "host-$hostArch" })
    if ($withArm) { $hosts += @{ From = $ArmHostDir; To = Join-Path $work 'host-arm64' } }
    foreach ($h in $hosts) {
        Copy-Item -Recurse $h.From $h.To
        Rename-Item (Join-Path $h.To 'TuiAppHost.exe') $exeName
        # Not shipped: a manifest from a development run.
        Remove-Item -Force -ErrorAction SilentlyContinue (Join-Path $h.To 'app.manifest.json')
    }

    # --- Icon -----------------------------------------------------------------------------------
    if (-not $Icon) {
        $fromManifest = Get-Prop $app 'icon'
        if ($fromManifest) { $Icon = Join-Path $manifestDir $fromManifest }
        elseif (Test-Path (Join-Path $Payload 'app.ico')) { $Icon = Join-Path $Payload 'app.ico' }
        elseif (Test-Path (Join-Path $Payload 'icon.png')) { $Icon = Join-Path $Payload 'icon.png' }
    }
    $iconFile = ''
    if ($Icon) {
        if (-not (Test-Path $Icon)) { Fail "no icon at $Icon" }
        if ([System.IO.Path]::GetExtension($Icon) -eq '.png') {
            $iconFile = Join-Path $work 'app.ico'
            Convert-PngToIco (Resolve-Path $Icon).Path $iconFile
        } else {
            $iconFile = (Resolve-Path $Icon).Path
        }
    } else {
        Write-Warning 'package: no icon (-Icon, app.icon, app.ico or icon.png in the payload): the installer uses the default one.'
    }

    # --- Registry: file associations and URL protocols ------------------------------------------
    $exe = '{app}\' + $exeName
    # -- first: a path or URL that looks like an option (--dev …) stays a path.
    $command = '"""' + $exe + '"" -- ""%1"""'
    $iconValue = if ($iconFile) { '"{app}\app.ico"' } else { '"' + $exe + ',0"' }
    $lines = New-Object System.Collections.Generic.List[string]
    function Add-Key([string] $Subkey, [string] $ValueName, [string] $Value, [string] $Flags) {
        $entry = 'Root: HKA; Subkey: "Software\Classes\' + $Subkey + '"; ValueType: string; ValueName: "' + $ValueName + '"; ValueData: ' + $Value
        if ($Flags) { $entry += '; Flags: ' + $Flags }
        $lines.Add($entry)
    }
    foreach ($association in @(Get-Prop $app 'fileAssociations' | Where-Object { $_ })) {
        $extension = ([string](Get-Prop $association 'extension')).TrimStart('.')
        if ($extension -notmatch '^[A-Za-z0-9_+-]+$') { Fail "bad file association extension: $extension" }
        $progId = "$id.$extension"
        $label = Escape-Inno ([string](Get-Prop $association 'name'))
        Add-Key $progId '' ('"' + $label + '"') 'uninsdeletekey'
        Add-Key "$progId\DefaultIcon" '' $iconValue ''
        Add-Key "$progId\shell\open\command" '' $command ''
        if ((Get-Prop $association 'role') -eq 'viewer') { Add-Key "$progId\shell\open" 'FriendlyAppName' ('"' + (Escape-Inno $name) + '"') '' }
        # The extension points at the ProgID, and lists it in Open with.
        # The extension's keys may hold other applications' values: the uninstaller removes ours, and a
        # key only once it is empty (OpenWithProgids first, then the extension's).
        Add-Key ".$extension" '' ('"' + $progId + '"') 'uninsdeletevalue uninsdeletekeyifempty'
        Add-Key ".$extension\OpenWithProgids" $progId '""' 'uninsdeletevalue uninsdeletekeyifempty'
        $mime = Get-Prop $association 'mimeType'
        if ($mime) { Add-Key ".$extension" 'Content Type' ('"' + (Escape-Inno $mime) + '"') 'uninsdeletevalue uninsdeletekeyifempty' }
    }
    foreach ($scheme in @(Get-Prop $app 'urlSchemes' | Where-Object { $_ })) {
        if ($scheme -notmatch '^[a-z][a-z0-9+.-]*$') { Fail "bad URL scheme: $scheme" }
        Add-Key $scheme '' ('"URL:' + (Escape-Inno $name) + ' Protocol"') 'uninsdeletekey'
        Add-Key $scheme 'URL Protocol' '""' ''
        Add-Key "$scheme\DefaultIcon" '' $iconValue ''
        Add-Key "$scheme\shell\open\command" '' $command ''
    }
    $registryFile = Join-Path $work 'registry.iss'
    Set-Content -Encoding UTF8 -Path $registryFile -Value ($lines -join "`r`n")

    # --- Signing --------------------------------------------------------------------------------
    $signArguments = $null
    if ($CertificateFile -or $CertificateThumbprint) {
        $signtool = Find-SignTool
        $signArguments = @('sign', '/fd', 'sha256', '/tr', $TimestampUrl, '/td', 'sha256')
        if ($CertificateFile) {
            # Into the user's store for this run, so that the password never reaches a command line
            # (signtool /p would show it to every process of the computer).
            $flags = [System.Security.Cryptography.X509Certificates.X509KeyStorageFlags]'UserKeySet, PersistKeySet'
            $pfx = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 ((Resolve-Path $CertificateFile).Path), $CertificatePassword, $flags
            $store = New-Object System.Security.Cryptography.X509Certificates.X509Store 'My', 'CurrentUser'
            $store.Open('ReadWrite')
            try {
                if ($store.Certificates.Find('FindByThumbprint', $pfx.Thumbprint, $false).Count -eq 0) {
                    $store.Add($pfx)
                    $importedCertificate = $pfx
                }
            } finally {
                $store.Close()
            }
            $CertificateThumbprint = $pfx.Thumbprint
        }
        $signArguments += @('/s', 'My', '/sha1', $CertificateThumbprint)
        foreach ($h in $hosts) {
            & $signtool @signArguments (Join-Path $h.To $exeName)
            if ($LASTEXITCODE -ne 0) { Fail "signtool failed on $exeName ($LASTEXITCODE)" }
        }
    }

    # --- Portable zip ---------------------------------------------------------------------------
    if ($formatList -contains 'zip') {
        foreach ($h in $hosts) {
            $hostFolder = Split-Path -Leaf $h.To
            $folder = Join-Path $work "zip-$hostFolder"
            Copy-Item -Recurse $h.To $folder
            Copy-Item -Recurse (Join-Path $Payload '*') $folder
            Copy-Item $Manifest (Join-Path $folder 'app.manifest.json')
            if ($iconFile) { Copy-Item $iconFile (Join-Path $folder 'app.ico') }
            $zip = Join-Path $Out ((($name -replace '[^A-Za-z0-9.-]+', '-').Trim('-')) + "-$version-windows-$($hostFolder -replace '^host-', '').zip")
            Remove-Item -Force -ErrorAction SilentlyContinue $zip
            New-Zip $folder $zip
            Write-Output $zip
        }
    }
    if ($formatList -notcontains 'exe') { return }

    # --- Compile --------------------------------------------------------------------------------
    $iscc = Find-Iscc
    $outputName = (($name -replace '[^A-Za-z0-9.-]+', '-').Trim('-')) + "-$version-$(if ($Arch) { "windows-$Arch-" })setup"
    $defines = [ordered]@{
        AppName = $name; AppIdentifier = $id; AppVersion = $version; NumericVersion = $numeric
        AppPublisher = $publisher; AppCopyright = $copyright; AppUrl = $homepage; AppDescription = $description
        ExeName = $exeName; HostDir = $hosts[0].To; ArmHostDir = $(if ($withArm) { $hosts[1].To } else { '' })
        Architectures = $(if ($withArm) { 'x64compatible or arm64' } elseif ($Arch -eq 'arm64') { 'arm64' } else { 'x64compatible' })
        PayloadDir = $Payload; ManifestFile = $Manifest; IconFile = $iconFile
        OutputDir = $Out; OutputName = $outputName; RegistryFile = $registryFile
    }
    $arguments = @('/Q')
    foreach ($key in $defines.Keys) { $arguments += "/D$key=$($defines[$key])" }
    if ($signArguments) {
        $arguments += '/DSign=1'
        # $q is Inno Setup's quote: Windows PowerShell 5.1 does not escape the quotes inside a native
        # argument, and ISCC would get the command cut in pieces. $f is the quoted file to sign.
        $quoted = ($signArguments | ForEach-Object { if ($_ -match '\s') { '$q' + $_ + '$q' } else { $_ } }) -join ' '
        $arguments += '/Stuiapp=$q' + $signtool + '$q ' + $quoted + ' $f'
    }
    $arguments += (Join-Path $here 'Installer\app.iss')
    & $iscc @arguments | Out-Host
    if ($LASTEXITCODE -ne 0) { Fail "ISCC failed ($LASTEXITCODE)" }
    Write-Output (Join-Path $Out "$outputName.exe")
} finally {
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $work
    if ($importedCertificate) {
        $store = New-Object System.Security.Cryptography.X509Certificates.X509Store 'My', 'CurrentUser'
        $store.Open('ReadWrite')
        $store.Remove($importedCertificate)
        $store.Close()
    }
}
