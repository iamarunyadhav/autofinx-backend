<?php

namespace App\Domain\FutureTrade;

use App\Domain\Market\Indicators\Indicators;
use App\Domain\Trading\KlineBrainService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotState;
use App\Models\FutureTradeLogicalTrade;

/**
 * Builds the market/account context sent to the AI gate.
 *
 * SECURITY: this builder WHITELISTS fields. The Pine webhook is token-trusted
 * but defense-in-depth applies — free-text fields that an attacker (or a
 * misconfigured alert) could influence (`reason`, `strategy_order_id`, the raw
 * `pinePayload`) must NEVER reach the prompt. Only numeric values, enums, and
 * ISO timestamps are copied.
 */
class FutureTradeAiContextBuilder
{
    public function __construct(
        private readonly KlineBrainService $klineBrain,
        private readonly FutureTradePnLService $pnl,
    ) {
    }

    public function build(FutureTradeBotConfig $config, array $signal, array $settings, FutureTradeBotState $state): array
    {
        $symbol = strtoupper((string) ($signal['symbol'] ?? ''));
        $timeframe = strtolower((string) ($signal['timeframe'] ?? $settings['timeframe'] ?? '15m'));

        return [
            'signal' => [
                'symbol' => $symbol,
                'side' => ($signal['signal'] ?? '') === 'BUY' ? 'LONG' : 'SHORT',
                'price' => (float) ($signal['price'] ?? 0),
                'confidence' => (float) ($signal['confidence'] ?? 0),
                'long_score' => (float) data_get($signal, 'debug.longScore', 0),
                'short_score' => (float) data_get($signal, 'debug.shortScore', 0),
                'timeframe' => $timeframe,
                'candle_open' => (string) ($signal['candle_open'] ?? ''),
                'candle_close' => (string) ($signal['candle_close'] ?? ''),
                'signal_latency_ms' => data_get($signal, 'debug.webhookTiming.signalLatencyMs'),
                'change_percent' => (float) ($signal['changePercent'] ?? 0),
                'range_percent' => (float) ($signal['rangePercent'] ?? 0),
                'range_position' => (float) ($signal['rangePosition'] ?? 0.5),
                'now_utc' => now()->toIso8601String(),
            ],
            'market' => [
                'tf_' . $timeframe => $this->timeframeSnapshot($symbol, $timeframe),
                'tf_1h' => $timeframe === '1h' ? null : $this->timeframeSnapshot($symbol, '1h'),
            ],
            'account' => [
                'mode' => $this->mode($config, $settings),
                'open_position' => (float) ($state->quantity ?? 0) > 0 ? [
                    'symbol' => (string) ($state->current_symbol ?? ''),
                    'side' => (string) ($state->position_side ?? ''),
                    'entry_price' => (float) ($state->entry_price ?? 0),
                ] : null,
                'trade_count_today' => (int) ($state->trade_count_today ?? 0),
                'max_trades_per_day' => (int) ($settings['maxTradesPerDay'] ?? 50),
                'daily_loss_pct' => (float) ($state->daily_loss ?? 0),
                'daily_profit_pct' => (float) ($state->daily_profit ?? 0),
                'loss_tiers_pct' => [
                    'warn' => (float) ($settings['softDailyLossWarnPercent'] ?? 1.2),
                    'block_entries' => (float) ($settings['maxDailyLossPercent'] ?? 2.0),
                    'hard_block' => (float) ($settings['hardDailyLossBlockPercent'] ?? 3.0),
                ],
                'wins' => (int) ($state->wins ?? 0),
                'losses' => (int) ($state->losses ?? 0),
                'cooldown_until' => optional($state->cooldown_until)->toIso8601String(),
            ],
            'recent_performance' => $this->recentTrades($config),
            'risk_plan' => [
                'leverage' => (float) ($settings['leverage'] ?? 3),
                'trade_amount_usdt' => (float) ($settings['tradeAmountUsdt'] ?? 25),
                'take_profit_pct' => (float) ($settings['takeProfitPercent'] ?? 1.5),
                'stop_loss_pct' => (float) ($settings['stopLossPercent'] ?? 0.75),
                'trailing_take_profit_pct' => (float) ($settings['trailingTakeProfitPercent'] ?? 0.45),
                'rollback_loss_pct' => (float) ($settings['rollbackLossPercent'] ?? 0.6),
            ],
        ];
    }

    private function timeframeSnapshot(string $symbol, string $timeframe): ?array
    {
        $candles = $this->klineBrain->getCachedCandles($symbol, $timeframe);
        if (count($candles) < 20) {
            return null;
        }

        $closes = array_map(static fn (array $candle) => (float) ($candle['close'] ?? 0), $candles);
        $last = $candles[count($candles) - 1];

        return [
            'candle_count' => count($candles),
            'last_close' => (float) ($last['close'] ?? 0),
            'recent_closes' => array_map(
                static fn (float $close) => round($close, 8),
                array_slice($closes, -20)
            ),
            'ema_20' => Indicators::ema($closes, 20),
            'ema_50' => Indicators::ema($closes, 50),
            'ema_200' => Indicators::ema($closes, 200),
            'rsi_14' => Indicators::rsi($closes, 14),
            'macd' => Indicators::macd($closes),
            'atr_14' => Indicators::atr($candles, 14),
            'adx_14' => Indicators::adx($candles, 14),
            'bollinger' => Indicators::bollinger($closes, 20),
        ];
    }

    private function recentTrades(FutureTradeBotConfig $config): array
    {
        return FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->where('status', FutureTradeLogicalTrade::STATUS_CLOSED)
            ->orderByDesc('closed_at')
            ->limit(10)
            ->get(['symbol', 'side', 'net_pnl', 'opened_at', 'closed_at'])
            ->map(static fn (FutureTradeLogicalTrade $trade) => [
                'symbol' => (string) $trade->symbol,
                'side' => (string) $trade->side,
                'result' => (float) $trade->net_pnl > 0 ? 'win' : ((float) $trade->net_pnl < 0 ? 'loss' : 'flat'),
                'net_pnl_usdt' => round((float) $trade->net_pnl, 4),
                'duration_minutes' => $trade->opened_at && $trade->closed_at
                    ? (int) $trade->opened_at->diffInMinutes($trade->closed_at)
                    : null,
            ])
            ->values()
            ->all();
    }

    private function mode(FutureTradeBotConfig $config, array $settings): string
    {
        $liveRequested = (bool) $config->enable_live_trading && (bool) ($settings['liveTrading'] ?? false);
        if (! $liveRequested) {
            return 'paper';
        }

        return ((bool) ($settings['forceTestnet'] ?? true)) ? 'testnet' : 'live';
    }
}
