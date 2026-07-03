param(
    [Parameter(Mandatory = $true)]
    [string]$InstallDir
)

$ErrorActionPreference = 'SilentlyContinue'

$ServiceName = 'InfraControlAgentService'
$WrapperExe = Join-Path $InstallDir 'InfraControlAgentService.exe'
$NodeExe = Join-Path $InstallDir 'node.exe'

function Wait-ServiceStopped {
    param(
        [string]$Name,
        [int]$TimeoutSeconds = 45
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)

    while ((Get-Date) -lt $deadline) {
        $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
        if (-not $service) {
            return $true
        }

        $service.Refresh()
        if ($service.Status -eq 'Stopped') {
            return $true
        }

        Start-Sleep -Seconds 1
    }

    return $false
}

function Stop-AgentNodeProcesses {
    param([string]$Directory)

    if (-not $Directory) {
        return
    }

    $normalized = $Directory.TrimEnd('\')

    Get-CimInstance Win32_Process -Filter "Name = 'node.exe'" |
        Where-Object {
            ($_.ExecutablePath -and $_.ExecutablePath.StartsWith($normalized, [StringComparison]::OrdinalIgnoreCase)) -or
            ($_.CommandLine -and $_.CommandLine -like "*$normalized*")
        } |
        ForEach-Object {
            Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
        }
}

$service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($service -and $service.Status -in @('Running', 'StartPending', 'PausePending', 'ContinuePending')) {
    Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
    Wait-ServiceStopped -Name $ServiceName | Out-Null
}

if (Test-Path -LiteralPath $WrapperExe) {
    & $WrapperExe stop 2>$null
    Start-Sleep -Seconds 2
    & $WrapperExe uninstall 2>$null
    Start-Sleep -Seconds 2
}

Stop-AgentNodeProcesses -Directory $InstallDir

if (Test-Path -LiteralPath $NodeExe) {
    Get-Process -Name 'node' -ErrorAction SilentlyContinue |
        Where-Object { $_.Path -and $_.Path.Equals($NodeExe, [StringComparison]::OrdinalIgnoreCase) } |
        ForEach-Object { Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue }
}

Start-Sleep -Seconds 2

& sc.exe stop $ServiceName 2>$null
Start-Sleep -Seconds 2
& sc.exe delete $ServiceName 2>$null

exit 0
