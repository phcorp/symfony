; The installer of one TuiApp application, compiled by package.ps1 with /D defines; not meant to be
; compiled by hand. Installs for the current user: no administrator rights, nothing outside the profile.

#ifndef AppName
  #error Run package.ps1: it passes the defines.
#endif

[Setup]
AppId={#AppIdentifier}
AppName={#AppName}
AppVersion={#AppVersion}
VersionInfoVersion={#NumericVersion}
AppPublisher={#AppPublisher}
AppCopyright={#AppCopyright}
#if AppUrl != ""
AppPublisherURL={#AppUrl}
AppSupportURL={#AppUrl}
#endif
#if AppDescription != ""
AppComments={#AppDescription}
#endif
DefaultDirName={localappdata}\Programs\{#AppName}
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
DisableDirPage=auto
PrivilegesRequired=lowest
ArchitecturesAllowed={#Architectures}
ArchitecturesInstallIn64BitMode={#Architectures}
OutputDir={#OutputDir}
OutputBaseFilename={#OutputName}
#if IconFile != ""
SetupIconFile={#IconFile}
#endif
UninstallDisplayIcon={app}\{#ExeName}
UninstallDisplayName={#AppName}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
ChangesAssociations=yes
CloseApplications=yes
#ifdef Sign
SignTool=tuiapp
SignedUninstaller=yes
#endif

[Files]
#if ArmHostDir != ""
; Two builds of the host, one installed: Windows on Arm gets its own, not x64 under emulation.
Source: "{#HostDir}\*"; DestDir: "{app}"; Flags: recursesubdirs ignoreversion; Check: not IsArm64
Source: "{#ArmHostDir}\*"; DestDir: "{app}"; Flags: recursesubdirs ignoreversion; Check: IsArm64
#else
Source: "{#HostDir}\*"; DestDir: "{app}"; Flags: recursesubdirs ignoreversion
#endif
Source: "{#PayloadDir}\*"; DestDir: "{app}"; Flags: recursesubdirs ignoreversion createallsubdirs
; The manifest goes next to the .exe: the host reads nothing else.
Source: "{#ManifestFile}"; DestDir: "{app}"; DestName: "app.manifest.json"; Flags: ignoreversion
#if IconFile != ""
Source: "{#IconFile}"; DestDir: "{app}"; DestName: "app.ico"; Flags: ignoreversion
#endif

[Icons]
#if IconFile != ""
Name: "{userprograms}\{#AppName}"; Filename: "{app}\{#ExeName}"; IconFilename: "{app}\app.ico"
Name: "{userdesktop}\{#AppName}"; Filename: "{app}\{#ExeName}"; IconFilename: "{app}\app.ico"; Tasks: desktopicon
#else
Name: "{userprograms}\{#AppName}"; Filename: "{app}\{#ExeName}"
Name: "{userdesktop}\{#AppName}"; Filename: "{app}\{#ExeName}"; Tasks: desktopicon
#endif

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; Flags: unchecked

[Registry]
; File associations and URL protocols, from app.fileAssociations and app.urlSchemes.
#include RegistryFile

[Run]
Filename: "{app}\{#ExeName}"; Description: "Start {#AppName}"; Flags: nowait postinstall skipifsilent
