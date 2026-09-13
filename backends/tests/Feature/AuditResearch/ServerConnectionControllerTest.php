<?php

namespace Tests\Feature\AuditResearch;

use App\Models\ServerConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\ExchangeSecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers: credential encryption/masking, IDOR isolation (same pattern as
 * AuditResearchControllerIdorTest / RatioBotControllerIdorTest), the fixed
 * whitelist rejecting unknown check keys, and sandbox-mode determinism.
 */
class ServerConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private Workspace $workspaceA;
    private Workspace $workspaceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->workspaceA = Workspace::factory()->create();
        $this->workspaceB = Workspace::factory()->create();

        WorkspaceMember::create([
            'workspace_id' => $this->workspaceA->id,
            'user_id' => $this->userA->id,
            'role' => 'owner',
        ]);
    }

    private function storeConnection(?string $host = 'test-server.example.com'): array
    {
        return $this->actingAs($this->userA)
            ->postJson("/api/v1/workspaces/{$this->workspaceA->id}/server-connections", [
                'name' => 'Primary diagnostics box',
                'host' => $host,
                'ssh_username' => 'diagnostics_readonly',
                'auth_method' => 'password',
                'credential' => 'super-secret-password-value',
            ])
            ->assertCreated()
            ->json();
    }

    /** @test */
    public function credential_is_encrypted_at_rest_with_the_shared_exchange_secret_vault(): void
    {
        $this->storeConnection();

        $connection = ServerConnection::first();
        $vault = app(ExchangeSecretVault::class);

        $this->assertNotSame('super-secret-password-value', $connection->credential_encrypted);
        $this->assertSame('super-secret-password-value', $vault->decrypt($connection->credential_encrypted));
    }

    /** @test */
    public function credential_never_appears_in_any_controller_json_response(): void
    {
        $created = $this->storeConnection();
        $connectionId = $created['id'];

        $responses = [
            $created,
            $this->actingAs($this->userA)->getJson("/api/v1/workspaces/{$this->workspaceA->id}/server-connections")->assertOk()->json()[0],
            $this->actingAs($this->userA)->getJson("/api/v1/server-connections/{$connectionId}")->assertOk()->json(),
            $this->actingAs($this->userA)->putJson("/api/v1/server-connections/{$connectionId}", ['name' => 'Renamed'])->assertOk()->json(),
        ];

        foreach ($responses as $response) {
            $this->assertArrayNotHasKey('credential_encrypted', $response);
            $this->assertArrayNotHasKey('credential', $response);
            foreach ($response as $value) {
                if (is_string($value)) {
                    $this->assertStringNotContainsString('super-secret-password-value', $value);
                }
            }
        }
    }

    /** @test */
    public function run_check_rejects_an_unknown_check_key_without_executing_anything(): void
    {
        $created = $this->storeConnection();

        $this->actingAs($this->userA)
            ->postJson("/api/v1/server-connections/{$created['id']}/run-check", [
                'check_key' => 'rm -rf / ; DROP TABLE users;',
            ])
            ->assertStatus(422);
    }

    /** @test */
    public function checks_endpoint_exposes_the_real_whitelist(): void
    {
        $created = $this->storeConnection();

        $response = $this->actingAs($this->userA)
            ->getJson("/api/v1/server-connections/{$created['id']}/checks")
            ->assertOk()
            ->json();

        $this->assertContains('disk_usage', $response['checks']);
        $this->assertContains('process_health', $response['checks']);
    }

    /** @test */
    public function sandbox_mode_run_check_is_deterministic_and_updates_status(): void
    {
        $created = $this->storeConnection('sandbox-demo.example.com');

        $first = $this->actingAs($this->userA)
            ->postJson("/api/v1/server-connections/{$created['id']}/run-check", ['check_key' => 'disk_usage'])
            ->assertOk()
            ->json();

        $second = $this->actingAs($this->userA)
            ->postJson("/api/v1/server-connections/{$created['id']}/run-check", ['check_key' => 'disk_usage'])
            ->assertOk()
            ->json();

        $this->assertTrue($first['connected']);
        $this->assertTrue($first['authenticated']);
        $this->assertSame($first['output'], $second['output']);
        $this->assertSame('sandbox', $first['mode']);

        $connection = ServerConnection::find($created['id']);
        $this->assertSame(ServerConnection::STATUS_CONNECTED, $connection->status);
        $this->assertNotNull($connection->last_checked_at);
    }

    /** @test */
    public function sandbox_mode_test_connection_reports_connected(): void
    {
        $created = $this->storeConnection('example.com');

        $response = $this->actingAs($this->userA)
            ->postJson("/api/v1/server-connections/{$created['id']}/test")
            ->assertOk()
            ->json();

        $this->assertSame(ServerConnection::STATUS_CONNECTED, $response['status']);
    }

    /** @test */
    public function cannot_list_server_connections_in_a_workspace_the_user_is_not_a_member_of(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/workspaces/{$this->workspaceB->id}/server-connections")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_create_a_server_connection_in_a_workspace_the_user_is_not_a_member_of(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/workspaces/{$this->workspaceB->id}/server-connections", [
                'name' => 'x',
                'host' => 'example.com',
                'ssh_username' => 'ro',
                'auth_method' => 'password',
                'credential' => 'secret',
            ])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_update_delete_test_or_run_check_on_a_connection_in_another_workspace(): void
    {
        $memberOfB = User::factory()->create(['two_factor_confirmed_at' => now()]);
        WorkspaceMember::create([
            'workspace_id' => $this->workspaceB->id,
            'user_id' => $memberOfB->id,
            'role' => 'owner',
        ]);

        $foreign = $this->actingAs($memberOfB)
            ->postJson("/api/v1/workspaces/{$this->workspaceB->id}/server-connections", [
                'name' => 'Foreign box',
                'host' => 'example.com',
                'ssh_username' => 'ro',
                'auth_method' => 'password',
                'credential' => 'secret',
            ])
            ->assertCreated()
            ->json();

        $foreignId = $foreign['id'];

        $this->actingAs($this->userA)->getJson("/api/v1/server-connections/{$foreignId}")->assertStatus(403);
        $this->actingAs($this->userA)->putJson("/api/v1/server-connections/{$foreignId}", ['name' => 'x'])->assertStatus(403);
        $this->actingAs($this->userA)->deleteJson("/api/v1/server-connections/{$foreignId}")->assertStatus(403);
        $this->actingAs($this->userA)->postJson("/api/v1/server-connections/{$foreignId}/test")->assertStatus(403);
        $this->actingAs($this->userA)->postJson("/api/v1/server-connections/{$foreignId}/run-check", ['check_key' => 'disk_usage'])->assertStatus(403);
        $this->actingAs($this->userA)->getJson("/api/v1/server-connections/{$foreignId}/checks")->assertStatus(403);
    }

    /** @test */
    public function a_member_of_the_owning_workspace_can_delete_their_own_connection(): void
    {
        $created = $this->storeConnection();

        $this->actingAs($this->userA)
            ->deleteJson("/api/v1/server-connections/{$created['id']}")
            ->assertOk();

        $this->assertNull(ServerConnection::find($created['id']));
    }
}
