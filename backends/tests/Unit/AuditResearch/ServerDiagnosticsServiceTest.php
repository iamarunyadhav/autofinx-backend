<?php

namespace Tests\Unit\AuditResearch;

use App\Domain\AuditResearch\ServerDiagnostics\DiagnosticCheckRegistry;
use App\Domain\AuditResearch\ServerDiagnostics\ServerDiagnosticsService;
use App\Domain\AuditResearch\ServerDiagnostics\SshExecutor;
use App\Models\ServerConnection;
use App\Support\ExchangeSecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerDiagnosticsServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function the_registry_rejects_an_unknown_check_key(): void
    {
        $this->assertFalse(DiagnosticCheckRegistry::has('rm -rf /'));
        $this->expectException(\InvalidArgumentException::class);
        DiagnosticCheckRegistry::commandFor('not_a_real_check');
    }

    /** @test */
    public function every_registered_command_is_a_literal_fixed_string(): void
    {
        foreach (DiagnosticCheckRegistry::all() as $key => $command) {
            $this->assertIsString($command);
            $this->assertNotSame('', trim($command));
        }
    }

    private function makeConnection(string $host): ServerConnection
    {
        return ServerConnection::create([
            'workspace_id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Test box',
            'host' => $host,
            'port' => 22,
            'ssh_username' => 'diagnostics_readonly',
            'auth_method' => 'password',
            'credential_encrypted' => app(ExchangeSecretVault::class)->encrypt('irrelevant'),
            'status' => ServerConnection::STATUS_NOT_CONFIGURED,
        ]);
    }

    /** @test */
    public function sandbox_hosts_produce_deterministic_run_check_output(): void
    {
        $connection = $this->makeConnection('sandbox.example.com');
        $service = app(ServerDiagnosticsService::class);

        $first = $service->runCheck($connection, 'uptime');
        $second = $service->runCheck($connection->fresh(), 'uptime');

        $this->assertTrue($first['connected']);
        $this->assertTrue($first['authenticated']);
        $this->assertSame($first['output'], $second['output']);
        $this->assertSame('sandbox', $first['mode']);
        $this->assertSame(ServerConnection::STATUS_CONNECTED, $connection->fresh()->status);
    }

    /** @test */
    public function sandbox_mode_test_connection_is_deterministic(): void
    {
        $connection = $this->makeConnection('127.0.0.1');
        $service = app(ServerDiagnosticsService::class);

        $result = $service->testConnection($connection);

        $this->assertSame('connected', $result['status']);
        $this->assertTrue($result['connected']);
        $this->assertTrue($result['authenticated']);
    }

    /** @test */
    public function unknown_check_key_throws_before_ever_touching_the_ssh_transport(): void
    {
        $connection = $this->makeConnection('sandbox.example.com');
        $service = app(ServerDiagnosticsService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->runCheck($connection, 'rm -rf /');
    }

    /** @test */
    public function a_real_non_sandbox_host_uses_the_injected_ssh_executor_and_maps_statuses(): void
    {
        $fake = new class implements SshExecutor {
            public array $ranCommands = [];

            public function run(ServerConnection $connection, string $plaintextCredential, string $command): array
            {
                $this->ranCommands[] = $command;

                return ['connected' => true, 'authenticated' => false, 'output' => null, 'error' => 'Authentication failed'];
            }

            public function testConnection(ServerConnection $connection, string $plaintextCredential): array
            {
                return ['connected' => true, 'authenticated' => false, 'error' => 'Authentication failed'];
            }
        };

        $this->app->instance(SshExecutor::class, $fake);

        $connection = $this->makeConnection('real-diagnostics-host.internal-corp.net');
        $service = app(ServerDiagnosticsService::class);

        $this->assertFalse($service->isSandbox($connection));

        $result = $service->runCheck($connection, 'uptime');

        $this->assertSame(DiagnosticCheckRegistry::commandFor('uptime'), $fake->ranCommands[0]);
        $this->assertSame(ServerConnection::STATUS_UNAUTHORIZED, $connection->fresh()->status);
        $this->assertSame('live', $result['mode']);
    }
}
