<?php

namespace Tests\Feature\Ml;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Tests\TestCase;

final class MlShadowApiTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ml.enabled' => true, 'ml.symbols' => ['BTCUSDT', 'ETHUSDT'], 'ml.horizons_minutes' => [60, 120, 240, 1440]]);
        $user = User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user);
    }

    public function test_member_gets_shadow_only_payload_for_supported_duration(): void
    {
        $this->getJson('/api/v1/quick-trade/ml-shadow?'.http_build_query(['workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT', 'duration_seconds' => 3600]))
            ->assertOk()
            ->assertJsonPath('mode', 'shadow_only')
            ->assertJsonPath('execution', false)
            ->assertJsonPath('horizon_minutes', 60)
            ->assertJsonPath('status', 'NO_CHAMPION')
            ->assertJsonPath('live_evaluation.forecast.status', 'insufficient_data');

        $this->getJson('/api/v1/quick-trade/ml-shadow?'.http_build_query(['workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT', 'duration_seconds' => 300]))
            ->assertOk()->assertJsonPath('status', 'COST_EXCEEDS_MOVE')
            ->assertJsonPath('message', 'No model for this expiry: trading costs exceed the typical price move at this horizon.');

        $this->getJson('/api/v1/quick-trade/ml-shadow/learning?'.http_build_query(['workspace_id' => $this->workspace->id, 'symbol' => 'ETHUSDT']))
            ->assertOk()->assertJsonPath('candles.collected', 0)->assertJsonCount(4, 'horizons');
    }

    public function test_non_member_is_refused_and_unknown_symbol_is_reported(): void
    {
        $other = Workspace::factory()->create();
        $this->getJson('/api/v1/quick-trade/ml-shadow?'.http_build_query(['workspace_id' => $other->id, 'symbol' => 'BTCUSDT', 'duration_seconds' => 3600]))
            ->assertForbidden();
        $this->getJson('/api/v1/quick-trade/ml-shadow/learning?'.http_build_query(['workspace_id' => $other->id, 'symbol' => 'BTCUSDT']))
            ->assertForbidden();
        $this->getJson('/api/v1/quick-trade/ml-shadow?'.http_build_query(['workspace_id' => $this->workspace->id, 'symbol' => 'DOGEUSDT', 'duration_seconds' => 3600]))
            ->assertOk()->assertJsonPath('status', 'UNSUPPORTED_SYMBOL')->assertJsonPath('supported_symbols', ['BTCUSDT', 'ETHUSDT']);
        $this->getJson('/api/v1/quick-trade/ml-shadow?'.http_build_query(['workspace_id' => $this->workspace->id, 'duration_seconds' => 3600]))
            ->assertUnprocessable();
    }

    public function test_disabled_flag_reports_disabled(): void
    {
        config(['ml.enabled' => false]);
        $this->getJson('/api/v1/quick-trade/ml-shadow?'.http_build_query(['workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT', 'duration_seconds' => 3600]))
            ->assertOk()->assertJsonPath('status', 'DISABLED')->assertJsonPath('execution', false);
    }
}
