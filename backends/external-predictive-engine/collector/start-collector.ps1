# Starts the AutoFinX market-data collector as a detached, hidden, below-normal-priority background process.
# Safe to run twice: the collector refuses a second instance (lock file + heartbeat check).
# Host-agnostic: override with  -Python <path to venv python>  and  -Config <path to collector.config.json>.
param(
    [string]$Python = $(if ($env:AUTOFINX_COLLECTOR_PYTHON) { $env:AUTOFINX_COLLECTOR_PYTHON } else { 'D:\autofinx-collector\venv\Scripts\python.exe' }),
    [string]$Config = ''
)
$ErrorActionPreference = 'Stop'
$engineDir = Split-Path -Parent $PSScriptRoot          # ...\external-predictive-engine  (so `-m collector` resolves)
$cfgPath   = if ($Config) { $Config } else { Join-Path $PSScriptRoot 'collector.config.json' }
$cfgArgs   = @('--config', $cfgPath)

$cfg = Get-Content $cfgPath -Raw | ConvertFrom-Json
$logDir = Join-Path $cfg.data_dir 'logs'
New-Item -ItemType Directory -Force $logDir | Out-Null

& $Python -m collector @cfgArgs status *> $null
if ($LASTEXITCODE -eq 0) { Write-Output 'collector already running'; exit 0 }

$argList = @('-m', 'collector') + $cfgArgs + @('start')
$p = Start-Process -FilePath $Python -ArgumentList $argList -WorkingDirectory $engineDir `
        -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $logDir 'stdout.log') -RedirectStandardError (Join-Path $logDir 'stderr.log')
try { $p.PriorityClass = 'BelowNormal' } catch { }
Write-Output "collector launched (launcher pid $($p.Id)); check with:  $Python -m collector $($cfgArgs -join ' ') status"
