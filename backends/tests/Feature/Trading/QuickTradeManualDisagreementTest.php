<?php

use App\Domain\Trading\QuickTradeResearchService;
use App\Domain\Trading\QuickTradeService;
use App\Models\User;
use App\Models\Workspace;

/**
 * Covers spec item 61: "If system says Expiry Decision: BUY but user
 * manually chooses SELL, allow existing behavior unless current rules
 * prohibit it. Record manual_action_disagreed_with_prediction = true for
 * research. Do not secretly change user's order."
 *
 * QuickTradeResearchService::capture() is mocked so the "system's most
 * recent prediction" (decision_trend) is fully deterministic here, rather
 * than depending on whatever a synthetic paper-mode candle series happens
 * to produce -- the flag-setting logic under test
 * (QuickTradeService::manualActionDisagreedWithPrediction()) is exercised
 * for real; only the upstream forecast input is stubbed.
 */
function fakeQuickTradeResearchSnapshot(string $decisionTrend): array
{
    return [
        'schema_version' => 1,
        'captured_at' => now()->toIso8601String(),
        'primary' => [
            'available' => true,
            'decision_trend' => $decisionTrend,
            'horizon_seconds' => 5,
            'current_price' => 100,
        ],
    ];
}

function placeQuickTradeForDisagreementTest(string $side): \App\Models\QuickTrade
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    return app(QuickTradeService::class)->place([
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => $side,
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 5,
        'mode' => 'paper',
        'indicator_set' => ['RSI'],
    ], $user->id);
}

test('a manual SELL that disagrees with a predicted BUY is recorded but the order still executes as SELL', function () {
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        $mock->shouldReceive('capture')->andReturn(fakeQuickTradeResearchSnapshot('BUY'));
    });

    $trade = placeQuickTradeForDisagreementTest('sell');

    expect($trade->side)->toBe('sell');
    expect($trade->manual_action_disagreed_with_prediction)->toBeTrue();
});

test('a manual BUY that disagrees with a predicted SELL is recorded but the order still executes as BUY', function () {
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        $mock->shouldReceive('capture')->andReturn(fakeQuickTradeResearchSnapshot('SELL'));
    });

    $trade = placeQuickTradeForDisagreementTest('buy');

    expect($trade->side)->toBe('buy');
    expect($trade->manual_action_disagreed_with_prediction)->toBeTrue();
});

test('a manual side matching the predicted decision is recorded as agreeing, not disagreeing', function () {
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        $mock->shouldReceive('capture')->andReturn(fakeQuickTradeResearchSnapshot('BUY'));
    });

    $trade = placeQuickTradeForDisagreementTest('buy');

    expect($trade->manual_action_disagreed_with_prediction)->toBeFalse();
});

test('a WAIT prediction never fabricates a disagreement comparison, it stays null', function () {
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        $mock->shouldReceive('capture')->andReturn(fakeQuickTradeResearchSnapshot('WAIT'));
    });

    $trade = placeQuickTradeForDisagreementTest('sell');

    expect($trade->manual_action_disagreed_with_prediction)->toBeNull();
});

test('no research snapshot at all leaves manual_action_disagreed_with_prediction null, never guessed', function () {
    config(['quick_trade_research.enabled' => false]);

    $trade = placeQuickTradeForDisagreementTest('sell');

    expect($trade->manual_action_disagreed_with_prediction)->toBeNull();
});
