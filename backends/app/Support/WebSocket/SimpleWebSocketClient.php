<?php

namespace App\Support\WebSocket;

use RuntimeException;

class SimpleWebSocketClient
{
    /** @var resource|null */
    private $socket = null;

    public function connect(string $wsUrl, int $timeoutSeconds = 20): void
    {
        $parts = parse_url($wsUrl);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('Invalid websocket URL: ' . $wsUrl);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'wss'));
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'wss' ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $target = sprintf('%s://%s:%d', $scheme === 'wss' ? 'tls' : 'tcp', $host, $port);

        $socket = @stream_socket_client($target, $errno, $errstr, $timeoutSeconds, STREAM_CLIENT_CONNECT);
        if (! $socket) {
            throw new RuntimeException("WebSocket connect failed ({$errno}): {$errstr}");
        }

        // Keep blocking mode — stream_select() handles read timeouts in readBytes().
        // Non-blocking mode caused fread() to return '' on SSL internal frames even
        // after stream_select() reported the socket as readable, triggering false
        // "connection closed" exceptions every 3-4 seconds on wss:// streams.

        $key = base64_encode(random_bytes(16));
        $headers = "GET {$path}{$query} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($socket, $headers);

        // Read HTTP 101 response using stream_select so we respect the deadline
        // even in blocking mode (avoids hanging if the server never responds).
        $response = '';
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline && ! str_contains($response, "\r\n\r\n")) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $read    = [$socket];
            $write   = null;
            $except  = null;
            $sec     = (int) floor($remaining);
            $usec    = (int) max(0, ($remaining - $sec) * 1_000_000);
            $ready   = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                continue;
            }
            $chunk = fread($socket, 4096);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            $response .= $chunk;
        }

        if (! str_contains($response, ' 101 ') && ! str_contains($response, ' 101\r')) {
            fclose($socket);
            throw new RuntimeException('WebSocket handshake failed: ' . trim($response));
        }

        $this->socket = $socket;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket);
    }

    /** Send a PING frame to keep the connection alive (opcode 0x9). */
    public function sendPing(string $payload = ''): void
    {
        if (! is_resource($this->socket)) {
            return;
        }
        $this->sendFrame($payload, 0x9);
    }

    public function readFrame(int $timeoutSeconds = 1): ?array
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('WebSocket is not connected.');
        }

        // Short timeout for the 2-byte header — if no data arrives in $timeoutSeconds,
        // return null (normal idle poll).  Once the header is received we know a full
        // frame is coming, so use a longer deadline for the payload so that large Binance
        // messages (deep order-book snapshots, multi-symbol combined streams) on a slow
        // link don't throw a false "Incomplete websocket frame" and cause a reconnect.
        $payloadTimeout = max($timeoutSeconds, 8);

        $header = $this->readBytes(2, $timeoutSeconds);
        if ($header === null) {
            return null;
        }

        $byte1 = ord($header[0]);
        $byte2 = ord($header[1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $length = $byte2 & 0x7F;

        if ($length === 126) {
            $extended = $this->readBytes(2, $payloadTimeout);
            if ($extended === null) {
                return null;
            }
            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            $extended = $this->readBytes(8, $payloadTimeout);
            if ($extended === null) {
                return null;
            }
            $parts = unpack('N2', $extended);
            $length = ((int) $parts[1] << 32) + (int) $parts[2];
        }

        $mask = $masked ? $this->readBytes(4, $payloadTimeout) : null;
        if ($masked && $mask === null) {
            return null;
        }

        $payload = $length > 0 ? $this->readBytes($length, $payloadTimeout) : '';
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

        if ($opcode === 0x9) {
            $this->sendFrame((string) $payload, 0xA);

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

    private function sendFrame(string $payload, int $opcode = 0x1): void
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('WebSocket is not connected.');
        }

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

        fwrite($this->socket, $header . $mask . $maskedPayload);
    }

    private function readBytes(int $length, int $timeoutSeconds): ?string
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('WebSocket is not connected.');
        }

        $buffer = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (strlen($buffer) < $length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                if ($buffer === '') {
                    return null;
                }

                throw new RuntimeException('Incomplete websocket frame received.');
            }

            $read = [$this->socket];
            $write = null;
            $except = null;
            $sec = (int) floor($remaining);
            $usec = (int) max(0, ($remaining - $sec) * 1_000_000);
            $selected = @stream_select($read, $write, $except, $sec, $usec);
            if ($selected === false) {
                throw new RuntimeException('stream_select failed while reading websocket frame.');
            }
            if ($selected === 0) {
                continue;
            }

            $chunk = fread($this->socket, $length - strlen($buffer));

            if ($chunk === false) {
                throw new RuntimeException('WebSocket read error (fread returned false).');
            }

            if ($chunk === '') {
                // On TLS/SSL sockets (wss://) stream_select can report the socket as
                // readable for an internal TLS control frame while fread() returns ''
                // because there is no application-layer data yet.  Treat this as a
                // transient SSL buffering event — only throw if we are truly at EOF.
                if (feof($this->socket)) {
                    throw new RuntimeException('WebSocket connection closed while reading frame.');
                }
                usleep(500);
                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
