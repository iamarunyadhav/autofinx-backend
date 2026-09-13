<?php

namespace App\Domain\AuditResearch\ServerDiagnostics;

use App\Models\ServerConnection;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/**
 * Real SSH transport, backed by phpseclib3\Net\SSH2 (composer require
 * phpseclib/phpseclib:^3.0 -- installed successfully in this environment,
 * see composer.lock). Only ever runs the ONE fixed command string it is
 * given by ServerDiagnosticsService -- never builds or receives anything
 * derived from user input.
 */
class PhpseclibSshExecutor implements SshExecutor
{
    public function run(ServerConnection $connection, string $plaintextCredential, string $command): array
    {
        $probe = $this->connectAndAuthenticate($connection, $plaintextCredential);
        if (! $probe['ssh'] instanceof SSH2 || ! $probe['authenticated']) {
            return [
                'connected' => $probe['connected'],
                'authenticated' => $probe['authenticated'],
                'output' => null,
                'error' => $probe['error'],
            ];
        }

        /** @var SSH2 $ssh */
        $ssh = $probe['ssh'];
        $output = $ssh->exec($command);

        return [
            'connected' => true,
            'authenticated' => true,
            'output' => $output,
            'error' => $ssh->getExitStatus() === 0 ? null : ('exit_status=' . $ssh->getExitStatus()),
        ];
    }

    public function testConnection(ServerConnection $connection, string $plaintextCredential): array
    {
        $probe = $this->connectAndAuthenticate($connection, $plaintextCredential);

        return [
            'connected' => $probe['connected'],
            'authenticated' => $probe['authenticated'],
            'error' => $probe['error'],
        ];
    }

    /**
     * @return array{ssh: ?SSH2, connected: bool, authenticated: bool, error: ?string}
     */
    private function connectAndAuthenticate(ServerConnection $connection, string $plaintextCredential): array
    {
        try {
            $ssh = new SSH2((string) $connection->host, (int) $connection->port, 10);
        } catch (\Throwable $e) {
            return ['ssh' => null, 'connected' => false, 'authenticated' => false, 'error' => $e->getMessage()];
        }

        try {
            if ($connection->auth_method === ServerConnection::AUTH_METHOD_KEY) {
                $key = PublicKeyLoader::load($plaintextCredential);
                $authenticated = (bool) $ssh->login((string) $connection->ssh_username, $key);
            } else {
                $authenticated = (bool) $ssh->login((string) $connection->ssh_username, $plaintextCredential);
            }
        } catch (\Throwable $e) {
            return ['ssh' => null, 'connected' => true, 'authenticated' => false, 'error' => $e->getMessage()];
        }

        if (! $authenticated) {
            return ['ssh' => null, 'connected' => true, 'authenticated' => false, 'error' => 'Authentication failed'];
        }

        return ['ssh' => $ssh, 'connected' => true, 'authenticated' => true, 'error' => null];
    }
}
