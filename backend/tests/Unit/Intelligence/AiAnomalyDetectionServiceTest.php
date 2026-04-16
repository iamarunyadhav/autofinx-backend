<?php

use App\Domain\Intelligence\AiAnomalyDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns pause recommendation for stale feed and breaker anomalies', function () {
    $service = app(AiAnomalyDetectionService::class);

    $report = $service->detect('ws-1', 'BTCUSDT', [
        'auto_pause_on_daily_loss' => true,
        'auto_pause_on_drawdown' => true,
        'auto_pause_on_order_failure_streak' => 3,
        'auto_pause_on_slippage_spike' => true,
        'auto_pause_on_reconciliation_mismatch' => true,
    ], [
        'balances' => ['capital_base' => 1000],
        'pnl' => ['realized_24h' => 0, 'unrealized_open' => 0],
        'risk' => ['rules' => []],
        'ops' => [
            'kill_switch' => ['active' => false],
            'stale_feed_breaker' => ['active' => true],
            'provider_breaker' => ['active' => false],
        ],
    ], []);

    expect($report['recommended_action'])->toBe('pause')
        ->and(collect($report['anomalies'])->pluck('code')->all())->toContain('STALE_FEED');
});

it('returns operator review for high severity slippage anomaly', function () {
    $service = app(AiAnomalyDetectionService::class);

    $report = $service->detect('ws-1', 'BTCUSDT', [
        'auto_pause_on_daily_loss' => false,
        'auto_pause_on_drawdown' => false,
        'auto_pause_on_order_failure_streak' => 3,
        'auto_pause_on_slippage_spike' => true,
        'auto_pause_on_reconciliation_mismatch' => false,
    ], [
        'balances' => ['capital_base' => 1000],
        'pnl' => ['realized_24h' => 0, 'unrealized_open' => 0],
        'risk' => ['rules' => []],
        'ops' => [
            'kill_switch' => ['active' => false],
            'stale_feed_breaker' => ['active' => false],
            'provider_breaker' => ['active' => false],
        ],
    ], [
        'symbol_state' => [
            'consecutive_order_failures' => 3,
        ],
    ]);

    expect($report['recommended_action'])->toBe('operator_review')
        ->and(collect($report['anomalies'])->pluck('code')->all())->toContain('ORDER_FAILURE_STREAK');
});
