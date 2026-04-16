<?php

use App\Domain\Portfolio\OpportunityScoringService;
use App\Domain\Portfolio\OpportunitySelectionService;
use App\Domain\Portfolio\PortfolioAllocationService;
use App\Domain\Portfolio\PortfolioRiskBudgetService;
use App\Domain\Portfolio\SymbolRankingService;

test('opportunity scoring favors a bullish allowlisted symbol', function () {
    $service = app(OpportunityScoringService::class);

    $score = $service->score(
        bullishSymbolContext(),
        bullishPortfolioContext([
            'allowed_symbols' => ['BTCUSDT'],
            'enabled_symbols' => ['BTCUSDT'],
            'blocked_symbols' => [],
        ])
    );

    expect($score['blocked'])->toBeFalse()
        ->and($score['recommended_action'])->toBe('buy')
        ->and($score['score'])->toBeGreaterThan(70)
        ->and($score['suggested_size_cap_pct'])->toBeGreaterThan(0);
});

test('opportunity scoring blocks a symbol outside the allowlist', function () {
    $service = app(OpportunityScoringService::class);

    $score = $service->score(
        bullishSymbolContext('ETHUSDT'),
        bullishPortfolioContext([
            'allowed_symbols' => ['BTCUSDT'],
            'enabled_symbols' => ['BTCUSDT'],
            'blocked_symbols' => [],
        ])
    );

    expect($score['blocked'])->toBeTrue()
        ->and($score['blockers'])->toContain('symbol_not_allowlisted')
        ->and($score['score'])->toBe(0.0)
        ->and($score['recommended_action'])->toBe('no_trade');
});

test('opportunity scoring blocks when live data is missing', function () {
    $service = app(OpportunityScoringService::class);

    $score = $service->score(
        array_merge(bullishSymbolContext(), [
            'symbol' => 'BTCUSDT',
            'price' => ['latest' => 0],
            'candles' => ['count' => 2],
            'indicators' => [
                'sma_9' => 0,
                'sma_21' => 0,
                'ema_12' => 0,
                'ema_26' => 0,
                'rsi_14' => 0,
                'macd' => ['histogram' => 0],
                'volatility_pct' => 0,
                'candle_count' => 2,
            ],
            'data_quality' => [
                'has_price' => false,
                'has_candles' => false,
                'blockers' => [],
            ],
        ]),
        bullishPortfolioContext([
            'allowed_symbols' => ['BTCUSDT'],
            'enabled_symbols' => ['BTCUSDT'],
            'blocked_symbols' => [],
        ])
    );

    expect($score['blocked'])->toBeTrue()
        ->and($score['blockers'])->toContain('missing_live_price')
        ->and($score['blockers'])->toContain('insufficient_candle_history')
        ->and($score['score'])->toBe(0.0);
});

test('risk budget blocks new entries when limits are breached', function () {
    $service = app(PortfolioRiskBudgetService::class);

    $budget = $service->evaluate(
        [
            'portfolio' => [
                'capital' => [
                    'equity_estimate' => 10000,
                    'allocated_capital' => 4200,
                    'free_capital' => 2000,
                    'cash_available' => 5000,
                    'peak_equity_estimate' => 11000,
                    'daily_total_pnl' => -420,
                ],
                'exposure' => [
                    'spot_positions' => 5,
                    'open_orders' => 4,
                ],
                'status' => [
                    'open_ai_decisions' => 3,
                ],
                'risk' => [
                    'kill_switch' => ['active' => false],
                    'provider_breaker' => ['active' => false],
                    'stale_feed_breaker' => ['active' => false],
                ],
                'mode' => [
                    'portfolio_intelligence_enabled' => true,
                    'ai_mode' => 'restricted_auto',
                ],
            ],
        ],
        [
            'max_total_allocated_pct' => 20,
            'max_symbol_allocated_pct' => 5,
            'max_correlated_group_allocated_pct' => 10,
            'max_simultaneous_new_trades' => 2,
            'reserve_cash_buffer_pct' => 15,
            'max_open_positions' => 4,
            'max_open_decision_count' => 2,
            'max_total_notional' => 1500,
            'max_daily_loss_pct' => 3,
            'max_drawdown_pct' => 8,
        ]
    );

    expect($budget['allowed'])->toBeFalse()
        ->and($budget['new_entries_allowed'])->toBeFalse()
        ->and($budget['reduce_risk_mode'])->toBeTrue()
        ->and($budget['reason_codes'])->toContain('daily_loss_limit_breached')
        ->and($budget['reason_codes'])->toContain('drawdown_limit_breached');
});

test('allocation respects shared group caps', function () {
    $service = app(PortfolioAllocationService::class);

    $allocation = $service->allocate(
        [
            [
                'symbol' => 'BTCUSDT',
                'score' => 90,
                'market_price' => 100,
                'exchange_metadata' => ['min_notional' => 10, 'step_size' => 0.1],
                'portfolio_impact' => ['group_key' => 'stable:usdt'],
                'correlation' => ['concentration_score' => 95],
                'score_breakdown' => [
                    ['factor' => 'external_context', 'score' => 80],
                    ['factor' => 'liquidity', 'score' => 85],
                ],
                'selection_status' => 'selected',
                'approval_required' => false,
                'auto_allowed' => true,
            ],
            [
                'symbol' => 'ETHUSDT',
                'score' => 88,
                'market_price' => 50,
                'exchange_metadata' => ['min_notional' => 10, 'step_size' => 0.1],
                'portfolio_impact' => ['group_key' => 'stable:usdt'],
                'correlation' => ['concentration_score' => 95],
                'score_breakdown' => [
                    ['factor' => 'external_context', 'score' => 78],
                    ['factor' => 'liquidity', 'score' => 82],
                ],
                'selection_status' => 'selected',
                'approval_required' => false,
                'auto_allowed' => true,
            ],
        ],
        [
            'portfolio' => [
                'capital' => [
                    'equity_estimate' => 1000,
                    'free_capital' => 300,
                ],
                'exposure' => [
                    'by_symbol' => [],
                    'by_group' => [],
                ],
            ],
        ],
        [
            'allocation_mode' => 'capped_score_weighted',
            'max_total_allocated_pct' => 20,
            'max_symbol_allocated_pct' => 10,
            'max_correlated_group_allocated_pct' => 5,
        ],
        [
            'remaining' => [
                'capital_notional' => 200,
            ],
        ]
    );

    expect($allocation['summary']['allocated_count'])->toBe(1)
        ->and($allocation['summary']['blocked_count'])->toBe(1)
        ->and($allocation['allocated_capital'])->toBeLessThanOrEqual(50.0);
});

test('selection watchlists strong buys when the trade quota is exhausted', function () {
    $service = app(OpportunitySelectionService::class);

    $selection = $service->select(
        [
            [
                'symbol' => 'BTCUSDT',
                'score' => 88,
                'recommended_action' => 'buy',
                'blocked' => false,
                'blockers' => [],
                'strengths' => ['trend aligned'],
                'cautions' => [],
            ],
            [
                'symbol' => 'ETHUSDT',
                'score' => 85,
                'recommended_action' => 'buy',
                'blocked' => false,
                'blockers' => [],
                'strengths' => ['trend aligned'],
                'cautions' => [],
            ],
        ],
        bullishPortfolioContext([
            'ai_mode' => 'restricted_auto',
        ]),
        [
            'opportunity_score_threshold' => 65,
            'enable_reduce_recommendations' => true,
        ],
        [
            'allowed' => true,
            'new_entries_allowed' => true,
            'reduce_risk_mode' => false,
            'remaining' => ['new_trades' => 1],
        ]
    );

    expect($selection['selected'])->toHaveCount(1)
        ->and($selection['watchlist'])->toHaveCount(1)
        ->and($selection['watchlist'][0]['blocked_reasons'])->toContain('max_simultaneous_new_trades_reached');
});

test('ranking keeps blocked opportunities behind actionable ones', function () {
    $service = app(SymbolRankingService::class);

    $ranked = $service->rank([
        ['symbol' => 'ETHUSDT', 'score' => 80, 'blocked' => true, 'recommended_action' => 'buy'],
        ['symbol' => 'BTCUSDT', 'score' => 70, 'blocked' => false, 'recommended_action' => 'buy'],
    ]);

    expect($ranked[0]['symbol'])->toBe('BTCUSDT')
        ->and($ranked[1]['symbol'])->toBe('ETHUSDT');
});

function bullishPortfolioContext(array $aiTradingConfigOverrides = []): array
{
    return [
        'portfolio' => [
            'capital' => [
                'equity_estimate' => 10000,
                'allocated_capital' => 1200,
                'free_capital' => 7000,
                'cash_available' => 8000,
                'peak_equity_estimate' => 10500,
                'daily_total_pnl' => 250,
            ],
            'exposure' => [
                'spot_positions' => 1,
                'open_orders' => 0,
                'by_symbol' => [],
                'by_group' => [],
            ],
            'risk' => [
                'kill_switch' => ['active' => false],
                'provider_breaker' => ['active' => false],
                'stale_feed_breaker' => ['active' => false],
                'portfolio_intelligence' => [
                    'reduce_size_under_external_risk' => true,
                ],
            ],
            'mode' => [
                'portfolio_intelligence_enabled' => true,
                'trading_enabled' => true,
                'ai_mode' => 'restricted_auto',
            ],
        ],
        'ai_trading_config' => array_merge([
            'allowed_symbols' => ['BTCUSDT'],
            'enabled_symbols' => ['BTCUSDT'],
            'blocked_symbols' => [],
            'use_external_context' => false,
        ], $aiTradingConfigOverrides),
        'external_context' => [
            'available' => false,
            'freshness_state' => 'unavailable',
            'high_severity_events' => [],
            'pause_review_hint' => false,
            'no_trade_hint' => false,
            'recommended_bias' => 'neutral',
        ],
    ];
}

function bullishSymbolContext(string $symbol = 'BTCUSDT'): array
{
    return [
        'symbol' => $symbol,
        'price' => ['latest' => 100],
        'candles' => ['count' => 40],
        'indicators' => [
            'sma_9' => 110,
            'sma_21' => 100,
            'ema_12' => 112,
            'ema_26' => 101,
            'rsi_14' => 60,
            'macd' => ['histogram' => 1.8],
            'volatility_pct' => 4.5,
            'candle_count' => 40,
        ],
        'signal_hints' => [
            'latest' => [
                'side' => 'buy',
                'decision_ts' => now()->subMinutes(15)->toIso8601String(),
                'meta_json' => ['confidence' => 0.84],
            ],
        ],
        'position' => [],
        'orders' => [],
        'recent_trades' => [
            ['realized_pnl' => 24],
            ['realized_pnl' => 18],
        ],
        'exchange_metadata' => [
            'min_notional' => 10,
            'step_size' => 0.001,
            'quote_asset' => 'USDT',
            'base_asset' => strtoupper(str_replace('USDT', '', $symbol)),
        ],
        'correlation' => [
            'group_key' => 'stable:usdt',
            'group_label' => 'Stable asset family',
            'concentration_score' => 92,
            'concentration_note' => 'Exposure is not concentrated.',
        ],
        'data_quality' => [
            'has_price' => true,
            'has_candles' => true,
            'blockers' => [],
        ],
        'external_context' => [
            'available' => false,
            'freshness_state' => 'unavailable',
            'high_severity_events' => [],
            'pause_review_hint' => false,
            'no_trade_hint' => false,
            'recommended_bias' => 'neutral',
        ],
    ];
}
