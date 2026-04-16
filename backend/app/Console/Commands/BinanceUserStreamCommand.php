<?php

namespace App\Console\Commands;

use App\Domain\Exchange\BinanceUserStreamService;
use App\Domain\Trading\ReconciliationService;
use App\Models\ExchangeConnection;
use Illuminate\Console\Command;

class BinanceUserStreamCommand extends Command
{
    protected $signature = 'trading:binance-user-stream
        {--workspace= : Workspace UUID}
        {--connection= : Exchange connection UUID}
        {--max-runtime=0 : Max runtime in seconds (0 = infinite)}
        {--reconnect-sleep=3 : Reconnect delay in seconds}
        {--stop-on-exit : Close listen key on command exit}';

    protected $description = 'Consume Binance user stream websocket and reconcile execution events.';

    public function handle(BinanceUserStreamService $streams, ReconciliationService $reconciliation): int
    {
        $connection = $this->resolveConnection();
        if (! $connection) {
            $this->error('No active Binance connection found for given options.');
            return self::FAILURE;
        }

        $maxRuntime = max(0, (int) $this->option('max-runtime'));
        $reconnectSleep = max(1, (int) $this->option('reconnect-sleep'));
        $stopOnExit = (bool) $this->option('stop-on-exit');
        $startedAt = time();
        $keepAliveEverySec = max(60, (int) env('BINANCE_USER_STREAM_KEEPALIVE_SEC', 1800));

        $this->info(sprintf(
            'Starting Binance user-stream consumer (workspace=%s connection=%s)',
            $connection->workspace_id,
            $connection->id
        ));

        try {
            while (true) {
                if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                    $this->info('Max runtime reached, stopping user-stream consumer.');
                    break;
                }

                try {
                    $stream = $streams->getOrStart($connection);
                    $wsUrl = (string) ($stream['ws_url'] ?? '');
                    if ($wsUrl === '') {
                        throw new \RuntimeException('Missing ws_url from user stream bootstrap.');
                    }

                    $socket = $this->connectWebSocket($wsUrl);
                    $this->line('Connected to Binance user stream.');
                    $lastKeepAlive = time();

                    while (true) {
                        if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                            fclose($socket);
                            break 2;
                        }

                        if ((time() - $lastKeepAlive) >= $keepAliveEverySec) {
                            $streams->keepAlive($connection);
                            $lastKeepAlive = time();
                        }

                        $frame = $this->readFrame($socket, 1);
                        if ($frame === null) {
                            continue;
                        }

                        if ($frame['type'] === 'close') {
                            $streams->markError($connection, 'Remote websocket closed the user stream.');
                            $this->warn('User stream socket closed by remote, reconnecting...');
                            fclose($socket);
                            break;
                        }
                        if ($frame['type'] !== 'text') {
                            continue;
                        }

                        $payload = json_decode((string) $frame['payload'], true);
                        if (! is_array($payload)) {
                            continue;
                        }

                        $streams->markFrameActivity($connection, [
                            'source' => 'websocket',
                            'event_type' => (string) ($payload['e'] ?? $payload['event'] ?? $payload['eventType'] ?? ''),
                        ]);

                        $order = $reconciliation->ingestExchangeEvent($payload);
                        if ($order) {
                            $this->line(sprintf(
                                'Reconciled order %s => %s',
                                (string) $order->id,
                                (string) $order->status
                            ));
                        }
                    }
                } catch (\Throwable $e) {
                    $streams->markError($connection, $e->getMessage());
                    $this->warn('User stream error: ' . $e->getMessage());
                }

                sleep($reconnectSleep);
            }
        } finally {
            if ($stopOnExit) {
                try {
                    $streams->stop($connection);
                } catch (\Throwable) {
                    // ignore cleanup failure
                }
            }
        }

        return self::SUCCESS;
    }

    private function resolveConnection(): ?ExchangeConnection
    {
        $connectionId = (string) $this->option('connection');
        if ($connectionId !== '') {
            return ExchangeConnection::where('id', $connectionId)
                ->where('provider', 'binance')
                ->where('is_active', true)
                ->first();
        }

        $workspaceId = (string) $this->option('workspace');
        $query = ExchangeConnection::where('provider', 'binance')
            ->where('is_active', true)
            ->orderByDesc('created_at');
        if ($workspaceId !== '') {
            $query->where('workspace_id', $workspaceId);
        }

        return $query->first();
    }

    /**
     * @return resource
     */
    private function connectWebSocket(string $wsUrl)
    {
        $parts = parse_url($wsUrl);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new \RuntimeException('Invalid websocket URL: ' . $wsUrl);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'wss'));
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'wss' ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $target = sprintf('%s://%s:%d', $scheme === 'wss' ? 'tls' : 'tcp', $host, $port);

        $socket = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (! $socket) {
            throw new \RuntimeException("WebSocket connect failed ({$errno}): {$errstr}");
        }

        stream_set_timeout($socket, 1);
        stream_set_blocking($socket, false);

        $key = base64_encode(random_bytes(16));
        $headers = "GET {$path}{$query} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($socket, $headers);
        $response = '';
        $deadline = time() + 8;
        while (time() < $deadline && ! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($socket, 1024);
            if ($chunk === false || $chunk === '') {
                usleep(50_000);
                continue;
            }
            $response .= $chunk;
        }

        if (! str_contains($response, ' 101 ') && ! str_contains($response, ' 101\r')) {
            fclose($socket);
            throw new \RuntimeException('WebSocket handshake failed: ' . trim($response));
        }

        return $socket;
    }

    private function readFrame($socket, int $timeoutSec = 1): ?array
    {
        $header = $this->readBytes($socket, 2, $timeoutSec);
        if ($header === null) {
            return null;
        }

        $byte1 = ord($header[0]);
        $byte2 = ord($header[1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $length = $byte2 & 0x7F;

        if ($length === 126) {
            $extended = $this->readBytes($socket, 2, $timeoutSec);
            if ($extended === null) {
                return null;
            }
            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            $extended = $this->readBytes($socket, 8, $timeoutSec);
            if ($extended === null) {
                return null;
            }
            $parts = unpack('N2', $extended);
            $length = ((int) $parts[1] << 32) + (int) $parts[2];
        }

        $mask = $masked ? $this->readBytes($socket, 4, $timeoutSec) : null;
        if ($masked && $mask === null) {
            return null;
        }

        $payload = $length > 0 ? $this->readBytes($socket, $length, $timeoutSec) : '';
        if ($payload === null) {
            return null;
        }

        if ($masked && $mask !== null) {
            $decoded = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        if ($opcode === 0x9) { // ping
            $this->sendFrame($socket, (string) $payload, 0xA);
            return ['type' => 'ping'];
        }
        if ($opcode === 0x8) {
            return ['type' => 'close'];
        }
        if ($opcode === 0x1) {
            return ['type' => 'text', 'payload' => $payload];
        }

        return ['type' => 'other'];
    }

    private function sendFrame($socket, string $payload, int $opcode = 0x1): void
    {
        $finAndOpcode = 0x80 | ($opcode & 0x0F);
        $length = strlen($payload);
        $mask = random_bytes(4);
        $maskedPayload = '';
        for ($i = 0; $i < $length; $i++) {
            $maskedPayload .= $payload[$i] ^ $mask[$i % 4];
        }

        if ($length <= 125) {
            $header = pack('CC', $finAndOpcode, 0x80 | $length);
        } elseif ($length <= 65535) {
            $header = pack('CCn', $finAndOpcode, 0x80 | 126, $length);
        } else {
            $header = pack('CCNN', $finAndOpcode, 0x80 | 127, 0, $length);
        }

        fwrite($socket, $header . $mask . $maskedPayload);
    }

    private function readBytes($socket, int $length, int $timeoutSec): ?string
    {
        $buffer = '';
        $deadline = microtime(true) + $timeoutSec;

        while (strlen($buffer) < $length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                if ($buffer === '') {
                    return null;
                }
                throw new \RuntimeException('Incomplete websocket frame received.');
            }

            $read = [$socket];
            $write = null;
            $except = null;
            $sec = (int) floor($remaining);
            $usec = (int) max(0, ($remaining - $sec) * 1_000_000);
            $selected = @stream_select($read, $write, $except, $sec, $usec);
            if ($selected === false) {
                throw new \RuntimeException('stream_select failed while reading websocket frame.');
            }
            if ($selected === 0) {
                continue;
            }

            $chunk = fread($socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('WebSocket connection closed while reading frame.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
