<#
  ml-v4-local.ps1 - AutoFinX V4 path-aware research runner (LOCAL PC ONLY, research / shadow only).
  Spec + progress: docs/ml-engine-v1/. No SSH, no server, no install, no orders.

  Implemented now (P1): CHECK -> VENV -> DOWNLOAD (Binance 1m + 5m + funding + G7 metrics) -> VERIFY.
  Later phases (P2+) add BUILD_5M_FEATURES, BUILD_PATH_TARGETS, TRAIN, EVALUATE, REPORT.

  Examples (PowerShell, from autofinx-backend\backends):
    Full data (BTC/ETH/BNB 60 months, SOL/XRP/SUI 36 months):
      powershell -ExecutionPolicy Bypass -File .\ml-v4-local.ps1
    Smoke test (BTC, 2 months):
      powershell -ExecutionPolicy Bypass -File .\ml-v4-local.ps1 -Symbols BTCUSDT -Months 2
    Resume after a stop or crash: run the same command again (finished files are skipped).
    Verify only:   ... -VerifyOnly          Download only:  ... -DownloadOnly
    Re-download a symbol from scratch:  ... -Symbols ETHUSDT -Refetch
  Stop safely: create the file <Work>\STOP (current file finishes, nothing half-written is kept).
#>
param(
    [string]$Symbols = 'BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT,SUIUSDT',
    [string]$Work = 'D:\autofinx-ml-v4',
    [int]$Months = 0,
    [switch]$NoMetrics,
    [switch]$DownloadOnly,
    [switch]$VerifyOnly,
    [switch]$Refetch
)

$ErrorActionPreference = 'Continue'
$repo = Split-Path -Parent $MyInvocation.MyCommand.Path
$engine = Join-Path $repo 'external-predictive-engine'
$symList = @($Symbols -split '[,; ]+' | Where-Object { $_ -ne '' } | ForEach-Object { $_.ToUpper() })

function Step($text) { Write-Host ""; Write-Host "=== $text ===" -ForegroundColor Cyan }
function Ok($t) { Write-Host "  OK    $t" -ForegroundColor Green }
function Warn($t) { Write-Host "  WARN  $t" -ForegroundColor Yellow }
function Die($t) { Write-Host "  FAIL  $t" -ForegroundColor Red; exit 2 }

New-Item -ItemType Directory -Force -Path $Work, "$Work\tmp", "$Work\pip-cache", "$Work\reports", "$Work\logs" | Out-Null
# Keep every heavy or temporary file on the work drive (C: is nearly full).
$env:TEMP = "$Work\tmp"; $env:TMP = "$Work\tmp"; $env:PIP_CACHE_DIR = "$Work\pip-cache"
$env:PYTHONPATH = $engine
$started = Get-Date

Step "CHECK"
if (-not (Test-Path (Join-Path $engine 'v4\cli.py'))) { Die "v4 package not found under $engine" }
$drive = (Split-Path -Qualifier $Work).TrimEnd(':')
$freeGb = [math]::Round((Get-PSDrive $drive).Free / 1GB, 1)
Ok "work $Work ($freeGb GB free); symbols $($symList -join ', ')$(if ($Months -gt 0) { "; months override $Months (smoke)" } else { '; months per symbol: BTC/ETH/BNB 60, SOL/XRP/SUI 36' })"
if ($freeGb -lt 10) { Warn "Less than 10 GB free on ${drive}: (full 6-coin data is roughly 1.5-2 GB, more after targets)." }
if (-not (Get-Command python -ErrorAction SilentlyContinue)) { Die "python is not on PATH." }
if (Test-Path "$Work\STOP") { Warn "STOP file present in $Work - delete it to run."; exit 2 }

Step "VENV (isolated, pinned: external-predictive-engine\requirements-v4.txt)"
$py = "$Work\venv\Scripts\python.exe"
if (-not (Test-Path $py)) { & python -m venv "$Work\venv" }
& $py -c "import lightgbm, numpy, sklearn, pyarrow" 2>$null
if ($LASTEXITCODE -ne 0) {
    & $py -m pip install --quiet --disable-pip-version-check -r (Join-Path $engine 'requirements-v4.txt')
    & $py -c "import lightgbm, numpy, sklearn, pyarrow" 2>$null
    if ($LASTEXITCODE -ne 0) { Die "Could not install the V4 Python environment (see output above)." }
}
& $py -m pip freeze --disable-pip-version-check | Set-Content -Encoding ascii "$Work\requirements-v4.lock.txt"
Ok "venv ready (exact versions in $Work\requirements-v4.lock.txt)"

$common = @('--work', $Work, '--symbols', ($symList -join ','))
if ($Months -gt 0) { $common += @('--months', "$Months") }

if ($Refetch) {
    foreach ($s in $symList) { if (Test-Path "$Work\data\$s") { Remove-Item -Recurse -Force "$Work\data\$s"; Warn "$s data removed for a fresh download." } }
}

$rc = 0
if (-not $VerifyOnly) {
    Step "DOWNLOAD (Binance official bulk + REST tail; resumable)"
    $dlArgs = @('-m', 'v4.cli', 'download') + $common
    if (-not $NoMetrics) { $dlArgs += '--metrics' }
    & $py @dlArgs 2>&1 | Tee-Object -FilePath "$Work\logs\download-$(Get-Date -Format yyyyMMdd-HHmmss).log"
    if ($LASTEXITCODE -eq 2) { Warn "Download stopped (STOP file or error). Run the same command again to resume."; exit 2 }
}

if (-not $DownloadOnly) {
    Step "VERIFY (gaps, duplicates, OHLC, closed candles, 1m vs 5m alignment, coverage, funding, G7 metrics)"
    & $py -m v4.cli verify @common 2>&1 | Tee-Object -FilePath "$Work\logs\verify-$(Get-Date -Format yyyyMMdd-HHmmss).log"
    $rc = $LASTEXITCODE
    Write-Host "  Reports: $Work\reports\data_<SYMBOL>.json"
}

Step "DONE in $([math]::Round(((Get-Date) - $started).TotalMinutes, 1)) min"
switch ($rc) { 0 { Ok "all symbols OK" } 1 { Warn "warnings above (gaps/coverage are reported, never filled)" } default { Write-Host "  FAIL  problems above" -ForegroundColor Red } }
Write-Host "  Next phases (P2+: path targets, training) are not built yet - see docs\ml-engine-v1\PROGRESS.md"
exit $rc
