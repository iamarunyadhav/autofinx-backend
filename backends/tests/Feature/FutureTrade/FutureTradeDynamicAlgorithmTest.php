<?php

namespace Tests\Feature\FutureTrade;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FutureTradeDynamicAlgorithmTest extends TestCase
{
    public function test_fixed_mode_keeps_manual_min_confidence_as_effective_threshold(): void
    {
        $config = $this->futureConfig([
            'confidenceMode' => 'fixed',
            'minConfidence' => 0.72,
        ]);
        $config->state->update([
            'chart_points' => $this->trendPoints(100, 104, 20),
        ]);
        $this->fakeMarket([$this->bullTicker('ETHUSDT', 104, 100, 94, 106, 5.2)]);

        $payload = app(FutureTradeBotService::class)->dashboardPayload($config->fresh('state'));

        $this->assertSame('fixed', $payload['dynamic']['mode']);
        $this->assertSame(0.72, $payload['dynamic']['effectiveMinConfidence']);
        $this->assertStringContainsString('Fixed mode', $payload['dynamic']['suggestion']);
    }

    public function test_semi_dynamic_raises_threshold_and_takes_faster_profit_in_choppy_market(): void
    {
        $config = $this->futureConfig([
            'confidenceMode' => 'semi_dynamic',
            'minConfidence' => 0.72,
            'takeProfitPercent' => 1.0,
            'trailingTakeProfitPercent' => 0.2,
        ]);
        $config->state->update([
            'chart_points' => $this->choppyPoints(100, 0.22, 24),
        ]);
        $this->fakeMarket([$this->bullTicker('ETHUSDT', 100.05, 99.8, 90, 110, 0.2)]);

        $payload = app(FutureTradeBotService::class)->dashboardPayload($config->fresh('state'));

        $this->assertTrue($payload['dynamic']['analysis']['choppy']);
        $this->assertGreaterThan(0.72, $payload['dynamic']['effectiveMinConfidence']);
        $this->assertLessThan(1.0, $payload['dynamic']['recommended']['takeProfitPercent']);
        $this->assertStringContainsString('Choppy market', implode(' ', $payload['dynamic']['adjustments']));
    }

    public function test_semi_dynamic_strong_aligned_trend_lowers_entry_threshold_within_caps(): void
    {
        $config = $this->futureConfig([
            'confidenceMode' => 'semi_dynamic',
            'minConfidence' => 0.72,
            'dynamicMinConfidenceFloor' => 0.68,
            'takeProfitPercent' => 1.0,
        ]);
        $config->state->update([
            'chart_points' => $this->trendPoints(100, 102.4, 24),
        ]);
        $this->fakeMarket([
            $this->bullTicker('ETHUSDT', 102.4, 100, 95, 104, 2.4, 1_000_000_000_000),
            $this->bullTicker('BTCUSDT', 204, 200, 190, 210, 2.0, 200_000_000_000),
        ]);

        $payload = app(FutureTradeBotService::class)->dashboardPayload($config->fresh('state'));

        $this->assertFalse($payload['dynamic']['analysis']['choppy']);
        $this->assertGreaterThanOrEqual(0.68, $payload['dynamic']['effectiveMinConfidence']);
        $this->assertLessThan(0.72, $payload['dynamic']['effectiveMinConfidence']);
        $this->assertGreaterThan(1.0, $payload['dynamic']['recommended']['takeProfitPercent']);
    }

    public function test_take_profit_reentry_buffer_requires_stronger_signal_after_recent_profit(): void
    {
        $config = $this->futureConfig([
            'confidenceMode' => 'semi_dynamic',
            'minConfidence' => 0.72,
            'dynamicReentryBuffer' => 0.03,
        ]);
        $config->trades()->create([
            'symbol' => 'ETHUSDT',
            'side' => 'CLOSE_LONG',
            'entry_price' => 100,
            'exit_price' => 101,
            'quantity' => 1,
            'reason' => 'TAKE_PROFIT_HIT',
            'status' => 'FILLED',
            'mode' => 'PAPER',
        ]);
        $config->state->update([
            'wins' => 1,
            'chart_points' => $this->trendPoints(100, 101.2, 20),
        ]);
        $this->fakeMarket([$this->bullTicker('ETHUSDT', 101.2, 100, 96, 103, 1.2)]);

        $payload = app(FutureTradeBotService::class)->dashboardPayload($config->fresh('state'));

        $this->assertGreaterThanOrEqual(0.75, $payload['dynamic']['effectiveMinConfidence']);
        $this->assertStringContainsString('re-entry buffer', implode(' ', $payload['dynamic']['adjustments']));
    }

    public function test_fully_dynamic_after_recent_loss_raises_threshold_extends_cooldown_and_reduces_size(): void
    {
        $config = $this->futureConfig([
            'confidenceMode' => 'fully_dynamic',
            'minConfidence' => 0.72,
            'tradeAmountUsdt' => 100,
            'cooldownSeconds' => 60,
        ]);
        $config->trades()->create([
            'symbol' => 'ETHUSDT',
            'side' => 'CLOSE_LONG',
            'entry_price' => 100,
            'exit_price' => 99,
            'quantity' => 1,
            'reason' => 'STOP_LOSS_HIT',
            'status' => 'FILLED',
            'mode' => 'PAPER',
        ]);
        $config->state->update([
            'wins' => 1,
            'losses' => 3,
            'chart_points' => $this->trendPoints(100, 101.8, 24),
        ]);
        $this->fakeMarket([$this->bullTicker('ETHUSDT', 101.8, 100, 96, 103, 1.8)]);

        $payload = app(FutureTradeBotService::class)->dashboardPayload($config->fresh('state'));

        $this->assertGreaterThan(0.72, $payload['dynamic']['effectiveMinConfidence']);
        $this->assertGreaterThanOrEqual(120, $payload['dynamic']['recommended']['cooldownSeconds']);
        $this->assertSame(75.0, $payload['dynamic']['recommended']['tradeAmountUsdt']);
        $this->assertStringContainsString('Recent loss', implode(' ', $payload['dynamic']['adjustments']));
    }

    public function test_fully_dynamic_still_obeys_ceiling_floor_and_blocks_low_confidence_entry(): void
    {
        $config = $this->futureConfig([
            'confidenceMode' => 'fully_dynamic',
            'minConfidence' => 0.86,
            'dynamicMinConfidenceCeiling' => 0.88,
            'autoExecutePaperSignals' => true,
        ], enabled: true);
        $config->state->update([
            'chart_points' => $this->choppyPoints(100, 0.22, 24),
        ]);
        $this->fakeMarket([$this->bullTicker('ETHUSDT', 100.05, 99.8, 90, 110, 0.2, 100_000)]);

        $scan = app(FutureTradeBotService::class)->scan($config->fresh('state'), true);

        $this->assertLessThanOrEqual(0.88, $scan['bestSignal']['effectiveMinConfidence']);
        $this->assertDatabaseMissing('future_trade_bot_trades', [
            'future_trade_bot_config_id' => $config->id,
            'status' => 'OPEN',
        ]);
        $this->assertDatabaseHas('future_trade_bot_logs', [
            'future_trade_bot_config_id' => $config->id,
            'event' => 'SIGNAL_SKIPPED',
        ]);
    }

    public function test_dynamic_execution_persists_decision_context_in_trade_meta(): void
    {
        $config = $this->futureConfig([
            'confidenceMode' => 'semi_dynamic',
            'minConfidence' => 0.68,
            'tradeAmountUsdt' => 25,
            'leverage' => 2,
            'autoExecutePaperSignals' => true,
        ], enabled: true);
        $config->state->update([
            'chart_points' => $this->trendPoints(100, 103, 24),
        ]);
        $this->fakeMarket([$this->bullTicker('ETHUSDT', 103, 100, 96, 104, 3.0, 1_000_000_000_000)]);

        app(FutureTradeBotService::class)->scan($config->fresh('state'), true);
        $trade = $config->trades()->where('status', 'OPEN')->firstOrFail();

        $this->assertSame('semi_dynamic', $trade->meta['dynamic']['mode']);
        $this->assertArrayHasKey('recommended', $trade->meta['dynamic']);
        $this->assertSame('AUTO_BUY_SIGNAL', $trade->reason);
    }

    private function futureConfig(array $settings = [], bool $enabled = false): FutureTradeBotConfig
    {
        $config = FutureTradeBotConfig::create([
            'workspace_id' => fake()->uuid(),
            'paper_trading' => true,
            'enable_live_trading' => false,
            'enabled' => $enabled,
            'settings' => array_merge(config('future_trade.defaults'), [
                'symbols' => ['ETHUSDT', 'BTCUSDT'],
                'liveTrading' => false,
                'paperTrading' => true,
                'forceTestnet' => true,
                'avoidChoppyMarket' => false,
            ], $settings),
        ]);
        $config->state()->create([
            'status' => $enabled ? 'Running' : 'Stopped',
            'state' => 'SCANNING',
            'connection_status' => 'Connected',
            'chart_points' => [],
        ]);

        return $config->fresh('state');
    }

    private function fakeMarket(array $rows): void
    {
        Http::fake([
            'fapi.binance.com/fapi/v1/ticker/24hr' => Http::response($rows, 200),
            '*' => Http::response($rows, 200),
        ]);
    }

    private function bullTicker(string $symbol, float $price, float $open, float $low, float $high, float $change, int $volume = 1_000_000_000): array
    {
        return [
            'symbol' => $symbol,
            'lastPrice' => (string) $price,
            'openPrice' => (string) $open,
            'lowPrice' => (string) $low,
            'highPrice' => (string) $high,
            'weightedAvgPrice' => (string) max(0.0001, $price * 0.985),
            'priceChangePercent' => (string) $change,
            'quoteVolume' => (string) $volume,
        ];
    }

    private function trendPoints(float $start, float $end, int $count): array
    {
        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $ratio = $count > 1 ? $i / ($count - 1) : 1;
            $points[] = [
                'time' => now()->subSeconds(($count - $i) * 3)->format('H:i:s'),
                'symbol' => 'ETHUSDT',
                'price' => round($start + (($end - $start) * $ratio), 6),
                'pnl' => 0,
            ];
        }

        return $points;
    }

    private function choppyPoints(float $base, float $swing, int $count): array
    {
        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $direction = $i % 2 === 0 ? 1 : -1;
            $points[] = [
                'time' => now()->subSeconds(($count - $i) * 3)->format('H:i:s'),
                'symbol' => 'ETHUSDT',
                'price' => round($base + ($direction * $swing), 6),
                'pnl' => 0,
            ];
        }

        return $points;
    }
}
