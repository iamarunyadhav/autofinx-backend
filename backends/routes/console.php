<?php

use App\Domain\Ops\FeatureFlagService;
use App\Jobs\DetectStaleFeedsJob;
use App\Jobs\DispatchAiDecisionCycleJob;
use App\Jobs\ExpireAiDecisionsJob;
use App\Jobs\KeepAliveUserStreamsJob;
use App\Models\SchedulerRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

// Laravel 12 loads scheduled definitions from this command route file. Keep the
// V5 research cadence here so the deployment flags are effective; these jobs are
// research/shadow-only and never authorize execution or model promotion.
if ((bool) config('market_opportunity_brain.v5_refresh_schedule_enabled', false)) {
    Schedule::command('v5:research-refresh --live --limit=3')
        ->everyFifteenMinutes()
        ->withoutOverlapping(10)
        ->runInBackground();
}

if ((bool) config('market_opportunity_brain.v5_outcome_schedule_enabled', false)) {
    Schedule::command('v5:research-evaluate-outcomes')
        ->hourly()
        ->withoutOverlapping(10)
        ->runInBackground();
}

$markRunStart = function (string $command): void {
    SchedulerRun::create([
        'id' => (string) Str::uuid(),
        'command' => $command,
        'started_at' => now(),
        'status' => 'running',
    ]);
};

$markRunEnd = function (string $command): void {
    $run = SchedulerRun::where('command', $command)->orderByDesc('started_at')->first();
    if (! $run) {
        return;
    }

    $run->finished_at = now();
    $run->duration_ms = $run->started_at
        ? $run->started_at->diffInMilliseconds($run->finished_at)
        : null;
    $run->status = 'completed';
    $run->save();
};

$sharedHostingMode = (bool) config('future_trade.shared_hosting_mode', true);
$schedulerEnabled = (bool) config('autofinx.scheduler.enabled', true);
if ($schedulerEnabled && config('nexus.enabled', false)) {
    Schedule::command('nexus:run-cycle')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(5)->runInBackground();
}
// Market Context (shadow-only). Interval >= 10 min, SHORT overlap-lock expiry (the default 24h lock once froze other schedules), no daemon.
if ($schedulerEnabled && config('market_context.enabled', false)) {
    Schedule::command('market-context:collect')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(10)->runInBackground();
    Schedule::command('market-context:snapshot')->cron('2,17,32,47 * * * *')->onOneServer()->withoutOverlapping(10)->runInBackground();
    Schedule::command('market-context:prune')->dailyAt('04:23')->onOneServer()->withoutOverlapping(30)->runInBackground();
}
$supervisorRuntime = (bool) config('autofinx.runtime.supervisor', false);
$allowMarketConsumers = (bool) config('autofinx.scheduler.market_consumers', false);
$allowBinanceRest = (bool) config('autofinx.scheduler.binance_rest', false);
$allowMetals = (bool) config('autofinx.scheduler.metals', false);
$allowRuntimeAutoRepair = (bool) config('autofinx.scheduler.runtime_auto_repair', true);
$scheduleFutureTradeRuntime = (bool) config('future_trade.schedule.runtime_commands', config('future_trade.schedule_runtime_commands', false));
$scheduleFutureTradeDiagnostics = (bool) config('future_trade.schedule.diagnostics', false);
$scheduleFutureTradeAuditMissedSignals = (bool) config('future_trade.schedule.audit_missed_signals', false);
$scheduleFutureTradeReconcile = (bool) config('future_trade.schedule.reconcile', false);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('connections:sync')
    ->cron(config('exchange.sync_cron', '*/10 * * * *'))
    ->onOneServer()
    ->runInBackground()
    ->before(fn () => $markRunStart('connections:sync'))
    ->after(fn () => $markRunEnd('connections:sync'));

Schedule::command('strategy:heal')
    ->everyTenMinutes()
    ->onOneServer()
    ->runInBackground()
    ->before(fn () => $markRunStart('strategy:heal'))
    ->after(fn () => $markRunEnd('strategy:heal'));

/*
Temporarily disabled to reduce unnecessary Binance REST pressure while only Future Trade and Fast2X are needed.
Schedule::command('orders:poll-status')
    ->everyTwoMinutes()
    ->onOneServer()
    ->runInBackground();
*/

if ($schedulerEnabled && ! $supervisorRuntime && ! $sharedHostingMode && $allowMarketConsumers) {
    Schedule::command('market:ws-consume --once --store-ohlc')
        ->everyTenSeconds()
        ->withoutOverlapping(5)
        ->runInBackground();

    Schedule::command('market:ws-consume --once --market-type=futures --store-ohlc --ohlc-intervals=1m,5m,15m,1h,4h')
        ->everyTenSeconds()
        ->withoutOverlapping(5)
        ->runInBackground();
}

if ($schedulerEnabled && $allowMetals) {
    Schedule::command('metals:poll-prices --once')
        ->everyFiveSeconds()
        ->withoutOverlapping(5)
        ->runInBackground()
        ->before(fn () => $markRunStart('metals:poll-prices'))
        ->after(fn () => $markRunEnd('metals:poll-prices'));
}

// Candle Direction / Robot Trade autonomous evaluation. Runs every 10 seconds so
// RUNNING robot sessions open/manage/close positions without manual "Run cycle".
// Disable with config autofinx.scheduler.robot_sessions=false if REST pressure
// becomes a concern.
if ($schedulerEnabled && (bool) config('autofinx.scheduler.robot_sessions', true)) {
    Schedule::command('robot-sessions:evaluate')
        ->everyTenSeconds()
        ->withoutOverlapping(5)
        ->runInBackground()
        ->before(fn () => $markRunStart('robot-sessions:evaluate'))
        ->after(fn () => $markRunEnd('robot-sessions:evaluate'));
}

if ($schedulerEnabled && (bool) config('autofinx.scheduler.quick_settler', true)) {
    Schedule::command('quick-trades:settle')
        ->everyTenSeconds()
        ->withoutOverlapping(5)
        ->runInBackground()
        ->before(fn () => $markRunStart('quick-trades:settle'))
        ->after(fn () => $markRunEnd('quick-trades:settle'));

    // Repairs Robot Trade's exchange-state after any interruption (e.g. the
    // live-feed daemon or worker restarting mid-trade). Previously existed
    // but was never scheduled — mirrors future-trade:reconcile's cadence.
    Schedule::command('quick-trades:reconcile')
        ->everyTenMinutes()
        ->withoutOverlapping(10)
        ->onOneServer()
        ->before(fn () => $markRunStart('quick-trades:reconcile'))
        ->after(fn () => $markRunEnd('quick-trades:reconcile'));
}

// Quick Trade Manual SL/TP reconciler (exchange is authoritative; restart/refresh recovery).
// NOT gated on QUICK_TRADE_MANUAL_ENABLED on purpose: turning the feature off must not stop
// reconciling a position that is still open on the exchange. The command is a cheap no-op when
// the table is missing or no manual position is active. See docs/quick-trade-manual/DESIGN.md.
if ($schedulerEnabled) {
    Schedule::command('quick-trade-manual:reconcile')
        ->everyTenSeconds()
        ->withoutOverlapping(5)
        ->runInBackground()
        ->before(fn () => $markRunStart('quick-trade-manual:reconcile'))
        ->after(fn () => $markRunEnd('quick-trade-manual:reconcile'));
}

if ($schedulerEnabled && $allowRuntimeAutoRepair) {
    Schedule::command('runtime:repair --attempts=1 --sleep=2')
        ->everyMinute()
        ->withoutOverlapping(10)
        ->runInBackground()
        ->before(fn () => $markRunStart('runtime:repair'))
        ->after(fn () => $markRunEnd('runtime:repair'));
}

/*
Temporarily disabled to reduce unnecessary Binance REST pressure while only Future Trade and Fast2X are needed.
Schedule::command('bots:evaluate')
    ->everySecond()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('robot-sessions:evaluate')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quick-trades:settle')
    ->everySecond()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::job(new SyncOrderStatusJob(100))
    ->everyTenSeconds()
    ->withoutOverlapping();
*/

// Read-only research observations from the existing cached futures feed.
if ($schedulerEnabled && config('quick_trade_research.enabled', true)) {
    Schedule::command('quick-trades:sample-research')->everyFiveSeconds()->withoutOverlapping(1)->runInBackground();
}

// Ratio Bot: WS (autofinx-ratio-feed supervisor daemon) is the primary driver
// of evaluations via RunRatioBotEvaluationJob on the dedicated "ratio_bot"
// queue. This is only a low-frequency safety net for any bot whose WS tick
// was missed — intentionally NOT everySecond() like the old disabled block,
// to avoid REST/circuit-breaker pressure shared with the other bots.
if ($schedulerEnabled && (bool) config('autofinx.scheduler.ratio_bots', true)) {
    // Short overlap locks: Laravel's default is 1440 minutes, so a run killed
    // mid-flight (worker restart, deploy) left a mutex that silently skipped
    // these commands for a whole day. evaluate only dispatches jobs (~1s).
    Schedule::command('ratio-bots:evaluate')
        ->everyMinute()
        ->withoutOverlapping(5)
        ->runInBackground()
        ->before(fn () => $markRunStart('ratio-bots:evaluate'))
        ->after(fn () => $markRunEnd('ratio-bots:evaluate'));

    Schedule::command('ratio-bots:reconcile')
        ->everyFiveMinutes()
        ->withoutOverlapping(10)
        ->runInBackground()
        ->before(fn () => $markRunStart('ratio-bots:reconcile'))
        ->after(fn () => $markRunEnd('ratio-bots:reconcile'));

    Schedule::command('ratio-bots:reconcile-costs --limit=20')
        ->everyTenMinutes()
        ->withoutOverlapping(15)
        ->runInBackground()
        ->before(fn () => $markRunStart('ratio-bots:reconcile-costs'))
        ->after(fn () => $markRunEnd('ratio-bots:reconcile-costs'));
}

// Forex Grid (brand-new, fully independent module) -- REST-polled (OANDA
// has no websocket driver here, unlike Ratio Bot's WS-primary path), so this
// is the PRIMARY evaluation driver, not just a safety net.
if ($schedulerEnabled && (bool) config('autofinx.scheduler.forex_grid_bots', true)) {
    Schedule::command('forex-grid-bots:evaluate')
        ->everyMinute()
        ->withoutOverlapping(10)
        ->runInBackground()
        ->before(fn () => $markRunStart('forex-grid-bots:evaluate'))
        ->after(fn () => $markRunEnd('forex-grid-bots:evaluate'));
}

// Multi-Window Forecast Engine Part C -- a once-daily walk-forward backtest,
// independently toggleable from the per-minute ratio_bots jobs above since
// it's a read-only analysis job, not part of the live trading loop.
if ($schedulerEnabled && app(FeatureFlagService::class)->enabled('scheduler.ratio_bot_forecast_backtest', true)) {
    Schedule::command('ratio-bots:forecast-backtest')
        ->dailyAt('03:15')
        ->withoutOverlapping(360)
        ->runInBackground()
        ->before(fn () => $markRunStart('ratio-bots:forecast-backtest'))
        ->after(fn () => $markRunEnd('ratio-bots:forecast-backtest'));
}

// Unified Forecast Engine, Phase 4c -- Quick Trade's own periodic
// calibration refresh, mirroring the ratio_bots_backtest job above
// (read-only analysis job, not part of any live trading loop; shadow-mode
// data only -- see QuickTradeController::forecast()'s shadow comparison).
// Quick Trade only, per the plan's scope -- Robot Trade/Future
// Trade/Fast2X are separate, later tasks.
if ($schedulerEnabled && app(FeatureFlagService::class)->enabled('scheduler.quick_trade_forecast_backtest', true)) {
    Schedule::command('quick-trade:forecast-backtest')
        ->dailyAt('03:45')
        ->withoutOverlapping(360)
        ->runInBackground()
        ->before(fn () => $markRunStart('quick-trade:forecast-backtest'))
        ->after(fn () => $markRunEnd('quick-trade:forecast-backtest'));
}

// Unified Forecast Engine -- Robot Trade's own periodic calibration
// refresh, mirroring quick_trade_forecast_backtest above exactly (read-only
// analysis job; NEVER called from RobotSessionService::evaluateSession() or
// any other live session decision path).
if ($schedulerEnabled && app(FeatureFlagService::class)->enabled('scheduler.robot_trade_forecast_backtest', true)) {
    Schedule::command('robot-trade:forecast-backtest')
        ->dailyAt('04:15')
        ->withoutOverlapping(360)
        ->runInBackground()
        ->before(fn () => $markRunStart('robot-trade:forecast-backtest'))
        ->after(fn () => $markRunEnd('robot-trade:forecast-backtest'));
}

// Unified Forecast Engine -- Future Trade's own periodic calibration
// refresh, mirroring quick_trade_forecast_backtest above exactly (read-only
// analysis job; NEVER called from FutureTradeBotService::scan() or any
// other live scan/entry/exit decision path).
if ($schedulerEnabled && app(FeatureFlagService::class)->enabled('scheduler.future_trade_forecast_backtest', true)) {
    Schedule::command('future-trade:forecast-backtest')
        ->dailyAt('04:45')
        ->withoutOverlapping(360)
        ->runInBackground()
        ->before(fn () => $markRunStart('future-trade:forecast-backtest'))
        ->after(fn () => $markRunEnd('future-trade:forecast-backtest'));
}

// Fast2X's own NATIVE periodic calibration refresh (real
// fast2x_decision_logs trigger -> real fee/slippage-netted fast2x_trades
// outcome via Fast2XCalibrationService -- deliberately NOT
// ForecastHorizonBacktestService::runForBot(), since Fast2X's real
// entry/exit decision never calls TradeForecastService::forecast()). Same
// "periodic job writes, live decision path only reads (Fast2X doesn't even
// read this yet)" split as the other bot types above; NEVER called from
// Fast2XService::processTick()/evaluateEntry()/manageOpenPosition() or any
// other live tick-loop path.
if ($schedulerEnabled && app(FeatureFlagService::class)->enabled('scheduler.fast2x_calibration_backtest', true)) {
    Schedule::command('fast2x:calibration-backtest')
        ->dailyAt('05:15')
        ->withoutOverlapping(360)
        ->runInBackground()
        ->before(fn () => $markRunStart('fast2x:calibration-backtest'))
        ->after(fn () => $markRunEnd('fast2x:calibration-backtest'));
}

// Fast2X restart-on-crash/reboot supervision. Fast2X's daemon is per
// workspace (fast2x:run {workspace_id}), unlike Ratio Bot/Future Trade's
// single global feed daemon, so it cannot be covered by a static Supervisor
// .conf enumerating a fixed process list -- this enumerates the actually
// -Running workspaces from the DB every minute and restarts any whose
// fast2x:run heartbeat has gone stale, mirroring runtime:repair's
// self-healing style. See Fast2XSuperviseCommand's docblock for the full
// design rationale.
if ($schedulerEnabled && app(FeatureFlagService::class)->enabled('scheduler.fast2x_supervisor', true)) {
    Schedule::command('fast2x:supervise')
        ->everyMinute()
        ->withoutOverlapping(10)
        ->runInBackground()
        ->before(fn () => $markRunStart('fast2x:supervise'))
        ->after(fn () => $markRunEnd('fast2x:supervise'));
}

// Unified Forecast Engine -- automated readiness re-evaluation across all
// four bot types (ratio_bot/quick_trade/robot_trade/future_trade), running
// AFTER every backtest command above has refreshed its own bot type's data
// (ratio_bots:03:15, quick_trade:03:45, robot_trade:04:15,
// future_trade:04:45, fast2x:05:15 -- this task's four bot types don't
// depend on fast2x's own calibration, but 05:45 keeps clear of its slot
// too), so it evaluates freshly-computed calibration data, not
// stale/mid-refresh data. Read-only reporting job -- never called from any
// live trading/execution path; only persists forecast_readiness_snapshots
// rows and logs a status change/downgrade.
if ($schedulerEnabled && app(FeatureFlagService::class)->enabled('scheduler.forecast_readiness_evaluation', true)) {
    Schedule::command('forecast:evaluate-readiness')
        ->dailyAt('05:45')
        ->withoutOverlapping(360)
        ->runInBackground()
        ->before(fn () => $markRunStart('forecast:evaluate-readiness'))
        ->after(fn () => $markRunEnd('forecast:evaluate-readiness'));
}

if ($schedulerEnabled && ! $supervisorRuntime && $allowBinanceRest) {
    Schedule::job(new KeepAliveUserStreamsJob)
        ->everyThirtyMinutes()
        ->withoutOverlapping(60)
        ->before(fn () => $markRunStart('KeepAliveUserStreamsJob'))
        ->after(fn () => $markRunEnd('KeepAliveUserStreamsJob'));
}

Schedule::job(new DetectStaleFeedsJob((int) env('LIVE_TRADING_STALE_FEED_THRESHOLD_SEC', 60)))
    ->everyMinute()
    ->withoutOverlapping(5)
    ->before(fn () => $markRunStart('DetectStaleFeedsJob'))
    ->after(fn () => $markRunEnd('DetectStaleFeedsJob'));

Schedule::command('jobs:maintain-backlog')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->before(fn () => $markRunStart('jobs:maintain-backlog'))
    ->after(fn () => $markRunEnd('jobs:maintain-backlog'));

// Future-trade tick: withoutOverlapping uses a cache lock under the hood, but
// we also (a) bound the lock to 60s so a crashed run cannot wedge forever, and
// (b) onOneServer to prevent duplicate execution when scheduling on multiple
// workers. The command itself takes a secondary cache lock keyed on the
// workspace, so even a misconfigured cron cannot spawn two scanner loops on
// the same workspace.
if ($schedulerEnabled && ! $supervisorRuntime && $scheduleFutureTradeRuntime) {
    Schedule::command('future-trade:tick --duration=55 --sleep-ms=1000')
        ->everyMinute()
        ->withoutOverlapping(2)
        ->onOneServer()
        ->runInBackground()
        ->before(fn () => $markRunStart('future-trade:tick'))
        ->after(fn () => $markRunEnd('future-trade:tick'));
}

if ($schedulerEnabled && $scheduleFutureTradeDiagnostics) {
    Schedule::command('future-trade:diagnostics --persist')
        ->everyFiveMinutes()
        ->withoutOverlapping(5)
        ->onOneServer()
        ->before(fn () => $markRunStart('future-trade:diagnostics'))
        ->after(fn () => $markRunEnd('future-trade:diagnostics'));
}

if ($schedulerEnabled && $scheduleFutureTradeAuditMissedSignals) {
    Schedule::command('future-trade:audit-missed-signals --limit=100')
        ->everyFiveMinutes()
        ->withoutOverlapping(5)
        ->onOneServer()
        ->before(fn () => $markRunStart('future-trade:audit-missed-signals'))
        ->after(fn () => $markRunEnd('future-trade:audit-missed-signals'));
}

if ($schedulerEnabled && $scheduleFutureTradeReconcile) {
    Schedule::command('future-trade:reconcile')
        ->everyTenMinutes()
        ->withoutOverlapping(10)
        ->onOneServer()
        ->before(fn () => $markRunStart('future-trade:reconcile'))
        ->after(fn () => $markRunEnd('future-trade:reconcile'));
}

// AI gate housekeeping (futures webhook path). Cheap DB sweeps — always on;
// they no-op instantly when the AI gate has produced no rows.
if ($schedulerEnabled) {
    Schedule::command('future-trade:ai-backfill-outcomes --limit=200')
        ->everyFiveMinutes()
        ->withoutOverlapping(5)
        ->onOneServer()
        ->before(fn () => $markRunStart('future-trade:ai-backfill-outcomes'))
        ->after(fn () => $markRunEnd('future-trade:ai-backfill-outcomes'));

    Schedule::command('future-trade:ai-daily-review')
        ->dailyAt('00:15')
        ->withoutOverlapping(60)
        ->onOneServer()
        ->before(fn () => $markRunStart('future-trade:ai-daily-review'))
        ->after(fn () => $markRunEnd('future-trade:ai-daily-review'));
}

// Operational observability — surfaces runtime staleness (feed daemons,
// schedulers, queues) that would otherwise silently block Robot Trade /
// Future Trade entries for hours with no visible signal to the operator.
// Both are read-only diagnostics; neither touches trading/execution code.
if ($schedulerEnabled) {
    Schedule::command('ops:readiness-check')
        ->everyFiveMinutes()
        ->onOneServer()
        ->withoutOverlapping(5)
        ->before(fn () => $markRunStart('ops:readiness-check'))
        ->after(fn () => $markRunEnd('ops:readiness-check'));

    // Edge-triggered (fires only on green<->red transitions, not every red
    // minute) alert on the futures live-feed health check specifically —
    // this is the exact 3s-freshness gate that blocks entries when the
    // autofinx-trading:live-feed supervisor program is down.
    Schedule::command('ops:runtime-health-alert')
        ->everyMinute()
        ->onOneServer()
        ->withoutOverlapping(1)
        ->before(fn () => $markRunStart('ops:runtime-health-alert'))
        ->after(fn () => $markRunEnd('ops:runtime-health-alert'));
}

// AI decision cycles (spot AI trading middleware). Dormant unless
// AI_TRADE_SCHEDULE_ENABLED=true AND per-workspace AiTradingConfig.enabled.
// Jobs run on the dedicated 'intelligence' queue (worker_2_runtime profile).
if ($schedulerEnabled && (bool) config('intelligence.ai_trading.schedule_enabled', false)) {
    Schedule::job(new DispatchAiDecisionCycleJob)
        ->everyFiveMinutes()
        ->withoutOverlapping(5)
        ->onOneServer()
        ->before(fn () => $markRunStart('ai:dispatch-decision-cycles'))
        ->after(fn () => $markRunEnd('ai:dispatch-decision-cycles'));

    Schedule::job(new ExpireAiDecisionsJob)
        ->everyTenMinutes()
        ->withoutOverlapping(10)
        ->onOneServer()
        ->before(fn () => $markRunStart('ai:expire-decisions'))
        ->after(fn () => $markRunEnd('ai:expire-decisions'));
}

// Bounded futures user-stream consumer — runs in 55s slices and re-spawns each
// minute. withoutOverlapping/onOneServer guarantee at most one consumer per
// cluster; the command itself takes a secondary cache lock so a misconfigured
// crontab cannot spawn two consumers on the same workspace.
if ($schedulerEnabled && ! $supervisorRuntime && $scheduleFutureTradeRuntime) {
    Schedule::command('future-trade:user-stream --duration=55')
        ->everyMinute()
        ->withoutOverlapping(2)
        ->onOneServer()
        ->runInBackground()
        ->before(fn () => $markRunStart('future-trade:user-stream'))
        ->after(fn () => $markRunEnd('future-trade:user-stream'));
}
Schedule::command('prediction:dispatch-settlements --limit=100')->everyMinute()->withoutOverlapping(10);

// Fresh ML snapshots are trained only after a human has sealed an audit-passed
// dataset. This is intentionally opt-in and shadow-only: it cannot alter a
// trading decision, publish a model, or modify execution configuration.
if ($schedulerEnabled && (bool) config('prediction_fresh_data.automatic_training_enabled', false)) {
    Schedule::command('prediction:fresh-model:dispatch-ready')
        ->dailyAt((string) config('prediction_fresh_data.training_schedule_utc', '02:30'))
        ->onOneServer()
        ->withoutOverlapping(30)
        ->runInBackground()
        ->before(fn () => $markRunStart('prediction:fresh-model:dispatch-ready'))
        ->after(fn () => $markRunEnd('prediction:fresh-model:dispatch-ready'));
}

// Quick Trade Python ML — Phase 1 automatic SHADOW learning loop. Off unless
// AUTOFINX_ML_SHADOW_ENABLED=true. Nothing here is read by any order path:
// candles -> features/labels -> label maturation -> shadow predictions ->
// live scoring, plus a daily retrain check that only QUEUES training (the
// trainer runs on the queue worker, so the scheduler is never blocked).
if ($schedulerEnabled && (bool) config('ml.enabled', false)) {
    $mlSchedule = [
        ['ml:candles:sync', 'everyMinute', 4],
        ['ml:shadow:predict', 'everyMinute', 4],
        ['ml:autopilot:run', 'everyMinute', 4],
        ['ml:labels:mature', 'everyFiveMinutes', 10],
        ['ml:shadow:evaluate', 'everyFiveMinutes', 10],
        ['ml:trade-feedback:classify', 'hourly', 30],
        ['ml:funding:sync', 'hourly', 30],
        // Training control plane: ends runs without a heartbeat, re-queues transient failures. Short expiry so a crashed reaper never blocks the next pass for long.
        ['ml:training:reap', 'everyTwoMinutes', 3],
    ];
    foreach ($mlSchedule as [$mlCommand, $mlFrequency, $mlOverlapMinutes]) {
        Schedule::command($mlCommand)
            ->{$mlFrequency}()
            ->onOneServer()
            ->withoutOverlapping($mlOverlapMinutes)
            ->runInBackground()
            ->before(fn () => $markRunStart($mlCommand))
            ->after(fn () => $markRunEnd($mlCommand));
    }
    Schedule::command('ml:retrain:check')
        ->timezone('UTC')
        ->dailyAt((string) config('ml.training.schedule_utc', '03:05'))
        ->onOneServer()
        ->withoutOverlapping(60)
        ->runInBackground()
        ->before(fn () => $markRunStart('ml:retrain:check'))
        ->after(fn () => $markRunEnd('ml:retrain:check'));
}
