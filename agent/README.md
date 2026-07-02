# InfraControl Windows Agent

`agent/` contains the Windows-only v1 agent implementation for InfraControl.

## Projects

- `src/InfraControl.Agent.Core`: shared configuration, inventory collection, API client, and runtime orchestration.
- `src/InfraControl.Agent.Service`: Windows Service host for enroll + heartbeat.
- `src/InfraControl.Agent.Config`: WinForms configuration app for operators.
- `tests/InfraControl.Agent.Tests`: unit tests for core behavior.
- `installer/`: WiX project that packages the published service and config app into an MSI.

## Build Notes

- Restore/build the solution with `dotnet restore agent/InfraControl.Agent.sln` and `dotnet build agent/InfraControl.Agent.sln`.
- Publish binaries for packaging with:
  - `dotnet publish agent/src/InfraControl.Agent.Service/InfraControl.Agent.Service.csproj -c Release -r win-x64 --self-contained true /p:PublishSingleFile=true`
  - `dotnet publish agent/src/InfraControl.Agent.Config/InfraControl.Agent.Config.csproj -c Release -r win-x64 --self-contained true /p:PublishSingleFile=true`
- Build the MSI with `dotnet build agent/installer/InfraControl.Agent.Setup.wixproj -c Release -p:ServicePublishDir=<service publish dir> -p:ConfigPublishDir=<config publish dir> -p:AssetsDir=<repo>/agent/assets`
- Build the EXE bootstrapper with `dotnet build agent/installer/InfraControl.Agent.Bundle.wixproj -c Release -p:MsiPath=<repo>/agent/installer/bin/x64/Release/InfraControl.Agent.Setup.msi`

## Runtime Files

- Live config: `%ProgramData%\InfraControl\Agent\config.json`
- Runtime status: `%ProgramData%\InfraControl\Agent\status.json`
- Start Menu shortcut: `InfraControl Agent Config`
