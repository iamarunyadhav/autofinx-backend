<?php

namespace Tests\Feature\FutureTrade;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PUT /future-trade/env-config is the switch that ultimately enables
 * live-fund trading for a workspace (envLiveEnabled). Previously it only
 * required auth:sanctum + require.2fa — any authenticated, 2FA'd user could
 * flip live trading on for ANY workspace whose UUID they happened to know,
 * not just their own. This proves membership is now enforced.
 */
class EnvConfigAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'mode' => 'testnet',
            'testnet_enabled' => true,
            'live_enabled' => false,
            'force_testnet' => true,
            'live_api_url' => 'https://fapi.binance.com',
            'testnet_api_url' => 'https://testnet.binancefuture.com',
        ];
    }

    public function test_a_user_with_no_membership_in_the_workspace_is_rejected(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $workspace = Workspace::factory()->create();
        // Deliberately no WorkspaceMember row for $user in $workspace.

        $response = $this->actingAs($user)
            ->putJson("/api/v1/workspaces/{$workspace->id}/future-trade/env-config", $this->payload());

        $response->assertStatus(403);
    }

    public function test_a_member_of_the_workspace_can_update_env_config(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/workspaces/{$workspace->id}/future-trade/env-config", $this->payload());

        $response->assertOk();
    }

    public function test_a_member_of_a_different_workspace_cannot_touch_this_one(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $ownWorkspace = Workspace::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        WorkspaceMember::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $ownWorkspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/workspaces/{$otherWorkspace->id}/future-trade/env-config", $this->payload());

        $response->assertStatus(403);
    }

    public function test_an_admin_of_the_workspace_can_update_env_config(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'admin',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/workspaces/{$workspace->id}/future-trade/env-config", $this->payload());

        $response->assertOk();
    }

    /**
     * This is the exact gap the audit flagged: env-config previously reused
     * the broader recovery-action authorization list
     * (owner/admin/trader/member), so any authenticated, 2FA'd workspace
     * member — not just an admin — could flip their own workspace to live
     * trading. Non-admin roles must now be rejected.
     */
    public function test_a_trader_of_the_workspace_cannot_update_env_config(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'trader',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/workspaces/{$workspace->id}/future-trade/env-config", $this->payload());

        $response->assertStatus(403);
    }

    public function test_a_plain_member_of_the_workspace_cannot_update_env_config(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/workspaces/{$workspace->id}/future-trade/env-config", $this->payload());

        $response->assertStatus(403);
    }
}
