<?php

namespace App\Domain\StrategyLab;

use App\Models\MarketOhlc;
use App\Models\MlFundingRate;
use App\Models\StrategyLabShadowSignal;
use Illuminate\Support\Carbon;

final class StrategyLabShadowService
{
    public function record(string $workspace, array $payload, Carbon $receivedAt): StrategyLabShadowSignal
    {
        $timeCloseMs = (int) ($payload['time_close'] ?? $payload['candle_close_time_ms'] ?? 0);
        $orderId = trim((string) ($payload['strategy_order_id'] ?? ''));
        if ($orderId === '') {
            $orderId = hash('sha256', implode('|', [$payload['strategy_id'], $payload['symbol'], $payload['signal'], $timeCloseMs]));
        }

        return StrategyLabShadowSignal::query()->firstOrCreate(
            ['workspace_id' => $workspace, 'strategy_id' => $payload['strategy_id'], 'strategy_order_id' => $orderId],
            [
                'symbol' => $this->symbol($payload['symbol']), 'timeframe' => $this->timeframe($payload['timeframe']),
                'signal' => strtoupper($payload['signal']), 'entry' => $payload['entry'] ?? $payload['price'] ?? null,
                'sl' => $payload['sl'] ?? null, 'tp1' => $payload['tp1'] ?? null, 'tp2' => $payload['tp2'] ?? null,
                'time_close_ms' => $timeCloseMs, 'latency_ms' => max(0, $receivedAt->valueOf() - $timeCloseMs),
                'payload' => $this->withoutToken($payload), 'received_at' => $receivedAt,
            ]
        );
    }

    public function report(string $workspace, ?string $strategyId = null): array
    {
        $query = StrategyLabShadowSignal::query()->where('workspace_id', $workspace);
        if ($strategyId) {
            $query->where('strategy_id', $strategyId);
        }
        $signals = $query->orderBy('time_close_ms')->get();
        foreach ($signals->where('evaluation_status', 'PENDING') as $signal) {
            $this->evaluate($signal);
        }
        $signals = $query->orderBy('time_close_ms')->get();
        $evaluated = $signals->where('evaluation_status', 'EVALUATED');

        return [
            'shadow_only' => true,
            'orders_placed' => 0,
            'signals' => $signals,
            'summary' => [
                'recorded' => $signals->count(), 'evaluated' => $evaluated->count(),
                'pending' => $signals->where('evaluation_status', 'PENDING')->count(),
                'avg_gross_pct' => $evaluated->avg('gross_return_pct'), 'avg_net_pct' => $evaluated->avg('net_return_pct'),
                'net_positive' => $evaluated->where('net_return_pct', '>', 0)->count(),
                'fees_pct_per_trade' => 0.12,
            ],
        ];
    }

    private function evaluate(StrategyLabShadowSignal $signal): void
    {
        if (! in_array($signal->signal, ['BUY', 'SELL'], true) || ! $signal->entry || ! $signal->sl || ! $signal->tp2) {
            $signal->update(['evaluation_status' => 'NOT_APPLICABLE']);
            return;
        }

        $from = Carbon::createFromTimestampMsUTC($signal->time_close_ms);
        $candles = MarketOhlc::query()->where('exchange', 'binance')->where('symbol', $signal->symbol)
            ->where('timeframe', $signal->timeframe)->where('ts', '>=', $from)->orderBy('ts')->limit(500)->get();
        if ($candles->isEmpty()) {
            return;
        }

        $isLong = $signal->signal === 'BUY';
        $exit = null; $reason = null; $exitTimeMs = null;
        foreach ($candles as $candle) {
            $stopHit = $isLong ? (float) $candle->low <= $signal->sl : (float) $candle->high >= $signal->sl;
            $targetHit = $isLong ? (float) $candle->high >= $signal->tp2 : (float) $candle->low <= $signal->tp2;
            if ($stopHit) { $exit = $signal->sl; $reason = 'SL'; $exitTimeMs = $candle->ts->valueOf(); break; }
            if ($targetHit) { $exit = $signal->tp2; $reason = 'TP2'; $exitTimeMs = $candle->ts->valueOf(); break; }
        }
        if ($exit === null) {
            return;
        }

        $gross = (($exit / $signal->entry) - 1) * 100 * ($isLong ? 1 : -1);
        $funding = (float) MlFundingRate::query()->where('symbol', $signal->symbol)
            ->whereBetween('funding_time_ms', [$signal->time_close_ms, (int) $exitTimeMs])->sum('funding_rate') * 100 * ($isLong ? 1 : -1);
        $signal->update([
            'evaluation_status' => 'EVALUATED', 'gross_return_pct' => $gross, 'fees_pct' => 0.12,
            'funding_pct' => $funding, 'net_return_pct' => $gross - 0.12 - $funding,
            'exit_reason' => $reason, 'evaluated_at' => now(),
        ]);
    }

    private function symbol(string $symbol): string
    {
        return preg_replace('/(?:\.P|PERP)$/', '', strtoupper(trim($symbol))) ?: '';
    }

    private function timeframe(string $timeframe): string
    {
        return match (strtoupper(trim($timeframe))) { '15', '15M' => '15m', '60', '1H' => '1h', '240', '4H' => '4h', 'D', '1D' => '1d' };
    }

    private function withoutToken(array $payload): array
    {
        unset($payload['token']);
        return $payload;
    }
}
