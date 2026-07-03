#define MyAppName "InfraControl Agent"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "InfraControl"
#define MyAppExeName "InfraControl.Agent.Config.cmd"
#define MyServiceWrapper "InfraControlAgentService.exe"
#define MyServiceName "InfraControlAgentService"
#define MyServiceDisplayName "InfraControl Agent Service"
#define MyUninstallRegKey "SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\E8D9A152-09CD-4EA4-B95F-F24C68B10D8A_is1"

[Setup]
AppId={{E8D9A152-09CD-4EA4-B95F-F24C68B10D8A}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\{#MyAppName}
DefaultGroupName=InfraControl
DisableProgramGroupPage=yes
OutputBaseFilename=InfraControl.Agent.Setup
OutputDir=..\dist\installer
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
CloseApplications=force
RestartApplications=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Dirs]
Name: "{commonappdata}\InfraControl\Agent\logs"

[Files]
Source: "stop-agent-for-upgrade.ps1"; DestDir: "{tmp}"; Flags: dontcopy
Source: "..\dist\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs restartreplace; Excludes: "installer"

[Icons]
Name: "{group}\InfraControl Agent Config"; Filename: "{app}\{#MyAppExeName}"
Name: "{autodesktop}\InfraControl Agent Config"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Code]
function TrimTrailingBackslash(const Value: String): String;
begin
  Result := Value;
  if (Length(Result) > 0) and (Result[Length(Result)] = '\') then
    SetLength(Result, Length(Result) - 1);
end;

function GetExistingInstallDir(): String;
var
  InstalledPath: String;
begin
  Result := ExpandConstant('{autopf}') + '\{#MyAppName}';
  if RegQueryStringValue(HKLM, '{#MyUninstallRegKey}', 'InstallLocation', InstalledPath) then
  begin
    if InstalledPath <> '' then
      Result := TrimTrailingBackslash(InstalledPath);
  end;
end;

procedure StopAgentForUpgrade();
var
  ResultCode: Integer;
  InstallDir: String;
  ScriptPath: String;
  Params: String;
begin
  InstallDir := GetExistingInstallDir();
  ExtractTemporaryFile('stop-agent-for-upgrade.ps1');
  ScriptPath := ExpandConstant('{tmp}\stop-agent-for-upgrade.ps1');
  Params := '-NoProfile -ExecutionPolicy Bypass -File "' + ScriptPath + '" -InstallDir "' + InstallDir + '"';
  if not Exec('powershell.exe', Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
  begin
    Log('Stop-agent upgrade script failed to launch.');
  end
  else
  begin
    Log('Stop-agent upgrade script finished with exit code ' + IntToStr(ResultCode));
  end;
end;

function InitializeSetup(): Boolean;
begin
  StopAgentForUpgrade();
  Result := True;
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  StopAgentForUpgrade();
  Result := '';
end;

[Run]
Filename: "{app}\{#MyServiceWrapper}"; Parameters: "install"; Flags: runhidden waituntilterminated; StatusMsg: "Registering Windows service..."
Filename: "{app}\{#MyServiceWrapper}"; Parameters: "start"; Flags: runhidden waituntilterminated; StatusMsg: "Starting InfraControl Agent service..."

[UninstallRun]
Filename: "{app}\{#MyServiceWrapper}"; Parameters: "stop"; Flags: runhidden waituntilterminated
Filename: "{app}\{#MyServiceWrapper}"; Parameters: "uninstall"; Flags: runhidden waituntilterminated
Filename: "{sys}\sc.exe"; Parameters: "stop ""{#MyServiceName}"""; Flags: runhidden waituntilterminated
Filename: "{sys}\sc.exe"; Parameters: "delete ""{#MyServiceName}"""; Flags: runhidden waituntilterminated
