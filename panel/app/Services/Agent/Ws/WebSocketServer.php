<?php

declare(strict_types=1);

namespace App\Services\Agent\Ws;

/**
 * Минимальный WebSocket-сервер (RFC 6455) без внешних зависимостей.
 *
 * Работает в отдельном процессе (`php artisan gamedock:ws-server`):
 *   php artisan gamedock:ws-server --host=0.0.0.0 --port=9222
 *
 * Нужен, потому что PHP-FPM не умеет держать постоянные соединения, а агенты
 * игровых нод должны иметь постоянный канал: живая консоль, поток статусов,
 * метрики и мгновенные команды.
 *
 * Поддерживается: text/binary, ping/pong, close, фрагментация, permessage-deflate
 * выключен намеренно (экономим CPU на потоке логов).
 */
class WebSocketServer
{
    public const OP_CONTINUATION = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_BINARY = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> fd => stream */
    private array $clients = [];

    /** @var array<int, string> fd => raw handshake buffer */
    private array $handshakes = [];

    /** @var array<int, array> fd => { buffer, opcode, closing, close_code, last_activity } */
    private array $state = [];

    /** @var callable(int, string, array): void */
    private $onMessage = null;

    /** @var callable(int): void */
    private $onOpen = null;

    /** @var callable(int, int, string): void */
    private $onClose = null;

    private bool $running = false;

    private float $lastPing = 0.0;

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 9222,
        private readonly int $handshakeTimeout = 10,
        private readonly int $pingInterval = 20,
        private readonly int $maxPayload = 8 * 1024 * 1024,
    ) {}

    public function onOpen(callable $callback): void
    {
        $this->onOpen = $callback;
    }

    public function onMessage(callable $callback): void
    {
        $this->onMessage = $callback;
    }

    public function onClose(callable $callback): void
    {
        $this->onClose = $callback;
    }

    public function listen(): void
    {
        $context = stream_context_create([
            'socket' => ['backlog' => 128, 'so_reuseaddr' => true],
        ]);

        $address = 'tcp://'.$this->host.':'.$this->port;
        $server = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if ($server === false) {
            throw new \RuntimeException("Не удалось запустить WSS-сервер на {$address}: {$errstr} ({$errno})");
        }

        stream_set_blocking($server, false);
        $this->server = $server;
    }

    public function run(?callable $tick = null): void
    {
        if ($this->server === null) {
            $this->listen();
        }

        $this->running = true;

        while ($this->running) {
            $read = $this->clients;
            $read[] = $this->server;
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, 0, 200000);

            if ($ready === false) {
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $this->server) {
                    $this->acceptClient();

                    continue;
                }

                $fd = (int) $stream;
                $this->readClient($fd, $stream);
            }

            if ($tick !== null) {
                $tick();
            }

            $this->maybePing();
            $this->gc();
        }

        $this->closeAll();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    // ── Приём подключений ───────────────────────────────────────────────

    private function acceptClient(): void
    {
        $stream = @stream_socket_accept($this->server, 0, $peer);

        if ($stream === false) {
            return;
        }

        stream_set_blocking($stream, false);
        $fd = (int) $stream;

        $this->clients[$fd] = $stream;
        $this->handshakes[$fd] = '';
        $this->state[$fd] = [
            'buffer' => '',
            'opcode' => null,
            'closing' => false,
            'close_code' => null,
            'last_activity' => time(),
        ];
    }

    private function readClient(int $fd, $stream): void
    {
        $chunk = @fread($stream, 65536);

        if ($chunk === false || ($chunk === '' && feof($stream))) {
            $this->closeClient($fd, 1006, 'connection reset');

            return;
        }

        if ($chunk === '') {
            return;
        }

        $this->state[$fd]['last_activity'] = time();

        if (isset($this->handshakes[$fd])) {
            $this->handshakes[$fd] .= $chunk;
            $this->processHandshake($fd, $stream);

            return;
        }

        $this->state[$fd]['buffer'] .= $chunk;

        $this->processFrames($fd, $stream);
    }

    // ── Handshake ────────────────────────────────────────────────────────

    private function processHandshake(int $fd, $stream): void
    {
        $raw = $this->handshakes[$fd];

        if (! str_contains($raw, "\r\n\r\n")) {
            if (strlen($raw) > 16384) {
                $this->closeClient($fd, 1002, 'handshake too large');
            }

            return;
        }

        [$head, $rest] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines) ?? '';

        if (! preg_match('#^GET\s+(\S+)\s+HTTP/1\.1$#', $requestLine, $m)) {
            $this->writeRaw($stream, "HTTP/1.1 400 Bad Request\r\n\r\n");
            $this->closeClient($fd, 1002, 'bad request');

            return;
        }

        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        $key = $headers['sec-websocket-key'] ?? '';
        $upgrade = strtolower($headers['upgrade'] ?? '');
        $version = $headers['sec-websocket-version'] ?? '';

        if ($key === '' || $upgrade !== 'websocket' || $version !== '13') {
            $this->writeRaw($stream, "HTTP/1.1 400 Bad Request\r\n\r\n");
            $this->closeClient($fd, 1002, 'bad websocket handshake');

            return;
        }

        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Accept: {$accept}\r\n";

        // Передаём аутентификацию агенту через заголовок (панель проверяет токен)
        if (isset($headers['x-gamedock-token'])) {
            $response .= "X-GameDock-Token: {$headers['x-gamedock-token']}\r\n";
        }

        $response .= "\r\n";

        $this->writeRaw($stream, $response);
        unset($this->handshakes[$fd]);

        if ($rest !== '') {
            $this->state[$fd]['buffer'] = $rest;
        }

        if ($this->onOpen !== null) {
            ($this->onOpen)($fd, [
                'path' => $m[1],
                'headers' => $headers,
                'ip' => stream_socket_get_name($stream, true),
            ]);
        }
    }

    // ── Кадры ───────────────────────────────────────────────────────────

    private function processFrames(int $fd, $stream): void
    {
        // Ограничиваем размер накопленного буфера
        if (strlen($this->state[$fd]['buffer']) > $this->maxPayload) {
            $this->closeClient($fd, 1009, 'message too big');

            return;
        }

        while (true) {
            $frame = $this->readFrame($fd, $stream);

            if ($frame === null) {
                return;
            }

            if ($frame['opcode'] === self::OP_CLOSE) {
                $this->sendClose($fd, $stream, 1000, '');
                $this->closeClient($fd, 1000, 'closed by peer');

                return;
            }

            if ($frame['opcode'] === self::OP_PING) {
                $this->writeFrame($stream, self::OP_PONG, $frame['payload']);

                continue;
            }

            if ($frame['opcode'] === self::OP_PONG) {
                continue;
            }

            $this->state[$fd]['opcode'] = $frame['opcode'] === self::OP_CONTINUATION
                ? $this->state[$fd]['opcode']
                : $frame['opcode'];

            $this->state[$fd]['buffer'] .= $frame['payload'];

            if ($frame['fin']) {
                $payload = $this->state[$fd]['buffer'];
                $this->state[$fd]['buffer'] = '';
                $opcode = $this->state[$fd]['opcode'] ?? self::OP_TEXT;
                $this->state[$fd]['opcode'] = null;

                if ($opcode === self::OP_TEXT && $this->onMessage !== null) {
                    ($this->onMessage)($fd, $payload);
                }
            }
        }
    }

    /** @return array{fin: bool, opcode: int, payload: string}|null */
    private function readFrame(int $fd, $stream): ?array
    {
        $buffer = $this->state[$fd]['buffer'];

        if (strlen($buffer) < 2) {
            return null;
        }

        $byte0 = ord($buffer[0]);
        $byte1 = ord($buffer[1]);

        $fin = (bool) ($byte0 & 0x80);
        $opcode = $byte0 & 0x0F;
        $masked = (bool) ($byte1 & 0x80);
        $length = $byte1 & 0x7F;

        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if ($length > $this->maxPayload) {
            $this->closeClient($fd, 1009, 'frame too large');

            return null;
        }

        $maskKey = '';
        if ($masked) {
            if (strlen($buffer) < $offset + 4) {
                return null;
            }
            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if (strlen($buffer) < $offset + $length) {
            return null;
        }

        $payload = substr($buffer, $offset, $length);

        if ($masked && $maskKey !== '') {
            $unmasked = '';
            for ($i = 0; $i < $length; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }

        $this->state[$fd]['buffer'] = substr($buffer, $offset + $length);

        return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
    }

    public function send(int $fd, string $data, bool $binary = false): bool
    {
        if (! isset($this->clients[$fd])) {
            return false;
        }

        $ok = $this->writeFrame($this->clients[$fd], $binary ? self::OP_BINARY : self::OP_TEXT, $data);

        if (! $ok) {
            $this->closeClient($fd, 1006, 'write failed');
        }

        return $ok;
    }

    public function sendJson(int $fd, array $payload): bool
    {
        return $this->send($fd, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function writeFrame($stream, int $opcode, string $payload): bool
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);

        if ($length < 126) {
            $header .= chr($length);
        } elseif ($length < 65536) {
            $header .= chr(126).pack('n', $length);
        } else {
            $header .= chr(127).pack('J', $length);
        }

        return $this->writeRaw($stream, $header.$payload);
    }

    private function sendClose(int $fd, $stream, int $code, string $reason): void
    {
        $payload = pack('n', $code).$reason;
        $this->writeFrame($stream, self::OP_CLOSE, $payload);
    }

    private function writeRaw($stream, string $data): bool
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $bytes = @fwrite($stream, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                return false;
            }
            $written += $bytes;
        }

        return true;
    }

    // ── Обслуживание ─────────────────────────────────────────────────────

    private function maybePing(): void
    {
        if ($this->clients === []) {
            return;
        }

        if (time() - $this->lastPing < $this->pingInterval) {
            return;
        }

        $this->lastPing = time();

        foreach ($this->clients as $fd => $stream) {
            $this->writeFrame($stream, self::OP_PING, '');
        }
    }

    private function gc(): void
    {
        $now = time();

        foreach ($this->state as $fd => $state) {
            if ($now - $state['last_activity'] > 300) {
                $this->closeClient($fd, 1001, 'idle timeout');
            }
        }
    }

    public function closeClient(int $fd, int $code = 1000, string $reason = ''): void
    {
        if (! isset($this->clients[$fd])) {
            return;
        }

        if ($this->onClose !== null) {
            ($this->onClose)($fd, $code, $reason);
        }

        if (isset($this->clients[$fd])) {
            @fclose($this->clients[$fd]);
        }

        unset($this->clients[$fd], $this->handshakes[$fd], $this->state[$fd]);
    }

    private function closeAll(): void
    {
        foreach (array_keys($this->clients) as $fd) {
            $this->closeClient($fd, 1001, 'server shutdown');
        }

        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }
    }

    /** @return array<int, array{ip: string, handshake_at: int}> */
    public function stats(): array
    {
        $out = [];
        foreach ($this->state as $fd => $s) {
            $out[$fd] = [
                'ip' => (string) (stream_socket_get_name($this->clients[$fd], true) ?: ''),
                'handshake_at' => $s['last_activity'],
            ];
        }

        return $out;
    }

    public function clientCount(): int
    {
        return count($this->clients);
    }
}
