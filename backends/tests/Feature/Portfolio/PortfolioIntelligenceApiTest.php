<?php

use App\Models\AiTradingConfig;
use App\Models\ExchangeSymbolMetadata;
use App\Models\Integration;
use App\Models\MarketCandle;
use App\Models\PortfolioIntelligenceConfig;
use App\Models\PortfolioIntelligenceRun;
use App\Models\Symbol;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Str;

test('portfolio intelligence config api supports read and update', function () {
    $fixture = seedPortfolioIntelligenceWorkspace();

    $this->actingAs($fixture['user']);

    $this->getJson("/api/v1/workspaces/{$fixture['workspace']->id}/portfolio/intelligence/config")
        ->assertOk()
        ->assertJsonPath('config.workspace_id', $fixture['workspace']->id);

    $this->putJson("/api/v1/workspaces/{$fixture['workspace']->id}/portfolio/intelligence/config", [
        'enabled' => true,
        'ranking_mode' => 'heuristic',
        'allocation_mode' => 'capped_score_weighted',
        'opportunity_score_threshold' => 70,
        'max_total_allocated_pct' => 20,
        'max_symbol_allocated_pct' => 25,
        'reserve_cash_buffer_pct' => 15,
    ])->assertStatus(422);

    $this->putJson("/api/v1/workspaces/{$fixture['workspace']->id}/portfolio/intelligence/config", [
        'enabled' => true,
        'ranking_mode' => 'heuristic',
        'allocation_mode' => 'capped_score_weighted',
        'opportunity_score_threshold' => 70,
        'max_total_allocated_pct' => 30,
        'max_symbol_allocated_pct' => 8,
        'max_correlated_group_allocated_pct' => 15,
        'max_simultaneous_new_trades' => 2,
        'reserve_cash_buffer_pct' => 12,
        'max_open_positions' => 8,
        'max_open_decision_count' => 4,
        'max_total_notional' => 5000,
        'max_daily_loss_pct' => 3,
        'max_drawdown_pct' => 8,
        'prefer_hold_cash_under_high_uncertainty' => true,
        'reduce_size_under_external_risk' => true,
        'require_approval_for_rotation' => true,
        'enable_reduce_recommendations' => true,
        'enable_rotation_recommendations' => false,
        'notes' => 'portfolio test',
    ])->assertOk()
        ->assertJsonPath('config.enabled', true)
        ->assertJsonPath('effective_config.max_symbol_allocated_pct', '8.0000');
});

test('portfolio intelligence run stores a ranked snapshot and exposes latest state', function () {
    $fixture = seedPortfolioIntelligenceWorkspace();

    $this->actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/workspaces/{$fixture['workspace']->id}/portfolio/intelligence/run", [
        'trigger_type' => 'manual',
    ])->assertCreated();

    expect($response->json('summary.state'))->toBe('healthy')
        ->and($response->json('ranked_opportunities.0.symbol'))->toBe('BTCUSDT')
        ->and($response->json('ranked_opportunities'))->not->toBeEmpty();

    expect(PortfolioIntelligenceRun::query()->where('workspace_id', $fixture['workspace']->id)->count())->toBe(1);

    $this->getJson("/api/v1/workspaces/{$fixture['workspace']->id}/portfolio/intelligence/latest")
        ->assertOk()
        ->assertJsonPath('workspace_id', $fixture['workspace']->id)
        ->assertJsonCount(2, 'ranked_opportunities');
});

test('portfolio intelligence symbol score api returns a detailed breakdown', function () {
    $fixture = seedPortfolioIntelligenceWorkspace();

    $this->actingAs($fixture['user']);

    $this->postJson("/api/v1/workspaces/{$fixture['workspace']->id}/portfolio/intelligence/run", [
        'trigger_type' => 'manual',
    ])->assertCreated();

    $this->getJson("/api/v1/workspaces/{$fixture['workspace']->id}/portfolio/intelligence/symbols/BTCUSDT")
        ->assertOk()
        ->assertJsonPath('symbol', 'BTCUSDT')
        ->assertJsonPath('recommended_action', 'buy')
        ->assertJsonStructure([
            'symbol',
            'score',
            'recommended_action',
            'score_breakdown',
            'portfolio_impact',
        ]);
});

function seedPortfolioIntelligenceWorkspace(): array
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Live',
        'status' => 'active',
        'config_json' => ['environment' => 'live'],
    ]);

    AiTradingConfig::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'enabled' => true,
        'mode' => 'restricted_auto',
        'exchange' => 'binance',
        'market_type' => 'spot',
        'provider' => 'heuristic',
        'enabled_symbols' => ['BTCUSDT', 'ETHUSDT'],
        'allowed_symbols' => ['BTCUSDT', 'ETHUSDT'],
        'blocked_symbols' => [],
        'max_trade_size_pct' => 2,
        'min_confidence' => 0.6,
        'decision_interval_minutes' => 5,
        'max_pending_decisions_per_symbol' => 1,
        'max_decisions_per_hour_per_symbol' => 12,
        'max_decisions_per_hour' => 60,
        'cooldown_minutes_after_trade' => 0,
        'cooldown_minutes_after_loss' => 0,
        'pause_until' => null,
        'auto_pause_on_daily_loss' => true,
        'auto_pause_on_drawdown' => true,
        'auto_pause_on_slippage_spike' => true,
        'auto_pause_on_reconciliation_mismatch' => true,
        'manual_resume_required' => false,
        'require_stop_loss' => true,
        'require_take_profit' => true,
        'require_time_stop' => true,
        'auto_trade_enabled' => false,
        'use_external_context' => false,
        'max_external_context_age_minutes' => 60,
    ]);

    PortfolioIntelligenceConfig::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        ...PortfolioIntelligenceConfig::defaults(),
        'enabled' => true,
        'opportunity_score_threshold' => 65,
    ]);

    WalletAccount::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'currency' => 'USDT',
        'type' => 'cash',
        'balance' => 10000,
        'available' => 10000,
        'locked_balance' => 0,
    ]);

    $btc = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
        'exchange' => 'binance',
    ]);

    $eth = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'ETHUSDT',
        'type' => 'crypto',
        'exchange' => 'binance',
    ]);

    ExchangeSymbolMetadata::create([
        'id' => (string) Str::uuid(),
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'base_asset' => 'BTC',
        'quote_asset' => 'USDT',
        'min_qty' => 0.0001,
        'max_qty' => 1000,
        'step_size' => 0.0001,
        'min_notional' => 10,
        'tick_size' => 0.1,
    ]);

    ExchangeSymbolMetadata::create([
        'id' => (string) Str::uuid(),
        'exchange' => 'binance',
        'symbol' => 'ETHUSDT',
        'base_asset' => 'ETH',
        'quote_asset' => 'USDT',
        'min_qty' => 0.001,
        'max_qty' => 10000,
        'step_size' => 0.001,
        'min_notional' => 10,
        'tick_size' => 0.1,
    ]);

    seedCandles($btc->id, [90, 92, 94, 95, 96, 97, 98, 99, 100, 101, 102, 104, 105, 106, 108, 110, 111, 112, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125]);
    seedCandles($eth->id, [130, 128, 126, 125, 124, 123, 122, 121, 120, 119, 118, 117, 116, 115, 114, 113, 112, 111, 110, 109, 108, 107, 106, 105, 104, 103, 102, 101, 100, 99]);

    return compact('workspace', 'user');
}

function seedCandles(string $symbolId, array $closes): void
{
    $base = now()->startOfHour()->subMinutes(count($closes));

    foreach ($closes as $index => $close) {
        $open = (float) $close - 0.5;
        MarketCandle::create([
            'id' => (string) Str::uuid(),
            'symbol_id' => $symbolId,
            'timeframe' => '1m',
            'opens_at' => $base->copy()->addMinutes($index),
            'open' => $open,
            'high' => (float) $close + 0.5,
            'low' => (float) $close - 1,
            'close' => (float) $close,
            'volume' => 1000 + ($index * 10),
        ]);
    }
}
