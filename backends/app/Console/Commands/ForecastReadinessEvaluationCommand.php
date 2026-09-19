<?php

namespace App\Console\Commands;

use App\Domain\RatioBot\ForecastProductionReadinessReport;
use App\Domain\RatioBot\RatioBotBrainService;
use App\Domain\Trading\TimeframeRegistry;
use App\Models\ForecastReadinessSnapshot;
use App\Models\FutureTradeLogicalTrade;
use App\Models\QuickTrade;
use App\Models\RatioBotConfig;
use App\Models\RobotSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Periodic re-evaluation of `ForecastProductionReadinessReport` for every
 * REAL active scope across all four bot types, so a status flip (most
 * notably VALIDATED -> NOT_TRADEABLE/NOT_VALIDATED) is noticed without a
 * human manually polling QuickTradeResearchController::readiness().
 *
 * Read-only reporting job -- it only CALLS
 * ForecastProductionReadinessReport::generate() (which itself only reads
 * RatioBotForecastBacktest rows via the calibration service and promotion
 * gate) and WRITES its own new `forecast_readiness_snapshots` table. It
 * never touches any live trading/execution path, and never mutates any of
 * the rows it reads.
 *
 * "Active" enumeration per bot type deliberately REUSES the exact same
 * queries this session's own sibling backtest commands already use, rather
 * than inventing a second, possibly-disagreeing definition of "active":
 *  - ratio_bot:     RatioBotConfig::where('brain_enabled', true)->where('enabled', true)
 *                    -- see RatioBotForecastBacktestCommand::handle().
 *  - quick_trade:   distinct (symbol, timeframe, expiry_seconds) combinations
 *                    from QuickTrade rows created in the last 7 days -- see
 *                    QuickTradeForecastBacktestCommand::handle().
 *  - robot_trade:   RobotSession::whereIn('status', ['RUNNING', 'PAUSED'])
 *                    -- see RobotTradeForecastBacktestCommand::handle().
 *  - future_trade:  distinct (symbol, timeframe) combinations from
 *                    FutureTradeLogicalTrade rows created in the last 7 days
 *                    -- see FutureTradeForecastBacktestCommand::handle().
 *
 * No real, settled-trade holdout P&L summary is built here (unlike
 * QuickTradeResearchController::readiness(), which can build one from a
 * caller-supplied symbol/timeframe/mode scoped to one workspace's own
 * trades). This command evaluates ALL workspaces' scopes at once and has no
 * single workspace to scope a P&L query to, and there is still no existing
 * cross-workspace linkage in this codebase between a backtest scope and a
 * specific set of real trades (see ForecastModelPromotionGate's own
 * docblock on this exact point) -- inventing one here would be exactly the
 * fabricated-linkage shortcut this codebase's rules forbid. Passing null is
 * the same, already-documented "no real evidence yet" contract every other
 * caller of this report already relies on (P&L-derived fields report null,
 * a known_limitations entry is added, and the promotion gate correctly
 * reports NOT_VALIDATED rather than a false VALIDATED).
 */
class ForecastReadinessEvaluationCommand extends Command
{
    /**
     * Snapshots older than this are pruned at the start of each run so this
     * table keeps a real, bounded history rather than growing forever --
     * mirrors the retention-window convention already used elsewhere in
     * this codebase (see App\Domain\Ops\*TrendService's
     * `ops.maintenance.retention_days` config, same 180-day default).
     */
    public const RETENTION_DAYS = 180;

    protected $signature = 'forecast:evaluate-readiness
        {--limit=50 : Maximum scopes to evaluate per bot type}';

    protected $description = 'Re-evaluates ForecastProductionReadinessReport for every active bot instance/scope across all four bot types and persists a timestamped snapshot, logging any status downgrade (read-only, never affects a live trading decision).';

    public function handle(ForecastProductionReadinessReport $report, RatioBotBrainService $brain, TimeframeRegistry $timeframes): int
    {
        $this->pruneOldSnapshots();

        $limit = max(1, min(500, (int) $this->option('limit')));

        $scopes = [];
        foreach ($this->ratioBotScopes($brain, $limit) as $scope) {
            $scopes[] = $scope;
        }
        foreach ($this->quickTradeScopes($limit) as $scope) {
            $scopes[] = $scope;
        }
        foreach ($this->robotTradeScopes($limit) as $scope) {
            $scopes[] = $scope;
        }
        foreach ($this->futureTradeScopes($timeframes, $limit) as $scope) {
            $scopes[] = $scope;
        }

        $processed = 0;
        foreach ($scopes as $scope) {
            try {
                $this->evaluateScope($report, $scope['bot_type'], $scope['bot_instance_id'], $scope['horizon_seconds']);
                $processed++;
            } catch (Throwable $e) {
                Log::warning('[ForecastReadinessEvaluation] Failed for scope', [
                    'bot_type' => $scope['bot_type'],
                    'bot_instance_id' => $scope['bot_instance_id'],
                    'horizon_seconds' => $scope['horizon_seconds'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Forecast readiness re-evaluated for {$processed}/".count($scopes).' scopes.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{bot_type:string, bot_instance_id:string, horizon_seconds:int}>
     */
    private function ratioBotScopes(RatioBotBrainService $brain, int $limit): array
    {
        $configs = RatioBotConfig::query()
            ->where('brain_enabled', true)
            ->where('enabled', true)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $scopes = [];
        foreach ($configs as $config) {
            $scopes[] = [
                'bot_type' => 'ratio_bot',
                'bot_instance_id' => (string) $config->id,
                'horizon_seconds' => (int) $brain->horizonSeconds($config),
            ];
        }

        return $scopes;
    }

    /**
     * @return array<int, array{bot_type:string, bot_instance_id:string, horizon_seconds:int}>
     */
    private function quickTradeScopes(int $limit): array
    {
        $exchange = (string) config('unified_forecast.quick_trade_default_exchange', 'binance');

        $combinations = QuickTrade::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('symbol, timeframe, expiry_seconds, COUNT(*) as trade_count')
            ->groupBy('symbol', 'timeframe', 'expiry_seconds')
            ->orderByDesc('trade_count')
            ->limit($limit)
            ->get();

        $scopes = [];
        foreach ($combinations as $combination) {
            $scopes[] = [
                'bot_type' => 'quick_trade',
                'bot_instance_id' => sprintf('%s:%s:%s', $exchange, (string) $combination->symbol, (string) $combination->timeframe),
                'horizon_seconds' => (int) $combination->expiry_seconds,
            ];
        }

        return $scopes;
    }

    /**
     * @return array<int, array{bot_type:string, bot_instance_id:string, horizon_seconds:int}>
     */
    private function robotTradeScopes(int $limit): array
    {
        $sessions = RobotSession::query()
            ->whereIn('status', ['RUNNING', 'PAUSED'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $scopes = [];
        foreach ($sessions as $session) {
            $horizonSeconds = (int) $session->expiry_seconds;
            if ($horizonSeconds <= 0) {
                continue;
            }
            $scopes[] = [
                'bot_type' => 'robot_trade',
                'bot_instance_id' => (string) $session->id,
                'horizon_seconds' => $horizonSeconds,
            ];
        }

        return $scopes;
    }

    /**
     * @return array<int, array{bot_type:string, bot_instance_id:string, horizon_seconds:int}>
     */
    private function futureTradeScopes(TimeframeRegistry $timeframes, int $limit): array
    {
        $exchange = 'binance_futures';

        $combinations = FutureTradeLogicalTrade::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('timeframe')
            ->selectRaw('symbol, timeframe, COUNT(*) as trade_count')
            ->groupBy('symbol', 'timeframe')
            ->orderByDesc('trade_count')
            ->limit($limit)
            ->get();

        $scopes = [];
        foreach ($combinations as $combination) {
            $symbol = strtoupper((string) $combination->symbol);
            $timeframe = (string) $combination->timeframe;
            $scopes[] = [
                'bot_type' => 'future_trade',
                'bot_instance_id' => sprintf('%s:%s:%s', $exchange, $symbol, $timeframe),
                'horizon_seconds' => max(1, $timeframes->seconds($timeframe)),
            ];
        }

        return $scopes;
    }

    private function evaluateScope(
        ForecastProductionReadinessReport $report,
        string $botType,
        string $botInstanceId,
        int $horizonSeconds,
    ): void {
        $result = $report->generate($botType, $botInstanceId, $horizonSeconds, null);
        $status = (string) $result['promotion_gate']['status'];
        $reasons = $result['promotion_gate']['reasons'] ?? [];
        $computedAt = now();

        $previous = ForecastReadinessSnapshot::query()
            ->where('bot_type', $botType)
            ->where('bot_instance_id', $botInstanceId)
            ->where('horizon_seconds', $horizonSeconds)
            ->orderByDesc('computed_at')
            ->first();

        ForecastReadinessSnapshot::create([
            'bot_type' => $botType,
            'bot_instance_id' => $botInstanceId,
            'horizon_seconds' => $horizonSeconds,
            'status' => $status,
            'reasons' => $reasons,
            'report_json' => $result,
            'computed_at' => $computedAt,
        ]);

        if ($previous !== null && $previous->status !== $status) {
            $context = [
                'bot_type' => $botType,
                'bot_instance_id' => $botInstanceId,
                'horizon_seconds' => $horizonSeconds,
                'previous_status' => $previous->status,
                'new_status' => $status,
                'reasons' => $reasons,
                'previous_computed_at' => optional($previous->computed_at)->toIso8601String(),
            ];

            if ($this->isDowngrade($previous->status, $status)) {
                Log::error('[ForecastReadinessEvaluation] Readiness status downgraded', $context);
            } else {
                Log::warning('[ForecastReadinessEvaluation] Readiness status changed', $context);
            }
        }
    }

    /**
     * A downgrade is any move AWAY from VALIDATED, or INTO NOT_TRADEABLE --
     * the "this was trustworthy and now isn't" direction this task's
     * requirements specifically call out as the concerning one. Anything
     * moving the other way (e.g. INSUFFICIENT_DATA -> NOT_VALIDATED ->
     * VALIDATED) is a status CHANGE worth logging, but not a downgrade.
     */
    private function isDowngrade(string $previousStatus, string $newStatus): bool
    {
        if ($newStatus === $previousStatus) {
            return false;
        }

        $order = [
            'INSUFFICIENT_DATA' => 0,
            'NOT_VALIDATED' => 1,
            'VALIDATED' => 2,
            'NOT_TRADEABLE' => 1,
        ];

        $previousRank = $order[$previousStatus] ?? 0;
        $newRank = $order[$newStatus] ?? 0;

        if ($previousStatus === 'VALIDATED' && $newStatus !== 'VALIDATED') {
            return true;
        }

        return $newRank < $previousRank;
    }

    private function pruneOldSnapshots(): void
    {
        ForecastReadinessSnapshot::query()
            ->where('computed_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();
    }
}
