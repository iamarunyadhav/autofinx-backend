<?php

use App\Models\AssistantAction;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function assistantUser(string $role, Workspace $workspace): User
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

test('mutation assistant actions default to requiring 2fa on proposal', function () {
    $workspace = Workspace::factory()->create();
    $user = assistantUser('trader', $workspace);

    $this->actingAs($user)
        ->postJson('/api/v1/assistant/actions/propose', [
            'workspace_id' => $workspace->id,
            'tool_name' => 'toggle_strategy',
            'request_json' => ['instance_id' => (string) Str::uuid(), 'status' => 'paused'],
        ])
        ->assertCreated()
        ->assertJsonPath('requires_2fa', true);
});

test('assistant actions must be approved before execution', function () {
    $workspace = Workspace::factory()->create();
    $user = assistantUser('trader', $workspace);
    $action = AssistantAction::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'tool_name' => 'get_portfolio_summary',
        'request_json' => [],
        'preview_json' => [],
        'status' => 'proposed',
        'requires_2fa' => false,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/assistant/actions/{$action->id}/execute")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Assistant actions must be approved before execution.');
});

test('approved assistant actions execute and persist the result', function () {
    $workspace = Workspace::factory()->create();
    $user = assistantUser('trader', $workspace);
    $action = AssistantAction::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'tool_name' => 'get_portfolio_summary',
        'request_json' => [],
        'preview_json' => [],
        'status' => 'approved',
        'requires_2fa' => false,
        'approved_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/assistant/actions/{$action->id}/execute")
        ->assertOk()
        ->assertJsonPath('action.status', 'executed')
        ->assertJsonPath('result.ok', true);

    $action->refresh();
    expect($action->status)->toBe('executed')
        ->and($action->executed_at)->not->toBeNull()
        ->and($action->preview_json['execution_result']['ok'] ?? false)->toBeTrue();
});
