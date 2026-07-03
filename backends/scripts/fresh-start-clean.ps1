param(
    [string]$WorkspaceId = "",
    [switch]$Force,
    [switch]$KeepConfigs,
    [switch]$SkipBackup
)

$ErrorActionPreference = "Stop"

function Run-Step {
    param([string]$Title, [scriptblock]$Block)
    Write-Host ""
    Write-Host "==> $Title" -ForegroundColor Cyan
    & $Block
}

$Root = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $Root

if (-not (Test-Path "artisan")) {
    throw "Run this script from the Laravel backend repo, or keep it inside scripts\."
}

if (-not $Force) {
    Write-Host "This will reset LOCAL app trading history/state/cache for a fresh start." -ForegroundColor Yellow
    Write-Host "It does NOT close real Binance positions. Close live exchange positions first." -ForegroundColor Yellow
    Write-Host "Run again with -Force to continue." -ForegroundColor Yellow
    exit 2
}

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupDir = Join-Path $Root "storage\app\reset-backups\$timestamp"
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

$php = @'
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$workspaceId = getenv('RESET_WORKSPACE_ID') ?: null;
$backupDir = getenv('RESET_BACKUP_DIR');
$skipBackup = getenv('RESET_SKIP_BACKUP') === '1';
$keepConfigs = getenv('RESET_KEEP_CONFIGS') === '1';

$whereWorkspace = function ($query) use ($workspaceId) {
    return $workspaceId ? $query->where('workspace_id', $workspaceId) : $query;
};

$tables = [
    'future_trade_bot_logs',
    'future_trade_bot_trades',
    'future_trade_logical_trade_events',
    'future_trade_logical_trades',
    'future_trade_missed_signal_audits',
    'future_trade_webhook_audits',
    'future_trade_order_audits',
    'robot_trade_audits',
    'quick_trades',
    'orders',
    'trades',
    'market_feed_status',
    'market_price_caches',
    'scheduler_runs',
    'jobs',
    'failed_jobs',
    'webhooks_inbox',
    'webhook_deliveries',
];

if (! $skipBackup) {
    foreach ($tables as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }

        $query = DB::table($table);
        if ($workspaceId && Schema::hasColumn($table, 'workspace_id')) {
            $query->where('workspace_id', $workspaceId);
        }

        $rows = $query->limit(20000)->get();
        File::put($backupDir.DIRECTORY_SEPARATOR.$table.'.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    if (Schema::hasTable('future_trade_bot_configs')) {
        $query = DB::table('future_trade_bot_configs');
        if ($workspaceId && Schema::hasColumn('future_trade_bot_configs', 'workspace_id')) {
            $query->where('workspace_id', $workspaceId);
        }
        File::put($backupDir.DIRECTORY_SEPARATOR.'future_trade_bot_configs.json', json_encode($query->get(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    if (Schema::hasTable('robot_sessions')) {
        $query = DB::table('robot_sessions');
        if ($workspaceId && Schema::hasColumn('robot_sessions', 'workspace_id')) {
            $query->where('workspace_id', $workspaceId);
        }
        File::put($backupDir.DIRECTORY_SEPARATOR.'robot_sessions.json', json_encode($query->get(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

DB::transaction(function () use ($tables, $workspaceId, $keepConfigs) {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    foreach ($tables as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }

        $query = DB::table($table);
        if ($workspaceId && Schema::hasColumn($table, 'workspace_id')) {
            $query->where('workspace_id', $workspaceId)->delete();
        } else {
            $query->delete();
        }
    }

    if (Schema::hasTable('future_trade_bot_states')) {
        $query = DB::table('future_trade_bot_states');
        if ($workspaceId && Schema::hasTable('future_trade_bot_configs')) {
            $ids = DB::table('future_trade_bot_configs')->where('workspace_id', $workspaceId)->pluck('id');
            $query->whereIn('future_trade_bot_config_id', $ids);
        }
        $query->update([
            'status' => 'STOPPED',
            'current_symbol' => null,
            'position_side' => 'FLAT',
            'entry_price' => 0,
            'quantity' => 0,
            'stop_loss' => null,
            'take_profit' => null,
            'trailing_take_profit' => null,
            'total_profit' => 0,
            'daily_loss' => 0,
            'daily_loss_locked' => false,
            'scanner_snapshot' => json_encode([]),
            'chart_points' => json_encode([]),
            'open_position' => json_encode([]),
            'runtime_json' => json_encode([]),
            'updated_at' => now(),
        ]);
    }

    if (Schema::hasTable('future_trade_bot_configs')) {
        $query = DB::table('future_trade_bot_configs');
        if ($workspaceId && Schema::hasColumn('future_trade_bot_configs', 'workspace_id')) {
            $query->where('workspace_id', $workspaceId);
        }
        $query->update([
            'enabled' => false,
            'paper_trading' => true,
            'enable_live_trading' => false,
            'updated_at' => now(),
        ]);
    }

    if (Schema::hasTable('robot_sessions')) {
        $query = DB::table('robot_sessions');
        if ($workspaceId && Schema::hasColumn('robot_sessions', 'workspace_id')) {
            $query->where('workspace_id', $workspaceId);
        }
        $query->update([
            'status' => 'STOPPED',
            'total_pnl' => 0,
            'win_count' => 0,
            'loss_count' => 0,
            'consecutive_losses' => 0,
            'last_signal_at' => null,
            'last_signal_direction' => null,
            'last_trade_at' => null,
            'last_error' => null,
            'runtime_json' => json_encode([]),
            'active_side' => null,
            'active_entry_price' => null,
            'active_since' => null,
            'peak_profit' => 0,
            'trade_stats_json' => json_encode([]),
            'updated_at' => now(),
        ]);
    }

    if (! $keepConfigs && Schema::hasTable('settings')) {
        $groups = ['runtime_safety'];
        DB::table('settings')
            ->whereIn('group', $groups)
            ->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->delete();
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

foreach ([
    'framework/autofinx-live-feed-heartbeat.json',
    'framework/autofinx-live-feed.json',
    'framework/autofinx-quick-settler-heartbeat.json',
    'framework/autofinx-robot-evaluator-heartbeat.json',
    'framework/future-trade-live-feed.json',
] as $relative) {
    $path = storage_path($relative);
    if (File::exists($path)) {
        File::delete($path);
    }
}

try {
    foreach (['live-feed:*', 'price:*', 'ts:*', 'future_trade_*', 'robot_session*', 'quick_trade*', 'binance:*'] as $pattern) {
        foreach (Redis::keys($pattern) as $key) {
            Redis::del($key);
        }
    }
} catch (Throwable $e) {
    echo "Redis cleanup skipped: {$e->getMessage()}\n";
}

Artisan::call('queue:restart');
Artisan::call('cache:clear');
Artisan::call('config:clear');
Artisan::call('route:clear');
Artisan::call('view:clear');
Artisan::call('event:clear');
Artisan::call('optimize:clear');
Artisan::call('schedule:clear-cache');
Artisan::call('queue:flush', ['--force' => true]);

echo json_encode([
    'ok' => true,
    'workspace_id' => $workspaceId,
    'backup_dir' => $skipBackup ? null : $backupDir,
    'keep_configs' => $keepConfigs,
    'message' => 'Local stale trading state, app trades, webhooks, feed cache, queues, and runtime caches were reset. Restart workers/live feed next.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
'@

$tmp = Join-Path $env:TEMP "autofinx-fresh-reset-$timestamp.php"
Set-Content -Path $tmp -Value "<?php`n$php" -Encoding UTF8

try {
    Run-Step "Backup + local trading reset" {
        $env:RESET_WORKSPACE_ID = $WorkspaceId
        $env:RESET_BACKUP_DIR = $backupDir
        $env:RESET_SKIP_BACKUP = if ($SkipBackup) { "1" } else { "0" }
        $env:RESET_KEEP_CONFIGS = if ($KeepConfigs) { "1" } else { "0" }
        php $tmp
    }

    Run-Step "Runtime repair/check" {
        php artisan runtime:repair --attempts=1
    }

    Run-Step "Future Trade runtime status" {
        php artisan future-trade:runtime-status --json
    }

    Write-Host ""
    Write-Host "Fresh local reset complete." -ForegroundColor Green
    Write-Host "Backup: $backupDir"
    Write-Host ""
    Write-Host "Next start commands, in separate terminals or Supervisor:" -ForegroundColor Yellow
    Write-Host "  php artisan future-trade:live-feed"
    Write-Host "  php artisan robot-sessions:evaluate --limit=50"
    Write-Host "  php artisan quick-trades:settle --limit=100"
    Write-Host "  php artisan queue:work --queue=critical,execution,market,signals,default --sleep=1 --tries=3"
} finally {
    Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
    Remove-Item Env:\RESET_WORKSPACE_ID -ErrorAction SilentlyContinue
    Remove-Item Env:\RESET_BACKUP_DIR -ErrorAction SilentlyContinue
    Remove-Item Env:\RESET_SKIP_BACKUP -ErrorAction SilentlyContinue
    Remove-Item Env:\RESET_KEEP_CONFIGS -ErrorAction SilentlyContinue
}
