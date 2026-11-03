; Build with build-installer.ps1 so the complete Flutter bundle and CRT are staged.
#if Ver < EncodeVer(6, 5, 0)
  #error Inno Setup 6.5 or newer is required (Arabic language support).
#endif

#ifndef BuildDir
  #define BuildDir SourcePath + "..\..\build\installer\payload"
#endif
#ifndef OutputDir
  #define OutputDir SourcePath + "..\..\build\installer"
#endif
#define AppExe "water_project.exe"
#define AppName "مشروع مياه الوشاح"
#if !FileExists(BuildDir + "\" + AppExe)
  #error Release bundle not found. Run windows\installer\build-installer.ps1 first.
#endif
#if !FileExists(BuildDir + "\flutter_windows.dll") || !FileExists(BuildDir + "\data\app.so") || !FileExists(BuildDir + "\data\icudtl.dat")
  #error Incomplete Flutter release bundle.
#endif
#if FileSize(BuildDir + "\flutter_windows.dll") > 35000000
  #error Debug flutter_windows.dll detected. Run windows\installer\build-installer.ps1 to stage the release engine.
#endif
#if !FileExists(BuildDir + "\vcruntime140.dll") || !FileExists(BuildDir + "\vcruntime140_1.dll") || !FileExists(BuildDir + "\msvcp140.dll")
  #error Microsoft x64 CRT is missing. Run build-installer.ps1 to stage it.
#endif
#ifndef AppVersion
  #define AppVersion GetVersionNumbersString(BuildDir + "\" + AppExe)
#endif
#ifndef AppFileVersion
  #define AppFileVersion GetVersionNumbersString(BuildDir + "\" + AppExe)
#endif

[Setup]
; Keep this ID unchanged across versions so upgrades use the same installation.
AppId={{7549115E-B52A-49B8-8E43-E9ACAF1565A1}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher=مشروع مياه الوشاح
VersionInfoVersion={#AppFileVersion}
VersionInfoDescription=مثبت مشروع مياه الوشاح
DefaultDirName={localappdata}\Programs\WaterProject
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0.17763
OutputDir={#OutputDir}
OutputBaseFilename=setup
SetupIconFile=..\runner\resources\app_icon.ico
UninstallDisplayIcon={app}\{#AppExe}
UninstallDisplayName={#AppName}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
WizardSizePercent=120
DisableWelcomePage=no
CloseApplications=yes
RestartApplications=no
SetupLogging=yes

[Languages]
Name: "arabic"; MessagesFile: "compiler:Languages\Arabic.isl"

[Tasks]
Name: "desktopicon"; Description: "إنشاء اختصار على سطح المكتب"; GroupDescription: "الاختصارات:"; Flags: unchecked

[Files]
; Includes Flutter engine, plugin DLLs, assets, AOT library and app-local CRT.
Source: "{#BuildDir}\*"; DestDir: "{app}"; Excludes: "*.pdb,*.ilk,*.exp,*.lib"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autoprograms}\{#AppName}"; Filename: "{app}\{#AppExe}"; WorkingDir: "{app}"; Check: not WizardNoIcons
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AppExe}"; WorkingDir: "{app}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#AppExe}"; Description: "تشغيل مشروع مياه الوشاح"; Flags: nowait postinstall skipifsilent; WorkingDir: "{app}"

; Do not delete AppData, databases, account settings or backups on uninstall.
