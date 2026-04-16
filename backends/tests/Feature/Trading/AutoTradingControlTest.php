<?php

use App\Console\Commands\BotsEvaluateCommand;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\Bot;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

test('bots evaluation pauses running bots when auto trading is disabled', function () {
    $workspace = Workspace::factory()->create();
    seedAutoTradingSetting($workspace->id, false);

    $bot = Bot::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => null,
        'connection_id' => null,
        'name' => 'Auto bot',
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'interval_sec' => 3600,
        'is_live' => false,
        'status' => 'RUNNING',
        'strategy_type' => 'threshold',
        'strategy_params' => [
            'anchor_price' => 100,
            'buy_drop_pct' => 0.5,
            'sell_rise_pct' => 0.5,
        ],
    ]);

    Artisan::call('bots:evaluate', [
        '--bot' => $bot->id,
        '--limit' => 1,
    ]);

    $refreshed = $bot->fresh();

    expect($refreshed->status)->toBe('PAUSED')
        ->and((string) ($refreshed->last_error ?? ''))->toContain('disabled');
});

test('bot start is blocked when auto trading is disabled', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    seedAutoTradingSetting($workspace->id, false);

    $bot = Bot::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'connection_id' => null,
        'name' => 'Auto bot',
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'interval_sec' => 3600,
        'is_live' => false,
        'status' => 'STOPPED',
        'strategy_type' => 'threshold',
        'strategy_params' => [
            'anchor_price' => 100,
            'buy_drop_pct' => 0.5,
            'sell_rise_pct' => 0.5,
        ],
    ]);

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->postJson("/api/v1/bots/{$bot->id}/start")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Auto trading is disabled for this workspace. Enable it in Settings before starting bots.');
});

function seedAutoTradingSetting(string $workspaceId, bool $enabled): void
{
    Setting::updateOrCreate(
        [
            'workspace_id' => $workspaceId,
            'group' => 'trading',
            'key' => 'auto_trading_enabled',
        ],
        [
            'id' => (string) Str::uuid(),
            'value_json' => $enabled,
            'encrypted' => false,
        ]
    );
}
