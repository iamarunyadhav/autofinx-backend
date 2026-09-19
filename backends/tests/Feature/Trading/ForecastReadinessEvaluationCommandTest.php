<?php

namespace Tests\Feature\Trading;

use App\Models\ForecastReadinessSnapshot;
use App\Models\QuickTrade;
use App\Models\RatioBotForecastBacktest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves `forecast:evaluate-readiness` (the automated, all-bot-type
 * successor to manually polling QuickTradeResearchController::readiness())
 * writes a real, correctly-scoped snapshot per active scope, detects and
 * logs a status change/downgrade between two runs, and that the new
 * readiness-history endpoint is workspace-scoped like every sibling route
 * in QuickTradeResearchController.
 */
class ForecastReadinessEvaluationCommandTest extends TestCase
{
    use RefreshDatabase;

    private function quickTrade(array $overrides = []): QuickTrade
    {
        return QuickTrade::create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory()->create()->id,
            'symbol' => 'BTCUSDT',
            'side' => 'buy',
            'stake_amount' => 100,
            'quantity' => 1,
            'asset_class' => 'CRYPTO',
            'mode' => 'live',
            'source' => 'manual_quick_trade',
            'provider_key' => 'binance',
            'quote_currency' => 'USDT',
            'timeframe' => '1m',
            'expiry_seconds' => 60,
            'payout_ratio' => 0,
            'entry_price' => 100,
            'exit_price' => 100.3,
            'profit_loss' => -0.1,
            'gross_pnl' => 0.3,
            'fees_total' => 0.4,
            'fee_source' => 'real',
            'status' => 'lost',
            'entry_at' => now()->subMinutes(2),
            'expires_at' => now()->subMinute(),
            'settled_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_command_persists_a_snapshot_per_active_quick_trade_scope_with_correct_identity(): void
    {
        $this->quickTrade();

        $this->artisan('forecast:evaluate-readiness')->assertExitCode(0);

        $snapshot = ForecastReadinessSnapshot::query()->where('bot_type', 'quick_trade')->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('binance:BTCUSDT:1m', $snapshot->bot_instance_id);
        $this->assertSame(60, $snapshot->horizon_seconds);
        $this->assertSame('INSUFFICIENT_DATA', $snapshot->status);
        $this->assertNotNull($snapshot->computed_at);
        $this->assertIsArray($snapshot->report_json);
    }

    public function test_no_active_scope_produces_no_fabricated_snapshot(): void
    {
        $this->artisan('forecast:evaluate-readiness')->assertExitCode(0);

        $this->assertDatabaseCount('forecast_readiness_snapshots', 0);
    }

    public function test_a_status_downgrade_between_two_runs_is_logged_as_an_error(): void
    {
        $this->quickTrade();
        RatioBotForecastBacktest::create([
            'id' => (string) Str::uuid(),
            'bot_type' => 'quick_trade',
            'bot_instance_id' => 'binance:BTCUSDT:1m',
            'horizon_seconds' => 60,
            'confidence_bucket' => '80-90',
            'evaluation_period' => 'training',
            'sample_size' => 40,
            'hits' => 30,
            'hit_rate_pct' => 75.0,
            'avg_confidence_pct' => 85.0,
            'computed_at' => now(),
        ]);

        // First run: training data exists but no holdout evidence yet ->
        // NOT_VALIDATED (a real, non-fabricated status).
        $this->artisan('forecast:evaluate-readiness')->assertExitCode(0);
        $first = ForecastReadinessSnapshot::query()->where('bot_type', 'quick_trade')->orderByDesc('computed_at')->first();
        $this->assertSame('NOT_VALIDATED', $first->status);

        // All backtest evidence disappears (e.g. purged) -> the same scope
        // now has NO real data at all -> INSUFFICIENT_DATA, a genuine
        // downgrade in trust from the prior run.
        RatioBotForecastBacktest::query()->delete();

        // Distinct wall-clock second from the first run -- this table's
        // `computed_at` is stored with whole-second resolution (Eloquent's
        // default date format), so two runs executed within the same real
        // second would otherwise tie; scheduled production runs are ~24h
        // apart and never hit this, so this only matters for the test.
        $this->travelTo(now()->addDay());

        Log::spy();
        $this->artisan('forecast:evaluate-readiness')->assertExitCode(0);

        $second = ForecastReadinessSnapshot::query()->where('bot_type', 'quick_trade')->orderByDesc('computed_at')->first();
        $this->assertSame('INSUFFICIENT_DATA', $second->status);
        $this->assertNotSame($first->id, $second->id);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, array $context = []) => str_contains($message, 'Readiness status downgraded')
                && ($context['previous_status'] ?? null) === 'NOT_VALIDATED'
                && ($context['new_status'] ?? null) === 'INSUFFICIENT_DATA')
            ->once();
    }

    public function test_readiness_history_endpoint_returns_scoped_snapshots_and_rejects_foreign_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user);

        ForecastReadinessSnapshot::create([
            'id' => (string) Str::uuid(),
            'bot_type' => 'quick_trade',
            'bot_instance_id' => 'binance:BTCUSDT:1m',
            'horizon_seconds' => 60,
            'status' => 'NOT_VALIDATED',
            'reasons' => ['holdout_not_evaluated'],
            'report_json' => ['bot_type' => 'quick_trade'],
            'computed_at' => now(),
        ]);

        $url = '/api/v1/workspaces/'.$workspace->id.'/quick-trades/research/readiness-history';
        $this->getJson($url.'?bot_instance_id=binance:BTCUSDT:1m&horizon_seconds=60')
            ->assertOk()
            ->assertJsonPath('scope.bot_type', 'quick_trade')
            ->assertJsonPath('snapshots.0.status', 'NOT_VALIDATED')
            ->assertJsonCount(1, 'snapshots');

        $other = Workspace::factory()->create();
        $this->getJson('/api/v1/workspaces/'.$other->id.'/quick-trades/research/readiness-history?bot_instance_id=binance:BTCUSDT:1m&horizon_seconds=60')
            ->assertForbidden();
    }
}
