<?php

namespace Tests\Feature\Fast2X;

use App\Domain\Fast2X\Fast2XDaemonManager;
use App\Domain\Fast2X\Fast2XService;
use App\Models\Fast2XState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the actual restart-on-crash gap: fast2x:run is a per-workspace
 * daemon with no Supervisor entry, so fast2x:supervise (routes/console.php,
 * every minute) is what detects a dead/hung one via
 * Fast2XState.last_heartbeat_at and restarts it.
 *
 * Uses a fake Fast2XDaemonManager bound into the container so the test never
 * actually spawns/kills a real OS process -- it only asserts the command's
 * decision logic (which workspaces get restarted) is correct.
 */
class Fast2XSuperviseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_running_workspace_gets_its_daemon_restarted(): void
    {
        $workspace = Workspace::factory()->create();
        $config = app(Fast2XService::class)->getOrCreateConfig((string) $workspace->id);
        $config->state()->update([
            'status' => 'Running',
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $fake = new class extends Fast2XDaemonManager
        {
            public array $spawned = [];

            public array $terminated = [];

            public function isRunning(string $workspaceId): bool
            {
                return false;
            }

            public function terminate(string $workspaceId): void
            {
                $this->terminated[] = $workspaceId;
            }

            public function spawn(string $workspaceId): ?array
            {
                $this->spawned[] = $workspaceId;

                return ['pid' => 12345, 'workspace_id' => $workspaceId];
            }
        };
        $this->app->instance(Fast2XDaemonManager::class, $fake);

        $this->artisan('fast2x:supervise')->assertExitCode(0);

        $this->assertSame([(string) $workspace->id], $fake->spawned);

        $state = Fast2XState::query()->where('fast2x_config_id', $config->id)->firstOrFail();
        $this->assertSame('Running', $state->status);
    }

    public function test_a_workspace_with_a_fresh_heartbeat_is_left_alone(): void
    {
        $workspace = Workspace::factory()->create();
        $config = app(Fast2XService::class)->getOrCreateConfig((string) $workspace->id);
        $config->state()->update([
            'status' => 'Running',
            'last_heartbeat_at' => now(),
        ]);

        $fake = new class extends Fast2XDaemonManager
        {
            public array $spawned = [];

            public function spawn(string $workspaceId): ?array
            {
                $this->spawned[] = $workspaceId;

                return ['pid' => 1, 'workspace_id' => $workspaceId];
            }
        };
        $this->app->instance(Fast2XDaemonManager::class, $fake);

        $this->artisan('fast2x:supervise')->assertExitCode(0);

        $this->assertSame([], $fake->spawned);
    }

    public function test_a_stopped_workspace_is_never_supervised(): void
    {
        $workspace = Workspace::factory()->create();
        $config = app(Fast2XService::class)->getOrCreateConfig((string) $workspace->id);
        $config->state()->update([
            'status' => 'Stopped',
            'last_heartbeat_at' => now()->subDays(3),
        ]);

        $fake = new class extends Fast2XDaemonManager
        {
            public array $spawned = [];

            public function spawn(string $workspaceId): ?array
            {
                $this->spawned[] = $workspaceId;

                return ['pid' => 1, 'workspace_id' => $workspaceId];
            }
        };
        $this->app->instance(Fast2XDaemonManager::class, $fake);

        $this->artisan('fast2x:supervise')->assertExitCode(0);

        $this->assertSame([], $fake->spawned);
    }

    public function test_a_running_workspace_with_a_live_pid_but_hung_heartbeat_is_terminated_before_respawn(): void
    {
        $workspace = Workspace::factory()->create();
        $config = app(Fast2XService::class)->getOrCreateConfig((string) $workspace->id);
        $config->state()->update([
            'status' => 'Running',
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $fake = new class extends Fast2XDaemonManager
        {
            public array $spawned = [];

            public array $terminated = [];

            public function isRunning(string $workspaceId): bool
            {
                return true;
            }

            public function terminate(string $workspaceId): void
            {
                $this->terminated[] = $workspaceId;
            }

            public function spawn(string $workspaceId): ?array
            {
                $this->spawned[] = $workspaceId;

                return ['pid' => 999, 'workspace_id' => $workspaceId];
            }
        };
        $this->app->instance(Fast2XDaemonManager::class, $fake);

        $this->artisan('fast2x:supervise')->assertExitCode(0);

        $this->assertSame([(string) $workspace->id], $fake->terminated);
        $this->assertSame([(string) $workspace->id], $fake->spawned);
    }
}
