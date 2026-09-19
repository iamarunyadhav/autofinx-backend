<?php

namespace App\Domain\Trading;

use App\Models\QuickTrade;
use Illuminate\Support\Collection;

/**
 * Builds the real, settled-trade holdout P&L evidence
 * `ForecastModelPromotionGate::evaluate()` needs to ever return VALIDATED,
 * for the two LIVE-mode scopes this task wires (Unified Forecast Engine
 * plan): Quick Trade (scoped by symbol+timeframe+horizon, matching the
 * `{exchange}:{symbol}:{timeframe}` bot_instance_id convention
 * `QuickTradeForecastBacktestCommand`/the Quick Trade shadow wiring already
 * use) and Robot Trade (scoped by the specific `RobotSession::id`, matching
 * `RobotTradeForecastBacktestCommand`'s own documented real convention --
 * NOT the shadow wiring's mismatched `{exchange}:{symbol}:{timeframe}`, see
 * that command's class docblock for why a per-session id is the correct
 * scope for Robot Trade specifically).
 *
 * Mirrors `QuickTradeResearchController::realHoldoutPnlSummary()` exactly
 * (same source data and computation: settled QuickTrade rows ->
 * QuickTradeResearchService::report() -> filter execution_environment
 * === 'live' -> ForecastMetricsService::summarize()) but WITHOUT that
 * method's workspace_id filter, because both real scope keys used here
 * (bot_instance_id) are themselves cross-workspace identities already
 * established elsewhere in this codebase (see
 * ForecastReadinessEvaluationCommand::quickTradeScopes(), which enumerates
 * Quick Trade scopes the exact same cross-workspace way). Returns null
 * (never a fabricated summary) whenever no matching real trades exist --
 * exactly the same "no real evidence yet" contract every other caller of
 * ForecastModelPromotionGate/ForecastMetricsService already relies on.
 */
class ForecastLiveEvidenceService
{
    public function __construct(
        private readonly QuickTradeResearchService $research,
        private readonly ForecastMetricsService $metrics,
    ) {
    }

    public function quickTradeHoldoutPnlSummary(string $symbol, string $timeframe, int $horizonSeconds): ?array
    {
        $trades = QuickTrade::query()
            ->where('status', '!=', 'open')
            ->whereNotNull('settled_at')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', $timeframe)
            ->where('expiry_seconds', $horizonSeconds)
            ->orderByDesc('settled_at')
            ->limit(1000)
            ->get();

        return $this->summarizeLiveRows($trades);
    }

    public function robotTradeHoldoutPnlSummary(string $robotSessionId, int $horizonSeconds): ?array
    {
        $trades = QuickTrade::query()
            ->where('robot_session_id', $robotSessionId)
            ->where('status', '!=', 'open')
            ->whereNotNull('settled_at')
            ->where('expiry_seconds', $horizonSeconds)
            ->orderByDesc('settled_at')
            ->limit(1000)
            ->get();

        return $this->summarizeLiveRows($trades);
    }

    /**
     * @param  Collection<int, QuickTrade>  $trades
     */
    private function summarizeLiveRows(Collection $trades): ?array
    {
        if ($trades->isEmpty()) {
            return null;
        }

        $rows = $trades->map(fn (QuickTrade $trade) => $this->research->report($trade))
            ->filter(fn (array $row) => ($row['execution_environment'] ?? null) === 'live')
            ->values();

        if ($rows->isEmpty()) {
            return null;
        }

        return $this->metrics->summarize($rows);
    }
}
