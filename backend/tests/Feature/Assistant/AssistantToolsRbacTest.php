<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeUser(string $role, Workspace $workspace): User
{
    $user = User::factory()->create();
    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);
    return $user;
}

test('viewer cannot toggle strategy', function () {
    $workspace = Workspace::create(['id' => (string) Str::uuid(), 'name' => 'WS']);
    $viewer = makeUser('viewer', $workspace);

    $this->actingAs($viewer)
        ->postJson('/api/v1/assistant/function-call', [
            'tool' => 'toggle_strategy',
            'params' => ['instance_id' => (string) Str::uuid(), 'status' => 'running'],
        ])->assertForbidden();
});

test('trader can call allowed tool', function () {
    $workspace = Workspace::create(['id' => (string) Str::uuid(), 'name' => 'WS']);
    $trader = makeUser('trader', $workspace);

    $this->actingAs($trader)
        ->postJson('/api/v1/assistant/function-call', [
            'tool' => 'get_portfolio_summary',
            'params' => [],
        ])->assertStatus(200);
});
