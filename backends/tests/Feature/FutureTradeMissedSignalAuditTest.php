<?php

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeMissedSignalAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('missed trade audit review stores mode comparison and summarizes missed win capture by mode', function () {
    $workspaceId = (string) Str::uuid();
    $config = FutureTradeBotConfig::create([
        'workspace_id' => $workspaceId,
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => true,
        'enable_live_trading' => false,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), [
            'timeframe' => '5m',
            'minConfidence' => 0.52,
            'takeProfitPercent' => 0.8,
            'stopLossPercent' => 0.28,
            'missedTradeAuditEstimatedFeePercent' => 0.08,
            'missedTradeAuditEstimatedSlippagePercent' => 0.03,
            'missedTradeAuditMinNetProfitPercent' => 0.05,
        ]),
    ]);

    $config->state()->create([
        'status' => 'Running',
        'state' => 'SCANNING',
        'connection_status' => 'Connected',
        'trading_day' => now()->toDateString(),
        'scanner_snapshot' => [],
        'chart_points' => [],
    ]);

    $signalTime = now()->subMinutes(20)->startOfMinute();

    $audit = FutureTradeMissedSignalAudit::create([
        'future_trade_bot_config_id' => $config->id,
        'workspace_id' => $workspaceId,
        'symbol' => 'BTCUSDT',
        'side' => 'BUY',
        'timeframe' => '5m',
        'signal_time' => $signalTime,
        'entry_reference_price' => 100.00,
        'final_confidence' => 0.48,
        'active_minimum' => 0.52,
        'confidence_gap' => -0.04,
        'strategy_mode' => 'FULL_INDICATOR_INTELLIGENCE',
        'confidence_mode' => 'fixed',
        'strict_entry_guards' => false,
        'core_entry_passed' => true,
        'core_passed_count' => 4,
        'review_window_minutes' => 15,
        'top_skip_reason' => 'HTF filter blocked the setup',
        'top_3_blockers' => ['HTF filter blocked the setup', 'Volume weak'],
        'soft_penalties_json' => ['HTF filter blocked the setup', 'Volume weak'],
        'hard_blockers_json' => [],
        'component_scores_json' => ['htfPenalty' => -0.05, 'volumePenalty' => -0.03],
        'component_breakdown_json' => [
            'vwapPositive' => ['key' => 'vwapPositive', 'category' => 'core', 'finalContribution' => 0.12],
            'emaPositive' => ['key' => 'emaPositive', 'category' => 'core', 'finalContribution' => 0.14],
            'adxPositive' => ['key' => 'adxPositive', 'category' => 'core', 'finalContribution' => 0.10],
            'macdPositive' => ['key' => 'macdPositive', 'category' => 'core', 'finalContribution' => 0.12],
            'htfPenalty' => ['key' => 'htfPenalty', 'category' => 'guard', 'finalContribution' => -0.05],
            'volumePenalty' => ['key' => 'volumePenalty', 'category' => 'guard', 'finalContribution' => -0.03],
        ],
        'indicator_influence_json' => [],
        'market_snapshot_json' => [
            'config' => [
                'missedTradeAuditEstimatedFeePercent' => 0.08,
                'missedTradeAuditEstimatedSlippagePercent' => 0.03,
                'missedTradeAuditMinNetProfitPercent' => 0.05,
            ],
            'context' => [
                'activeTakeProfitPercent' => 0.8,
                'activeStopLossPercent' => 0.28,
            ],
        ],
        'status' => 'PENDING_REVIEW',
        'review_due_at' => now()->subMinute(),
    ]);

    foreach (range(0, 4) as $index) {
        DB::table('market_ohlc')->insert([
            'id' => (string) Str::uuid(),
            'exchange' => 'binance_futures',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1m',
            'open' => 100.00,
            'high' => $index === 0 ? 100.95 : 100.90,
            'low' => 99.96,
            'close' => 100.82,
            'volume' => 10 + $index,
            'ts' => $signalTime->copy()->addMinutes($index),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @var FutureTradeBotService $service */
    $service = app(FutureTradeBotService::class);
    $summary = $service->reviewPendingMissedSignalAudits($workspaceId, 15, 10);

    expect($summary['reviewed'])->toBe(1);

    $audit = $audit->fresh();
    expect($audit->would_have_result)->toBe('MISSED_WIN')
        ->and($audit->first_hit)->toBe('TP')
        ->and((float) $audit->net_would_have_pnl_percent)->toBeGreaterThan(0.05)
        ->and($audit->mode_comparison_json)->toBeArray()
        ->and(data_get($audit->mode_comparison_json, 'FULL_INDICATOR_INTELLIGENCE.decision'))->toBe('SKIP')
        ->and(data_get($audit->mode_comparison_json, 'CORE_ONLY.decision'))->toBe('TRADE_ALLOWED')
        ->and(data_get($audit->mode_comparison_json, 'CLASSIC_SIMPLE_LIVE_SIGNAL.decision'))->toBe('TRADE_ALLOWED')
        ->and(data_get($audit->mode_comparison_json, 'CORE_ONLY.wouldCaptureMissedWin'))->toBeTrue();

    $dashboard = $service->dashboardPayload($config->fresh('state'));
    expect(data_get($dashboard, 'missedTradeAudit.modeComparison.0.mode'))->not->toBeNull()
        ->and(collect((array) data_get($dashboard, 'missedTradeAudit.modeComparison'))->keyBy('mode')->get('CORE_ONLY')['capturedMissedWins'])->toBe(1)
        ->and(collect((array) data_get($dashboard, 'missedTradeAudit.modeComparison'))->keyBy('mode')->get('FULL_INDICATOR_INTELLIGENCE')['capturedMissedWins'])->toBe(0);
});

test('tuning plan preview recommends safe settings and config versioning stores snapshots', function () {
    $workspaceId = (string) Str::uuid();
    $config = FutureTradeBotConfig::create([
        'workspace_id' => $workspaceId,
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => true,
        'enable_live_trading' => false,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), [
            'minConfidence' => 0.52,
            'confidenceMode' => 'fixed',
        ]),
    ]);
    $config->state()->create([
        'status' => 'Running',
        'state' => 'SCANNING',
        'connection_status' => 'Connected',
        'trading_day' => now()->toDateString(),
        'scanner_snapshot' => [],
        'chart_points' => [],
    ]);

    FutureTradeMissedSignalAudit::create([
        'future_trade_bot_config_id' => $config->id,
        'workspace_id' => $workspaceId,
        'symbol' => 'ETHUSDT',
        'side' => 'BUY',
        'timeframe' => '5m',
        'signal_time' => now()->subMinutes(30),
        'entry_reference_price' => 100,
        'final_confidence' => 0.48,
        'active_minimum' => 0.52,
        'confidence_gap' => -0.04,
        'strategy_mode' => 'FULL_INDICATOR_INTELLIGENCE',
        'confidence_mode' => 'fixed',
        'strict_entry_guards' => false,
        'core_entry_passed' => true,
        'core_passed_count' => 4,
        'review_window_minutes' => 15,
        'top_skip_reason' => 'HTF filter blocked the setup',
        'top_3_blockers' => ['HTF filter blocked the setup'],
        'status' => 'REVIEWED',
        'would_have_result' => 'MISSED_WIN',
        'mode_comparison_json' => [
            'FULL_INDICATOR_INTELLIGENCE' => [
                'decision' => 'SKIP',
                'wouldCaptureMissedWin' => false,
                'wouldAllowBadSignal' => false,
                'wouldAllowCorrectSkip' => false,
                'netWouldHavePnlPercent' => 0.71,
            ],
            'CORE_ONLY' => [
                'decision' => 'TRADE_ALLOWED',
                'wouldCaptureMissedWin' => true,
                'wouldAllowBadSignal' => false,
                'wouldAllowCorrectSkip' => false,
                'netWouldHavePnlPercent' => 0.71,
            ],
            'CLASSIC_SIMPLE_LIVE_SIGNAL' => [
                'decision' => 'TRADE_ALLOWED',
                'wouldCaptureMissedWin' => true,
                'wouldAllowBadSignal' => true,
                'wouldAllowCorrectSkip' => false,
                'netWouldHavePnlPercent' => 0.71,
            ],
        ],
    ]);

    /** @var FutureTradeBotService $service */
    $service = app(FutureTradeBotService::class);
    $saved = $service->updateConfig($config, [
        'settings' => ['signalProfile' => 'legacy_future_trade', 'minConfidence' => 0.50],
        'algorithm_notes' => 'Preview version test',
    ]);

    expect($saved->configVersions()->count())->toBe(1);

    $plan = $service->generateTuningPlanPreview($config->fresh('state'));
    expect(data_get($plan, 'recommended.mode'))->toBe('CORE_ONLY')
        ->and((float) data_get($plan, 'recommended.minConfidence'))->toBeLessThanOrEqual(0.52)
        ->and((int) data_get($plan, 'previewImpact.capturedMissedWinsDelta'))->toBeGreaterThanOrEqual(1)
        ->and(data_get($plan, 'analysisOnly'))->toBeTrue()
        ->and(data_get($plan, 'autoApply'))->toBeFalse();
});
