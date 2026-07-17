param(
    [string]$AgentPath = "$PSScriptRoot\print-station-agent.ps1",
    [string]$ConfigPath = "$PSScriptRoot\print-station-config.json",
    [string]$TaskName = "Restaurant POS Print Station Agent"
)

$ErrorActionPreference = "Stop"

if (!(Test-Path -LiteralPath $AgentPath)) {
    throw "Agent script not found: $AgentPath"
}

if (!(Test-Path -LiteralPath $ConfigPath)) {
    throw "Config file not found: $ConfigPath"
}

$ResolvedAgentPath = (Resolve-Path -LiteralPath $AgentPath).Path
$ResolvedConfigPath = (Resolve-Path -LiteralPath $ConfigPath).Path
$Arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$ResolvedAgentPath`" -ConfigPath `"$ResolvedConfigPath`""

$Action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $Arguments
$Trigger = New-ScheduledTaskTrigger -AtLogOn
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -Description "Pulls Restaurant POS print jobs and prints them to reception PC printers." -Force | Out-Null

Write-Host "Scheduled task '$TaskName' installed."
