<?php

namespace App\Domain\Trading;

use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Models\QuickTrade;
use App\Models\QuickTradeResearchSample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Observational only: no trading decisions, settlement or model promotion. */
class QuickTradeResearchService
{
    public function capture(array $candles, array $signal, array $payload): array
    {
        $captured = now()->toIso8601String();
        $result = ['schema_version' => 1, 'captured_at' => $captured, 'origin' => 'server_pre_entry',
            'mode' => 'shadow_only', 'config' => Arr::only($payload, [
                'symbol', 'timeframe', 'expiry_seconds', 'stake_amount', 'side', 'mode', 'provider',
                'indicator_set', 'stop_loss_pct', 'take_profit_pct', 'leverage_enabled', 'leverage',
            ]), 'primary' => null, 'shadow' => null];
        try {
            $forecasts = app(TradeForecastService::class);
            $result['primary'] = $forecasts->forecast($candles, $payload['timeframe'],
                (int) $payload['expiry_seconds'], $forecasts->decisionFromSignalPreview($signal));
            $result['input_candles'] = array_map(fn ($row) => Arr::only($row,
                ['timestamp', 'time', 'opens_at', 'open', 'high', 'low', 'close', 'volume']), array_slice($candles, -100));
            $result['input_candles_include_forming_bar'] = true;
            $result['market_exchange'] = (string) config('unified_forecast.quick_trade_default_exchange', 'binance');
            if (($payload['mode'] ?? 'paper') === 'live' && ($payload['provider'] ?? 'binance') === 'binance'
                && config('unified_forecast.quick_trade_shadow_enabled', true)) {
                $result['shadow'] = app(UnifiedForecastEngine::class)->evaluate('quick_trade',
                    $result['market_exchange'].':'.$payload['symbol'].':'.$payload['timeframe'],
                    $result['market_exchange'], $payload['symbol'], $payload['timeframe'], (int) $payload['expiry_seconds']);
                $result['shadow']['horizon_seconds'] = (int) $payload['expiry_seconds'];
                $result['shadow']['current_price'] = $result['primary']['current_price'] ?? null;
                $result['shadow']['price_target_available'] = false;
            }
        } catch (Throwable $e) {
            $result['capture_status'] = 'partial';
            $result['capture_reason'] = 'forecast_capture_failed';
        }
        $result['collect_until_ms'] = CarbonImmutable::parse($captured)->addSeconds(
            max((int) $payload['expiry_seconds'], (int) ($result['primary']['horizon_seconds'] ?? 0)) + 10)->getTimestampMs();
        return $result;
    }

    public function report(QuickTrade $trade): array
    {
        $payload = (array) $trade->signal_payload;
        $research = (array) ($payload['research'] ?? []);
        $cost = (array) ($payload['execution_cost'] ?? []);
        $samples = Schema::hasTable('quick_trade_research_samples')
            ? QuickTradeResearchSample::where('quick_trade_id', $trade->id)->orderBy('quote_time_ms')->get() : collect();
        $rows = $samples->map(fn ($row) => ['time' => CarbonImmutable::createFromTimestampMs($row->quote_time_ms)->toIso8601String(),
            'time_ms' => $row->quote_time_ms, 'price' => (float) $row->price, 'source' => $row->source,
            'kind' => 'sampled_quote', 'recorded_at' => $row->recorded_at->toIso8601String()])->all();
        $closed = $trade->status !== 'open' && $trade->settled_at !== null && (float) $trade->exit_price > 0;
        $direction = $closed ? $this->direction((float) $trade->entry_price, (float) $trade->exit_price) : null;
        $signal = $this->normalize($payload['direction'] ?? null);
        $primary = $this->comparison($research['primary'] ?? null, $research['captured_at'] ?? null, $rows);
        $shadow = $this->comparison($research['shadow'] ?? null, $research['captured_at'] ?? null, $rows);
        $timeline = [['time' => $trade->entry_at?->toIso8601String(), 'time_ms' => $trade->entry_at?->getTimestampMs(),
            'price' => (float) $trade->entry_price, 'kind' => 'entry_fill', 'source' => 'stored_trade_execution']];
        foreach ($rows as $row) {
            $delta = ($row['price'] - (float) $trade->entry_price) * ($trade->side === 'sell' ? -1 : 1);
            $row['position_open_at_quote'] = ! $closed || $row['time_ms'] <= $trade->settled_at->getTimestampMs();
            $row['gross_pnl_at_quote'] = $row['position_open_at_quote'] && $trade->mode === 'live' && (float) $trade->quantity > 0
                ? round($delta * (float) $trade->quantity, 8) : null;
            $row['direction_from_entry'] = $this->direction((float) $trade->entry_price, $row['price']);
            $timeline[] = $row;
        }
        if ($closed) {
            $timeline[] = ['time' => $trade->settled_at->toIso8601String(), 'time_ms' => $trade->settled_at->getTimestampMs(),
                'price' => (float) $trade->exit_price, 'kind' => 'exit_fill', 'source' => 'stored_trade_execution'];
        }
        usort($timeline, fn ($a, $b) => ($a['time_ms'] ?? 0) <=> ($b['time_ms'] ?? 0));
        return [
            'schema_version' => 1, 'trade_id' => $trade->id, 'symbol' => $trade->symbol,
            'mode' => $trade->mode, 'source' => $trade->source, 'status' => $trade->status,
            'execution_environment' => $research['execution_environment'] ?? ($trade->mode === 'paper' ? 'paper' : 'unknown'),
            'quote_currency' => $trade->quote_currency, 'side' => $trade->side,
            'entry_at' => $trade->entry_at?->toIso8601String(), 'expires_at' => $trade->expires_at?->toIso8601String(),
            'settled_at' => $trade->settled_at?->toIso8601String(), 'entry_price' => (float) $trade->entry_price,
            'exit_price' => $closed ? (float) $trade->exit_price : null,
            'quantity' => $trade->quantity !== null ? (float) $trade->quantity : null,
            'stake_amount' => (float) $trade->stake_amount, 'leverage' => $trade->leverage,
            'timeframe' => $trade->timeframe, 'expiry_seconds' => $trade->expiry_seconds,
            'stop_loss_pct' => $trade->stop_loss_pct, 'take_profit_pct' => $trade->take_profit_pct,
            'stop_loss_triggered_at' => $trade->stop_loss_triggered_at?->toIso8601String(),
            'take_profit_triggered_at' => $trade->take_profit_triggered_at?->toIso8601String(),
            'fees' => [
                'source' => $trade->fee_source ?? $cost['fee_source'] ?? 'unavailable',
                'entry_actual' => $trade->entry_fee_actual, 'exit_actual' => $trade->exit_fee_actual,
                'funding_actual_cost' => $trade->funding_fee_actual,
                'entry_used' => $cost['entry_fee'] ?? null, 'exit_used' => $cost['exit_fee'] ?? null,
                'funding_used' => $cost['funding_fee'] ?? null, 'total_used' => $trade->fees_total,
            ],
            'gross_pnl' => $closed ? ($trade->gross_pnl ?? $cost['gross_pnl'] ?? null) : null,
            'net_pnl_booked' => $closed ? (float) $trade->profit_loss : null,
            'stake_plus_booked_net' => $closed ? round((float) $trade->stake_amount + (float) $trade->profit_loss, 8) : null,
            'payout_booked' => $closed ? $trade->payout_amount : null,
            'slippage' => Arr::only($cost, ['entry_slippage_pct', 'exit_slippage_pct', 'entry_reference_price', 'exit_reference_price']),
            'slippage_already_in_fill_pnl' => true,
            'entry_signal' => Arr::only($payload, ['direction', 'confidence', 'reasons', 'bullish_score', 'bearish_score', 'neutral_score', 'indicator_values']),
            'indicators_at_entry' => $trade->indicator_snapshot,
            'trade_exit_direction' => $direction,
            'entry_signal_correct_at_trade_exit' => $signal !== null && $direction !== null ? $signal === $direction : null,
            'research_snapshot' => $research ?: null, 'primary_comparison' => $primary, 'shadow_comparison' => $shadow,
            'timeline' => $timeline,
            'data_quality' => ['snapshot_available' => $research !== [], 'sample_count' => count($rows),
                'price_history' => $rows === [] ? 'entry_exit_only_no_intratrade_samples' : 'sampled_not_every_tick',
                'paper_is_synthetic' => $trade->mode !== 'live',
                'training_eligible' => $trade->mode === 'live' && ($research['execution_environment'] ?? '') === 'live'
                    && $closed && $primary['status'] === 'evaluated'
                    && ($trade->fee_source ?? '') === 'real',
                'notes' => 'Features are pre-entry snapshots; comparisons/PnL/timeline are post-entry labels. Split chronologically; do not train features on future outcomes.'],
        ];
    }

    public function comparison(?array $forecast, ?string $capturedAt, array $samples): array
    {
        $unknown = ['status' => 'unavailable', 'direction_correct' => null, 'price_in_predicted_range' => null,
            'predicted_direction' => null, 'actual_direction' => null, 'predicted_price' => null, 'actual_price' => null];
        if (! $forecast || ! $capturedAt || ($forecast['available'] ?? true) === false
            || ($forecast['status'] ?? 'clear') !== 'clear') {
            return $unknown;
        }
        $direction = $this->normalize($forecast['bias'] ?? $forecast['direction'] ?? null);
        $base = (float) ($forecast['current_price'] ?? 0);
        $horizon = (int) ($forecast['horizon_seconds'] ?? 0);
        if ($direction === null || $base <= 0 || $horizon <= 0) {
            return $unknown;
        }
        $target = CarbonImmutable::parse($capturedAt)->addSeconds($horizon);
        $result = array_merge($unknown, ['predicted_direction' => $direction,
            'predicted_price' => $forecast['expected_mid_price'] ?? null, 'target_at' => $target->toIso8601String()]);
        if ($target->isFuture()) {
            return array_merge($result, ['status' => 'pending']);
        }
        $matching = array_values(array_filter($samples, fn ($row) => $row['time_ms'] >= $target->getTimestampMs()
            && $row['time_ms'] <= $target->getTimestampMs() + 1000 * (int) config('quick_trade_research.max_outcome_delay_seconds', 10)));
        usort($matching, fn ($a, $b) => $a['time_ms'] <=> $b['time_ms']);
        if ($matching === []) {
            return array_merge($result, ['status' => 'missing_horizon_sample']);
        }
        $actual = (float) $matching[0]['price'];
        $actualDirection = $this->direction($base, $actual);
        return array_merge($result, ['status' => 'evaluated', 'actual_price' => $actual,
            'actual_direction' => $actualDirection, 'direction_correct' => $direction === $actualDirection,
            'sample_delay_ms' => $matching[0]['time_ms'] - $target->getTimestampMs(),
            'price_in_predicted_range' => isset($forecast['expected_low'], $forecast['expected_high'])
                ? $actual >= $forecast['expected_low'] && $actual <= $forecast['expected_high'] : null,
            'price_error' => isset($forecast['expected_mid_price']) ? $actual - (float) $forecast['expected_mid_price'] : null,
            'price_error_pct' => isset($forecast['expected_mid_price']) && (float) $forecast['expected_mid_price'] > 0
                ? round(($actual - (float) $forecast['expected_mid_price']) / (float) $forecast['expected_mid_price'] * 100, 6) : null,
        ]);
    }

    private function direction(float $from, float $to): string
    {
        return abs($to - $from) < max(1e-8, abs($from) * 1e-10) ? 'neutral' : ($to > $from ? 'buy' : 'sell');
    }

    private function normalize(?string $direction): ?string
    {
        return match (strtolower($direction ?? '')) {
            'buy', 'long', 'up' => 'buy', 'sell', 'short', 'down' => 'sell', 'neutral', 'hold', 'flat' => 'neutral', default => null,
        };
    }
}
