<?php

namespace App\Domain\Ml\Shadow;

use App\Domain\Market\MarketPriceCacheService;
use App\Models\MlCandle;
use App\Models\MlModel;
use App\Models\MlPaperAutopilotTrade;
use App\Models\MlShadowPrediction;

/**
 * ML Paper Autopilot: follows the shadow champions' BUY/SELL decisions with
 * SIMULATED trades so their behaviour can be watched in the UI.
 *
 * Isolation (by construction): it writes only ml_paper_autopilot_trades,
 * keeps its own virtual balance (starting balance + Σ closed P&L), reads
 * prices read-only (websocket cache, else Binance's public no-key ticker),
 * and never calls Quick Trade, a wallet, an account/order endpoint or any
 * order path. Manual trades are never touched.
 *
 * Trading rules: at most ONE open position per symbol × horizon (signals
 * while open are skipped and counted); entry only on a CONFIRMED signal (the
 * stable state is BUY/SELL and the newest fresh decision agrees, see
 * SignalStabilityService) and only for autopilot.trade_horizons_minutes;
 * entry at a fresh market price, exit at T+h; every round trip pays the
 * configured fee + slippage (default 0.18%) plus actual funding.
 */
class MlPaperAutopilotService
{
    public function __construct(
        private readonly MarketPriceCacheService $prices,
        private readonly FundingRateService $funding,
        private readonly MlModelRegistry $registry,
        private readonly FeatureLabelService $features,
        private readonly BinanceFuturesKlineClient $binance,
        private readonly SignalStabilityService $stability,
        private readonly DynamicV5ResearchUniverse $universe,
    ) {}

    /** @return list<int> */
    public static function tradeHorizons(): array
    {
        return array_values(array_map('intval', (array) config('ml.autopilot.trade_horizons_minutes', [60, 120, 240, 1440])));
    }

    /** @return array{opened:int, settled:int, cancelled:int} */
    public function run(): array
    {
        $settled = $this->settleExpired();

        return ['opened' => $this->openNewTrades()] + $settled;
    }

    public function openNewTrades(): int
    {
        $opened = 0;
        $nowMs = $this->nowMs();
        $maxAgeMs = (int) config('ml.autopilot.max_signal_age_seconds', 600) * 1000;
        foreach ($this->universe->current()['symbols'] as $symbol) {
            foreach (array_intersect($this->features->horizons(), self::tradeHorizons()) as $horizon) {
                if (! $this->registry->champion($symbol, $horizon)) {
                    continue;
                }
                $stable = $this->stability->state($symbol, $horizon);
                $signal = $stable['latest'];
                // Confirmed only: stable side AND the newest fresh decision agrees.
                if (! $signal || ! in_array($stable['state'], ['BUY', 'SELL'], true) || $signal->decision !== $stable['state']
                    || $nowMs - (int) $signal->anchor_time_ms > $maxAgeMs) {
                    continue;
                }
                if (MlPaperAutopilotTrade::query()->where('prediction_id', $signal->id)->exists()
                    || MlPaperAutopilotTrade::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->where('status', 'OPEN')->exists()) {
                    continue; // already taken, or one position at a time
                }

                [$entryPrice, $source] = $this->quote($symbol) ?? [(float) $signal->current_price, 'signal_candle_close'];
                MlPaperAutopilotTrade::query()->create([
                    'prediction_id' => $signal->id, 'symbol' => $symbol, 'horizon_minutes' => $horizon,
                    'model_id' => $signal->model_id, 'model_version' => $signal->model_version, 'model_type' => $signal->model_type,
                    'side' => $signal->decision, 'p_buy' => $signal->p_buy, 'p_sell' => $signal->p_sell,
                    'expected_net_pct' => $signal->decision === 'BUY' ? $signal->buy_net_pct : $signal->sell_net_pct,
                    'signal_time_ms' => (int) $signal->anchor_time_ms, 'signal_price' => (float) $signal->current_price,
                    'entry_time_ms' => $nowMs, 'entry_price' => $entryPrice, 'entry_price_source' => $source,
                    'expires_at_ms' => $nowMs + $horizon * 60_000,
                    'stake_usd' => (float) config('ml.autopilot.stake_usd', 100),
                    'cost_pct' => (float) config('ml.autopilot.cost_pct', 0.18),
                    'status' => 'OPEN',
                ]);
                $opened++;
            }
        }

        return $opened;
    }

    /** @return array{settled:int, cancelled:int} */
    public function settleExpired(): array
    {
        $nowMs = $this->nowMs();
        $result = ['settled' => 0, 'cancelled' => 0];
        $interval = MlCandleCollector::intervalMs((string) config('ml.base_timeframe', '5m'));
        foreach (MlPaperAutopilotTrade::query()->where('status', 'OPEN')->where('expires_at_ms', '<=', $nowMs)->orderBy('id')->get() as $trade) {
            $exit = null;
            // Live quote only if we are settling promptly after expiry.
            if ($nowMs - $trade->expires_at_ms <= 120_000) {
                $exit = $this->quote($trade->symbol);
            }
            if ($exit === null) {
                // Otherwise the close of the 5m candle that contains the expiry instant.
                $openMs = intdiv((int) $trade->expires_at_ms, $interval) * $interval;
                $close = MlCandle::query()->where('provider', (string) config('ml.provider', 'binance_futures'))
                    ->where('symbol', $trade->symbol)->where('timeframe', (string) config('ml.base_timeframe', '5m'))
                    ->where('open_time_ms', $openMs)->value('close');
                $exit = $close !== null ? [(float) $close, 'expiry_candle_close'] : null;
            }
            if ($exit === null) {
                if ($nowMs - $trade->expires_at_ms > 86_400_000) {
                    $trade->forceFill(['status' => 'CANCELLED', 'close_reason' => 'no_exit_price_available', 'exit_time_ms' => $nowMs])->save();
                    $result['cancelled']++;
                }

                continue;
            }
            $this->close($trade, $exit[0], $exit[1], $nowMs);
            $result['settled']++;
        }

        return $result;
    }

    private function close(MlPaperAutopilotTrade $trade, float $exitPrice, string $source, int $nowMs): void
    {
        $sideSign = $trade->side === 'BUY' ? 1 : -1;
        $gross = $sideSign * ($exitPrice / (float) $trade->entry_price - 1) * 100;
        $fundingBuy = $this->funding->buyCostPct($trade->symbol, [(int) $trade->entry_time_ms], (int) $trade->expires_at_ms - (int) $trade->entry_time_ms)[(int) $trade->entry_time_ms] ?? 0.0;
        $net = $gross - (float) $trade->cost_pct - $sideSign * $fundingBuy;
        $trade->forceFill([
            'status' => 'CLOSED', 'exit_time_ms' => $nowMs, 'exit_price' => $exitPrice, 'exit_price_source' => $source,
            'funding_buy_pct' => $fundingBuy, 'gross_return_pct' => round($gross, 8), 'net_return_pct' => round($net, 8),
            'pnl_usd' => round((float) $trade->stake_usd * $net / 100, 6),
            'outcome' => $net > 0 ? 'WIN' : 'LOSS', 'close_reason' => 'expired',
        ])->save();
    }

    /**
     * Fresh market price: the websocket cache first (only symbols someone
     * trades live are streamed), else Binance's public ticker. Null when
     * neither is fresh; callers then fall back to a candle close.
     *
     * @return array{0:float, 1:string}|null
     */
    private function quote(string $symbol): ?array
    {
        $maxAgeSeconds = (int) config('ml.autopilot.quote_max_age_seconds', 15);
        $snapshot = $this->prices->getLiveFeedSnapshot((string) config('ml.provider', 'binance_futures'), $symbol, $maxAgeSeconds);
        if ($snapshot && ($snapshot['fresh'] ?? false) && (float) ($snapshot['price'] ?? 0) > 0) {
            return [(float) $snapshot['price'], 'live_quote'];
        }
        try {
            $ticker = $this->binance->tickerPrice($symbol);
        } catch (\Throwable) {
            return null; // breaker open / network: the simulation must never fail on a price read
        }
        if ($ticker === null || abs($this->nowMs() - $ticker['time_ms']) > $maxAgeSeconds * 1000) {
            return null;
        }

        return [$ticker['price'], 'rest_ticker'];
    }

    /**
     * Everything the Paper Autopilot panel shows.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $start = (float) config('ml.autopilot.starting_balance_usd', 10000);
        $minClosed = (int) config('ml.autopilot.min_closed_trades', 20);
        $closed = MlPaperAutopilotTrade::query()->where('status', 'CLOSED')->orderBy('exit_time_ms')->get();
        $open = MlPaperAutopilotTrade::query()->where('status', 'OPEN')->orderByDesc('entry_time_ms')->get();

        $stats = $this->stats($closed);
        $equity = [['t' => null, 'balance' => $start]];
        $balance = $start;
        $peak = $start;
        $maxDrawdown = 0.0;
        foreach ($closed as $trade) {
            $balance += (float) $trade->pnl_usd;
            $peak = max($peak, $balance);
            $maxDrawdown = max($maxDrawdown, $peak - $balance);
            $equity[] = ['t' => gmdate('c', intdiv((int) $trade->exit_time_ms, 1000)), 'balance' => round($balance, 2)];
        }
        $equity[0]['t'] = $closed->isNotEmpty() ? gmdate('c', intdiv((int) $closed->first()->entry_time_ms, 1000)) : null;

        $byScope = $closed->groupBy(fn ($t) => $t->symbol.'|'.$t->horizon_minutes)->map(function ($group, $key) {
            [$symbol, $horizon] = explode('|', (string) $key);

            return ['symbol' => $symbol, 'horizon_minutes' => (int) $horizon] + $this->stats($group);
        })->values()->all();

        // Execution gap: same predictions scored by the shadow evaluator (candle close, model cost).
        $shadowNets = MlShadowPrediction::query()->whereIn('id', $closed->pluck('prediction_id'))->whereNotNull('decision_net_pct')->pluck('decision_net_pct');

        return [
            'enabled' => (bool) config('ml.enabled', false) && (bool) config('ml.autopilot.enabled', true),
            'mode' => 'paper_autopilot', 'separate_from_user_trades' => true, 'execution' => false, 'influence' => false,
            'settings' => [
                'starting_balance_usd' => $start, 'stake_usd' => (float) config('ml.autopilot.stake_usd', 100),
                'cost_pct' => (float) config('ml.autopilot.cost_pct', 0.18), 'min_closed_trades' => $minClosed,
                'rule' => 'confirmed_signal_one_position_per_symbol_horizon_exit_at_expiry',
                'trade_horizons_minutes' => self::tradeHorizons(),
                'stability' => [
                    'confirm_predictions' => (int) config('ml.stability.confirm_predictions', 2),
                    'entry_probability' => (float) config('ml.classifier.probability_floor', 0.55),
                    'exit_probability' => (float) config('ml.stability.exit_probability', 0.50),
                ],
            ],
            'summary' => $stats + [
                'status' => $closed->count() >= $minClosed ? 'ok' : 'insufficient_data',
                'balance_usd' => round($balance, 2),
                'total_return_pct' => round(($balance - $start) / $start * 100, 4),
                'open_trades' => $open->count(),
                'max_drawdown_usd' => round($maxDrawdown, 2),
                'cancelled_trades' => MlPaperAutopilotTrade::query()->where('status', 'CANCELLED')->count(),
            ],
            'by_scope' => $byScope,
            'by_side' => [
                'BUY' => $this->stats($closed->where('side', 'BUY')),
                'SELL' => $this->stats($closed->where('side', 'SELL')),
            ],
            'execution_gap' => [
                'autopilot_avg_net_pct' => $stats['avg_net_pct'],
                'shadow_avg_net_pct_same_signals' => $shadowNets->isEmpty() ? null : round((float) $shadowNets->avg(), 6),
                'avg_entry_slippage_pct' => $closed->isEmpty() ? null : round((float) $closed->avg(fn ($t) => ($t->side === 'BUY' ? 1 : -1) * ((float) $t->entry_price / (float) $t->signal_price - 1) * 100), 6),
                'live_quote_entries' => $closed->whereIn('entry_price_source', ['live_quote', 'rest_ticker'])->count(),
                'live_quote_exits' => $closed->whereIn('exit_price_source', ['live_quote', 'rest_ticker'])->count(),
            ],
            'equity_curve' => $equity,
            'universe' => $this->universe->current(),
            'signals' => $this->signals(),
            'open_trades' => $open->map(fn ($t) => $this->row($t))->values()->all(),
            'recent_trades' => $closed->sortByDesc('exit_time_ms')->take(50)->map(fn ($t) => $this->row($t))->values()->all(),
        ];
    }

    /** @param iterable<MlPaperAutopilotTrade> $trades */
    private function stats($trades): array
    {
        $trades = collect($trades);
        $n = $trades->count();
        $wins = $trades->where('outcome', 'WIN')->count();
        $nets = $trades->pluck('net_return_pct')->map(fn ($v) => (float) $v);
        $gains = (float) $trades->where('pnl_usd', '>', 0)->sum('pnl_usd');
        $losses = abs((float) $trades->where('pnl_usd', '<', 0)->sum('pnl_usd'));

        return [
            'closed_trades' => $n,
            'wins' => $wins,
            'losses' => $n - $wins,
            'win_rate_pct' => $n ? round($wins / $n * 100, 2) : null,
            // Direction right before costs (gross > 0) — "accuracy" of the call itself.
            'direction_accuracy_pct' => $n ? round($trades->filter(fn ($t) => (float) $t->gross_return_pct > 0)->count() / $n * 100, 2) : null,
            'total_pnl_usd' => round((float) $trades->sum('pnl_usd'), 2),
            'avg_net_pct' => $n ? round((float) $nets->avg(), 6) : null,
            'best_net_pct' => $n ? round((float) $nets->max(), 6) : null,
            'worst_net_pct' => $n ? round((float) $nets->min(), 6) : null,
            'profit_factor' => $losses > 0 ? round($gains / $losses, 3) : null,
            'avg_hold_minutes' => $n ? round((float) $trades->avg(fn ($t) => ((int) $t->exit_time_ms - (int) $t->entry_time_ms) / 60_000), 1) : null,
        ];
    }

    /** Stable + latest champion decision per symbol × horizon, and what the autopilot did with it. */
    private function signals(): array
    {
        $out = [];
        $nowMs = $this->nowMs();
        $maxAgeMs = (int) config('ml.autopilot.max_signal_age_seconds', 600) * 1000;
        $tradeHorizons = self::tradeHorizons();
        foreach ($this->universe->current()['symbols'] as $symbol) {
            foreach ($this->features->horizons() as $horizon) {
                $champion = $this->registry->champion($symbol, $horizon);
                $stable = $this->stability->state($symbol, $horizon);
                $signal = $stable['latest'];
                $taken = $signal ? MlPaperAutopilotTrade::query()->where('prediction_id', $signal->id)->exists() : false;
                $openPosition = MlPaperAutopilotTrade::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->where('status', 'OPEN')->exists();
                $rawSide = $signal && in_array($signal->decision, ['BUY', 'SELL'], true);
                $action = match (true) {
                    ! $champion => 'no_champion',
                    ! $signal => 'no_signal_yet',
                    $taken => 'traded',
                    $nowMs - (int) $signal->anchor_time_ms > $maxAgeMs => 'signal_stale',
                    $stable['state'] === 'WAIT' => $rawSide ? 'confirming' : 'wait',
                    ! in_array($horizon, $tradeHorizons, true) => 'display_only',
                    $signal->decision !== $stable['state'] => 'holding_no_fresh_entry',
                    $openPosition => 'skipped_position_open',
                    default => 'pending_entry',
                };
                $out[] = [
                    'symbol' => $symbol, 'horizon_minutes' => $horizon, 'model_version' => $champion?->model_version, 'model_type' => $champion?->model_type,
                    'tradable' => in_array($horizon, $tradeHorizons, true),
                    'decision' => $signal?->decision, 'decision_reason' => $signal?->decision_reason, 'p_buy' => $signal?->p_buy, 'p_sell' => $signal?->p_sell,
                    'buy_net_pct' => $signal?->buy_net_pct, 'sell_net_pct' => $signal?->sell_net_pct, 'price' => $signal?->current_price,
                    'signal_time' => $signal ? gmdate('c', intdiv((int) $signal->anchor_time_ms, 1000)) : null,
                    'signal_age_seconds' => $signal ? max(0, intdiv($nowMs - (int) $signal->anchor_time_ms, 1000)) : null,
                    'stable' => [
                        'state' => $champion ? $stable['state'] : null, 'since' => $stable['since'], 'held_minutes' => $stable['held_minutes'],
                        'confirming' => $stable['confirming'], 'raw_flips' => $stable['raw_flips'], 'window_predictions' => $stable['window_predictions'],
                    ],
                    'action' => $action,
                    // "T" badge: the newest finished training attempt (champion or not).
                    'last_training' => ($trained = MlModel::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
                        ->whereIn('status', ['CHAMPION', 'REJECTED', 'RETIRED', 'FAILED'])->orderByDesc('created_at')->first())
                        ? ['status' => $trained->status, 'at' => ($trained->trained_at ?? $trained->created_at)?->toIso8601String(), 'reason' => $trained->decision_reason]
                        : null,
                    // BUY/SELL signals the confirmation + one-position rules skipped (raw vs taken).
                    'raw_trade_signals' => MlShadowPrediction::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->whereIn('decision', ['BUY', 'SELL'])->count(),
                    'trades_taken' => MlPaperAutopilotTrade::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->count(),
                ];
            }
        }

        return $out;
    }

    private function row(MlPaperAutopilotTrade $t): array
    {
        return [
            'id' => $t->id, 'symbol' => $t->symbol, 'horizon_minutes' => $t->horizon_minutes, 'side' => $t->side, 'status' => $t->status,
            'model_version' => $t->model_version, 'p_buy' => $t->p_buy, 'p_sell' => $t->p_sell, 'expected_net_pct' => $t->expected_net_pct,
            'signal_time' => gmdate('c', intdiv((int) $t->signal_time_ms, 1000)), 'signal_price' => $t->signal_price,
            'entry_time' => gmdate('c', intdiv((int) $t->entry_time_ms, 1000)), 'entry_price' => $t->entry_price, 'entry_price_source' => $t->entry_price_source,
            'expires_at' => gmdate('c', intdiv((int) $t->expires_at_ms, 1000)),
            'exit_time' => $t->exit_time_ms ? gmdate('c', intdiv((int) $t->exit_time_ms, 1000)) : null,
            'exit_price' => $t->exit_price, 'exit_price_source' => $t->exit_price_source,
            'stake_usd' => $t->stake_usd, 'cost_pct' => $t->cost_pct, 'funding_buy_pct' => $t->funding_buy_pct,
            'gross_return_pct' => $t->gross_return_pct, 'net_return_pct' => $t->net_return_pct, 'pnl_usd' => $t->pnl_usd,
            'outcome' => $t->outcome,
        ];
    }

    protected function nowMs(): int
    {
        return now('UTC')->getTimestampMs();
    }
}
