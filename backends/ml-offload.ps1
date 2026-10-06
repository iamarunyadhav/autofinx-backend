<#
  ml-offload.ps1 - train the AutoFinX ML shadow models on THIS PC instead of the production server.

  ONE command (PowerShell, from the backends folder):
      powershell -ExecutionPolicy Bypass -File .\ml-offload.ps1 -Server root@YOUR_SERVER_IP

  What it does, in order (re-runnable: finished parts are skipped, so it resumes after a stop):
    1  checks SSH (key login), PHP, Python
    2  creates a Python venv on D: and installs lightgbm / numpy / scikit-learn
    3  reads the cost + promotion settings from the server, so verdicts match the server
    4  creates an ISOLATED sqlite database on D: (your local MySQL / .env are never touched)
    5  downloads each symbol training data from the server (streamed, gzip, low priority, no disk used on the server)
    6  trains every symbol x horizon locally, one model at a time (full history, no row cap issues)
    7  packs the trained models, uploads them, and shows what the server would install
       (-Install actually installs them: shadow only, checksums verified, never replaces an existing champion)

  Safe: reads training tables on the server only; trading tables are never touched; nothing is installed
  on the server unless you pass -Install. Stop any time with Ctrl+C, or create the file  D:\autofinx-ml\STOP
  to finish the current model and stop.
#>
param(
    [Parameter(Mandatory = $true)][string]$Server,
    [string]$Work = 'D:\autofinx-ml',
    [string[]]$Symbols = @('ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'XRPUSDT', 'SUIUSDT'),
    [int[]]$Horizons = @(240, 1440, 120, 60, 15),
    [string]$RemoteApp = '/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends',
    [switch]$Install,
    [switch]$Refetch
)

$ErrorActionPreference = 'Continue'
$repo = Split-Path -Parent $MyInvocation.MyCommand.Path
$ssh = "ssh -o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=30 -o ServerAliveCountMax=20"
$artisanRemote = "cd $RemoteApp && nice -n 19 ionice -c3 runuser -u zcadmin -- /usr/local/bin/ea-php82 -d memory_limit=1024M artisan"
$todo = New-Object System.Collections.Generic.List[string]

function Step($n, $text) { Write-Host ""; Write-Host "=== STEP $n  $text ===" -ForegroundColor Cyan }
function Ok($t) { Write-Host "  OK    $t" -ForegroundColor Green }
function Info($t) { Write-Host "  INFO  $t" }
function Warn($t) { Write-Host "  WARN  $t" -ForegroundColor Yellow }
function Die($t) { Write-Host "  FAIL  $t" -ForegroundColor Red; exit 2 }
function RemoteOut($command) { $o = cmd /c "$ssh $Server `"$command`" 2>nul"; return $o }

if (-not (Test-Path (Join-Path $repo 'artisan'))) { Die "artisan not found next to this script ($repo). Run it from the backends folder." }
New-Item -ItemType Directory -Force -Path $Work, "$Work\data", "$Work\logs", "$Work\exports", "$Work\tmp", "$Work\pip-cache" | Out-Null
# Keep every heavy or temporary file on the work drive (C: is nearly full).
$env:TEMP = "$Work\tmp"
$env:TMP = "$Work\tmp"
$env:PIP_CACHE_DIR = "$Work\pip-cache"
$driveName = (Split-Path -Qualifier $Work).TrimEnd(':')
$freeGb = [math]::Round((Get-PSDrive $driveName).Free / 1GB, 1)

Step 1 "Checks"
Info "work folder $Work ($freeGb GB free on drive ${driveName}:)"
if ($freeGb -lt 15) { Warn "Less than 15 GB free on ${driveName}: - 5 coins need roughly 8-10 GB." }
$null = cmd /c "$ssh $Server echo ssh-ok 2>nul"
if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "  SSH key login to $Server does not work yet. One-time setup (enter the server password when asked):" -ForegroundColor Yellow
    Write-Host '      if (-not (Test-Path $HOME\.ssh\id_ed25519)) { ssh-keygen -t ed25519 -N "" -f $HOME\.ssh\id_ed25519 }'
    Write-Host "      type `$HOME\.ssh\id_ed25519.pub | ssh $Server `"mkdir -p ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys`""
    Write-Host "  Then run this script again."
    exit 2
}
Ok "SSH key login works."
$phpVersion = (& php -r "echo PHP_VERSION;") 2>$null
if (-not $phpVersion) { Die "php is not on PATH (XAMPP php.exe)." }
Ok "PHP $phpVersion"
$sysPython = (Get-Command python -ErrorAction SilentlyContinue)
if (-not $sysPython) { Die "python is not on PATH." }
Ok "Python found: $(& python --version)"

Step 2 "Python environment (lightgbm)"
$py = "$Work\venv\Scripts\python.exe"
if (-not (Test-Path $py)) { Info "creating venv..."; & python -m venv "$Work\venv" }
& $py -c "import lightgbm, numpy, sklearn" 2>$null
if ($LASTEXITCODE -ne 0) {
    Info "installing lightgbm numpy scikit-learn (a few minutes, once)..."
    & $py -m pip install --quiet --disable-pip-version-check "lightgbm>=4.0,<5.0" "numpy>=1.24,<3.0" "scikit-learn>=1.4,<2.0"
    & $py -c "import lightgbm, numpy, sklearn" 2>$null
    if ($LASTEXITCODE -ne 0) { Die "pip could not install the ML libraries (see output above)." }
}
Ok "lightgbm / numpy / scikit-learn ready."

Step 3 "Settings from the server (costs, promotion gates)"
$settings = RemoteOut "$artisanRemote ml:offload:settings"
$settings | Set-Content -Encoding ascii "$Work\settings.txt"
$count = 0
foreach ($line in $settings) {
    if ($line -match '^([A-Z0-9_]+)=(.*)$') { Set-Item -Path ("Env:" + $Matches[1]) -Value $Matches[2]; $count++ }
}
if ($count -lt 10) { Die "Could not read the settings from the server (got $count lines). Is the code pulled on the server (git pull)?" }
Ok "$count settings copied (saved in $Work\settings.txt)."

# Isolated local runtime: everything below overrides .env for THIS process only.
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = "$Work\ml.sqlite"
$env:CACHE_STORE = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:SESSION_DRIVER = 'array'
$env:LOG_CHANNEL = 'null'
$env:APP_CONFIG_CACHE = "$Work\no-config-cache.php"
$env:AUTOFINX_ML_SHADOW_ENABLED = 'true'
$env:AUTOFINX_ML_PYTHON_BIN = $py
$env:AUTOFINX_ML_ARTIFACT_DIR = "$Work\models"
$env:AUTOFINX_ML_CHAMPION_DIR = "$Work\champions"
$env:AUTOFINX_ML_EXPORT_DIR = "$Work\exports"
$env:AUTOFINX_ML_TRAINING_TIMEOUT_SECONDS = '21600'
$env:AUTOFINX_ML_TRAINING_STALL_SECONDS = '86400'
$env:AUTOFINX_ML_TRAINING_AUTO_RETRY = 'false'

Step 4 "Local training database (sqlite on $Work)"
Push-Location $repo
if (-not (Test-Path "$Work\ml.sqlite")) { New-Item -ItemType File -Path "$Work\ml.sqlite" | Out-Null }
& php -d memory_limit=1024M artisan migrate --force --no-interaction 2>&1 | Select-Object -Last 2
if ($LASTEXITCODE -ne 0) { Pop-Location; Die "Local migration failed (see output above)." }
Ok "Local database ready: $Work\ml.sqlite  (your local MySQL is not used)."

Step 5 "Download + import training data from the server"
foreach ($sym in $Symbols) {
    $file = "$Work\data\$sym.ndjson.gz"
    $marker = "$Work\data\$sym.imported"
    if ((Test-Path $marker) -and -not $Refetch) { Ok "$sym already imported (use -Refetch to download again)."; continue }
    for ($try = 1; $try -le 2; $try++) {
        $started = Get-Date
        Info "$sym : downloading (server runs it at low priority; a loaded server can take a while) attempt $try"
        $cmdFile = "$Work\fetch-$sym.cmd"
        "@echo off`r`n$ssh $Server `"$artisanRemote ml:offload:export $sym | nice -n 19 gzip -1`" > `"$file`"" | Set-Content -Encoding ascii $cmdFile
        cmd /c $cmdFile
        $sizeMb = [math]::Round((Get-Item $file).Length / 1MB, 1)
        Info "$sym : downloaded $sizeMb MB in $([math]::Round(((Get-Date) - $started).TotalMinutes, 1)) min, importing..."
        & php -d memory_limit=1024M artisan ml:offload:import $file --yes-local
        if ($LASTEXITCODE -eq 0) { Set-Content -Path $marker -Value (Get-Date).ToString('s'); break }
        Warn "$sym : import failed (cut download?) - trying again."
        Remove-Item $file -Force -ErrorAction SilentlyContinue
    }
    if (-not (Test-Path $marker)) { Warn "$sym : could not be downloaded; skipped. Run the script again later."; $todo.Add("Re-run the script: $sym data was not downloaded.") }
}

Step 6 "Train locally (one model at a time)"
Write-Host "  Keep the PC plugged in and awake. Stop safely: create the file $Work\STOP" -ForegroundColor Yellow
$doneFile = "$Work\done.txt"
if (-not (Test-Path $doneFile)) { New-Item -ItemType File -Path $doneFile | Out-Null }
$resultsFile = "$Work\results.txt"
$stopped = $false
foreach ($h in $Horizons) {
    foreach ($sym in $Symbols) {
        if (-not (Test-Path "$Work\data\$sym.imported")) { continue }
        $key = "${sym}:$h"
        if ((Get-Content $doneFile) -contains $key) { Info "$sym h$h : already trained (skipped)."; continue }
        if (Test-Path "$Work\STOP") { $stopped = $true; break }
        $log = "$Work\logs\train-$sym-$h.log"
        $started = Get-Date
        Info "$sym h$h : training started $($started.ToString('HH:mm:ss'))"
        cmd /c "php -d memory_limit=3072M artisan ml:train $sym $h --ignore-eligibility > `"$log`" 2>&1"
        $minutes = [math]::Round(((Get-Date) - $started).TotalMinutes, 1)
        $verdict = (Get-Content $log -TotalCount 1) -replace '\x1b\[[0-9;]*m', ''
        if ($LASTEXITCODE -eq 0 -and $verdict) {
            Add-Content $doneFile $key
            Add-Content $resultsFile "$minutes min  $verdict"
            Ok "$sym h$h : $verdict  ($minutes min)"
        } else {
            Warn "$sym h$h : training failed after $minutes min - see $log (it will be retried on the next run)."
            $todo.Add("Training failed: $sym h$h (log: $log)")
        }
    }
    if ($stopped) { break }
}
if ($stopped) { Warn "STOP file found: stopped after the current model. Delete $Work\STOP and run again to continue."; $todo.Add("Delete $Work\STOP and re-run to continue training.") }

Step 7 "Pack and send the models to the server"
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$bundle = "$Work\bundle-$stamp"
& php artisan ml:offload:pack $bundle
if (-not (Test-Path "$bundle\bundle.json")) { Pop-Location; Die "Nothing was packed." }
$remoteDir = "/home/zcadmin/ml-offload/$stamp"
$null = cmd /c "$ssh $Server `"mkdir -p /home/zcadmin/ml-offload`""
cmd /c "scp -o BatchMode=yes -r `"$bundle`" ${Server}:$remoteDir"
if ($LASTEXITCODE -ne 0) { Pop-Location; Die "Upload failed. The bundle stays in $bundle ; copy it with scp and run the install command below by hand." }
$null = cmd /c "$ssh $Server `"chown -R zcadmin:zcadmin /home/zcadmin/ml-offload`""
Ok "Bundle uploaded to $remoteDir"
$applyFlag = ''
if ($Install) { $applyFlag = ' --apply' }
Write-Host ""
Write-Host "  Server install$(if ($Install) { ' (APPLYING)' } else { ' (report only)' }):" -ForegroundColor Cyan
cmd /c "$ssh $Server `"$artisanRemote ml:offload:install $remoteDir$applyFlag`""
if (-not $Install) { $todo.Add("Happy with the report above? Install: powershell -ExecutionPolicy Bypass -File .\ml-offload.ps1 -Server $Server -Install   (nothing is retrained; it only packs and installs)") }
Pop-Location

Write-Host ""
Write-Host "=== SUMMARY ===" -ForegroundColor Cyan
if (Test-Path $resultsFile) { Get-Content $resultsFile | ForEach-Object { Write-Host "  $_" } }
if ($todo.Count -eq 0) { Write-Host "  Everything done." -ForegroundColor Green } else { Write-Host "  Remaining:"; $i = 1; foreach ($t in $todo) { Write-Host "   $i. $t"; $i++ } }
