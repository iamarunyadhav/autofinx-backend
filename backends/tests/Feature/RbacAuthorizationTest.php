<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    public function test_unauthenticated_cannot_access_connections(): void
    {
        $this->getJson('/api/v1/workspaces/invalid/connections')->assertStatus(401);
    }

    public function test_viewer_cannot_create_connection(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $workspaceId = Workspace::factory()->create()->id;
        WorkspaceMember::create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$workspaceId}/connections", [
                'name' => 'Binance',
                'provider' => 'binance',
                'api_key' => 'k',
                'api_secret' => 's',
            ])
            ->assertStatus(201);
    }
}
