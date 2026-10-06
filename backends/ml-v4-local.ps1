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
    [switch]$Refetch,
    [string]$Horizons = '15,60,120,240,1440',
    [switch]$TargetsOnly,
    [switch]$SkipFeatures,
    [switch]$ForceRebuildFeatures,
    [switch]$ForceRebuildTargets,
    [switch]$PatternFeatures
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
if (-not $VerifyOnly -and -not $TargetsOnly) {
    Step "DOWNLOAD (Binance official bulk + REST tail; resumable)"
    $dlArgs = @('-m', 'v4.cli', 'download') + $common
    if (-not $NoMetrics) { $dlArgs += '--metrics' }
    & $py @dlArgs 2>&1 | Tee-Object -FilePath "$Work\logs\download-$(Get-Date -Format yyyyMMdd-HHmmss).log"
    if ($LASTEXITCODE -eq 2) { Warn "Download stopped (STOP file or error). Run the same command again to resume."; exit 2 }
}

if (-not $DownloadOnly -and -not $TargetsOnly) {
    Step "VERIFY (gaps, duplicates, OHLC, closed candles, 1m vs 5m alignment, coverage, funding, G7 metrics)"
    & $py -m v4.cli verify @common 2>&1 | Tee-Object -FilePath "$Work\logs\verify-$(Get-Date -Format yyyyMMdd-HHmmss).log"
    $rc = $LASTEXITCODE
    Write-Host "  Reports: $Work\reports\data_<SYMBOL>.json"
    if ($rc -ge 2) { Write-Host "  FAIL  data problems: features/targets are not built on failed data." -ForegroundColor Red; exit $rc }
}

if (-not $DownloadOnly -and -not $VerifyOnly) {
    $symArg = @('--work', $Work, '--symbols')
    if (-not $SkipFeatures) {
        Step "BUILD_5M_FEATURES (exact V3 features via the PHP ShadowFeatureBuilder, isolated sqlite)"
        if (-not (Get-Command php -ErrorAction SilentlyContinue)) { Die "php is not on PATH (needed for exact feature parity, SPEC 72)." }
        # Process-level overrides only: the developer .env / MySQL is never touched.
        New-Item -ItemType Directory -Force -Path "$Work\features" | Out-Null
        $env:DB_CONNECTION = 'sqlite'; $env:DB_DATABASE = "$Work\features\features.sqlite"
        $env:CACHE_STORE = 'file'; $env:QUEUE_CONNECTION = 'sync'; $env:SESSION_DRIVER = 'array'; $env:LOG_CHANNEL = 'null'
        $env:APP_CONFIG_CACHE = "$Work\no-config-cache.php"; $env:AUTOFINX_ML_SHADOW_ENABLED = 'true'
        $env:AUTOFINX_ML_SYMBOLS = 'BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT,SUIUSDT,' + ($symList -join ',')
        if (-not (Test-Path "$Work\features\features.sqlite")) { New-Item -ItemType File -Path "$Work\features\features.sqlite" | Out-Null }
        Push-Location $repo
        & php -d memory_limit=1024M artisan migrate --force --no-interaction 2>&1 | Select-Object -Last 1
        if ($LASTEXITCODE -ne 0) { Pop-Location; Die "local sqlite migration failed" }
        foreach ($s in $symList) {
            if ((Test-Path "$Work\features\$s\v3.json") -and -not $ForceRebuildFeatures) { Ok "$s features already built (use -ForceRebuildFeatures to redo)"; continue }
            if (Test-Path "$Work\STOP") { Warn "STOP file found - stopping before $s."; Pop-Location; exit 2 }
            $t = Get-Date
            & $py -m v4.cli import-candles @symArg $s
            Write-Host "  $s : PHP feature build started $(Get-Date -Format HH:mm:ss) (several minutes per coin)"
            & php -d memory_limit=4096M artisan ml:features:build "--symbol=$s" --full
            if ($LASTEXITCODE -ne 0) { Warn "$s : PHP feature build failed (see above)"; $rc = 2; continue }
            & $py -m v4.cli export-features @symArg $s
            if ($LASTEXITCODE -ne 0) { Warn "$s : feature export failed"; $rc = 2; continue }
            Ok "$s features in $([math]::Round(((Get-Date) - $t).TotalMinutes, 1)) min -> $Work\features\$s\v3.parquet"
        }
        if ($PatternFeatures) {
            # P4B research schema v4 (v3 + PatternFeatures G1-G6). Opt-in here only; the server never builds it.
            $env:AUTOFINX_ML_EXTRA_FEATURE_SCHEMAS = 'qt-shadow-features-v4'
            foreach ($s in $symList) {
                if ((Test-Path "$Work\features\$s\v4.json") -and -not $ForceRebuildFeatures) { Ok "$s pattern features already built"; continue }
                if (Test-Path "$Work\STOP") { Warn "STOP file found - stopping before $s."; Pop-Location; exit 2 }
                $t = Get-Date
                Write-Host "  $s : PHP pattern-feature (v4) build started $(Get-Date -Format HH:mm:ss)"
                & php -d memory_limit=4096M artisan ml:features:build "--symbol=$s" --full
                if ($LASTEXITCODE -ne 0) { Warn "$s : v4 feature build failed"; $rc = 2; continue }
                & $py -m v4.cli export-features @symArg $s --schema qt-shadow-features-v4
                if ($LASTEXITCODE -ne 0) { Warn "$s : v4 export failed"; $rc = 2; continue }
                Ok "$s pattern features in $([math]::Round(((Get-Date) - $t).TotalMinutes, 1)) min -> $Work\features\$s\v4.parquet"
            }
            Remove-Item Env:AUTOFINX_ML_EXTRA_FEATURE_SCHEMAS -ErrorAction SilentlyContinue
        }
        Pop-Location
    }

    Step "BUILD_PATH_TARGETS (Layer A, policy-independent; horizons $Horizons)"
    foreach ($s in $symList) {
        $hs = @($Horizons -split '[,; ]+' | Where-Object { $_ -ne '' })
        if (-not $ForceRebuildTargets) { $hs = @($hs | Where-Object { -not (Test-Path "$Work\targets\$s\h$_.parquet") }) }
        if ($hs.Count -eq 0) { Ok "$s targets already built (use -ForceRebuildTargets to redo)"; continue }
        & $py -m v4.cli targets @symArg $s --horizons ($hs -join ',')
        if ($LASTEXITCODE -ne 0) { Warn "$s : target build failed"; $rc = 2 }
    }
    Write-Host "  Targets: $Work\targets\<SYMBOL>\h<H>.parquet (+ .json)   Features: $Work\features\<SYMBOL>\v3.parquet"
}

Step "DONE in $([math]::Round(((Get-Date) - $started).TotalMinutes, 1)) min"
switch ($rc) { 0 { Ok "all steps OK" } 1 { Warn "warnings above (gaps/coverage are reported, never filled)" } default { Write-Host "  FAIL  problems above" -ForegroundColor Red } }
Write-Host "  Next: P3 (V3-vs-V4 label audit, path distributions, policy lab) - see docs\ml-engine-v1\PROGRESS.md"
exit $rc
