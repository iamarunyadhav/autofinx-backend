# OPTIONAL, NOT REGISTERED BY THE AGENT. Run this yourself (elevated PowerShell) if you want the collector to
# start automatically at boot/logon. It only creates a Scheduled Task that calls start-collector.ps1.
# Remove later with:  Unregister-ScheduledTask -TaskName AutoFinxCollector -Confirm:$false
# It does not change any power / sleep setting. Disable sleep yourself (README) or boots/resumes create logged gaps.
param([string]$TaskName = 'AutoFinxCollector')
$script = Join-Path $PSScriptRoot 'start-collector.ps1'
$action  = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$script`""
$trigger = New-ScheduledTaskTrigger -AtStartup
$trigger.Delay = 'PT1M'                                   # let the network come up
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero)
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -RunLevel Limited `
    -Description 'Starts the AutoFinX public market-data collector (research only).'
