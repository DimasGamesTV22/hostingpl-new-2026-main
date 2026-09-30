<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Node;
use App\Services\Agent\Ws\WebSocketServer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Соединение с агентом одной ноды.
 *
 * Живёт внутри процесса WSS-сервера. Команды от HTTP-запросов и воркеров
 * приходят через Redis-список (см. AgentClient) — так панель может запускать
 * произвольное количество PHP-процессов, а сокеты держат только один.
 */
class AgentConnection
{
    /** @var array<string, array> rid => resolver */
    private array $pending = [];

    /** @var array<int, array> буфер исходящих (накопление между тиками) */
    private array $outbox = [];

    /** @var array<string, callable> события от агента */
    private array $listeners = [];

    /** @var array<string, float> rid => время отправки (для таймаутов) */
    private array $pendingStartedAt = [];

    private bool $alive = true;

    private string $lastCloseReason = '';

    public function __construct(
        public readonly int $fd,
        public readonly Node $node,
        private readonly WebSocketServer $server,
        private readonly string $secret,
    ) {
        $this->listen('hello', function (array $msg) {
            $this->applyHello($msg);
        });

        $this->listen('heartbeat', function (array $msg) {
            $this->applyHeartbeat($msg);
        });
    }

    public function isOpen(): bool
    {
        return $this->alive;
    }

    public function close(string $reason = 'closed'): void
    {
        $this->alive = false;
        $this->lastCloseReason = $reason;
        $this->server->closeClient($this->fd, 1000, $reason);
    }

    public function closeReason(): string
    {
        return $this->lastCloseReason;
    }

    // ── Слушатели событий ───────────────────────────────────────────────

    public function listen(string $event, callable $handler): void
    {
        $this->listeners[$event][] = $handler;
    }

    // ── Отправка ────────────────────────────────────────────────────────

    public function send(array $message): void
    {
        $this->outbox[] = $message;
    }

    /** Отправить немедленно, минуя буфер. */
    public function sendNow(array $message): bool
    {
        return $this->server->sendJson($this->fd, $this->sign($message));
    }

    /** Слить буфер в сокет. Вызывается тиком WSS-сервера. */
    public function flush(): void
    {
        if ($this->outbox === []) {
            return;
        }

        $pending = $this->outbox;
        $this->outbox = [];

        foreach ($pending as $message) {
            $this->sendNow($message);
        }
    }

    /**
     * Отправить RPC и зарегистрировать обработчик ответа.
     *
     * Вызывается только из процесса WSS-сервера (здесь доступен сокет).
     * Ответ придёт позже событием `rpc:<type>` — в AgentConnection::handleRaw
     * он резолвится через $this->pending и вызывает $callback.
     *
     * @param  array<string, mixed>  $payload
     */
    public function request(string $type, array $payload = [], ?callable $onResponse = null): string
    {
        $rid = (string) Str::uuid();

        if ($onResponse !== null) {
            $this->pending[$rid] = $onResponse;
            $this->pendingStartedAt[$rid] = microtime(true);
        }

        $this->sendNow([
            'rid' => $rid,
            'type' => $type,
            'payload' => $payload,
            'ts' => now()->timestamp,
        ]);

        return $rid;
    }

    /**
     * Есть ли незавершённые RPC — используется health-чеком и дебагом.
     */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /** Проверить таймауты незавершённых запросов. */
    public function expireStale(float $timeout): void
    {
        if ($this->pending === []) {
            return;
        }

        foreach (array_keys($this->pending) as $rid) {
            $startedAt = $this->pendingStartedAt[$rid] ?? microtime(true);

            if (microtime(true) - $startedAt > $timeout) {
                $callback = $this->pending[$rid];
                unset($this->pending[$rid], $this->pendingStartedAt[$rid]);
                $callback(['ok' => false, 'error' => 'timeout']);
            }
        }
    }

    // ── Входящие ────────────────────────────────────────────────────────

    public function handleRaw(string $raw): void
    {
        $message = json_decode($raw, true);

        if (! is_array($message)) {
            return;
        }

        // Проверка подписи для важных сообщений
        if (isset($message['sig']) && ! $this->verifySignature($message)) {
            Log::warning('Agent: некорректная подпись сообщения', ['node' => $this->node->id]);
            $this->close('bad signature');

            return;
        }

        if (isset($message['rid'])) {
            $rid = (string) $message['rid'];
            if (isset($this->pending[$rid])) {
                $resolver = $this->pending[$rid];
                unset($this->pending[$rid], $this->pendingStartedAt[$rid]);
                $resolver($message);
            }
        }

        $event = (string) ($message['event'] ?? '');

        if ($event !== '' && isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $handler) {
                try {
                    $handler((array) ($message['data'] ?? []), $message);
                } catch (\Throwable $e) {
                    Log::error('Agent: ошибка обработчика события '.$event, [
                        'node' => $this->node->id,
                        'exception' => $e,
                    ]);
                }
            }
        }
    }

    // ── Обработчики служебных событий ────────────────────────────────────

    private function applyHello(array $data): void
    {
        $this->node->forceFill([
            'status' => Node::STATUS_ONLINE,
            'agent_version' => $data['version'] ?? null,
            'agent_info' => $data['system'] ?? null,
            'runtimes_available' => $data['runtimes'] ?? null,
            'last_heartbeat_at' => now(),
            'missed_heartbeats' => 0,
        ])->save();

        Log::info('Agent: нода подключена', [
            'node' => $this->node->name,
            'version' => $data['version'] ?? '?',
            'runtimes' => $data['runtimes'] ?? [],
        ]);
    }

    private function applyHeartbeat(array $data): void
    {
        $system = $data['system'] ?? [];

        $this->node->forceFill([
            'status' => Node::STATUS_ONLINE,
            'last_heartbeat_at' => now(),
            'missed_heartbeats' => 0,
            'cpu_cores' => $system['cpu_cores'] ?? $this->node->cpu_cores,
            'cpu_threads' => $system['cpu_threads'] ?? $this->node->cpu_threads,
            'cpu_model' => $system['cpu_model'] ?? $this->node->cpu_model,
            'memory_total_mb' => $system['memory_total_mb'] ?? $this->node->memory_total_mb,
            'disk_total_mb' => $system['disk_total_mb'] ?? $this->node->disk_total_mb,
            'disk_free_mb' => $system['disk_free_mb'] ?? $this->node->disk_free_mb,
            'used_memory_mb' => $system['used_memory_mb'] ?? $this->node->used_memory_mb,
            'used_disk_mb' => $system['used_disk_mb'] ?? $this->node->used_disk_mb,
            'running_servers' => $data['running_servers'] ?? $this->node->running_servers,
            'total_servers' => $data['total_servers'] ?? $this->node->total_servers,
            'load_1' => $system['load_1'] ?? $this->node->load_1,
            'load_5' => $system['load_5'] ?? $this->node->load_5,
            'load_15' => $system['load_15'] ?? $this->node->load_15,
        ])->save();
    }

    // ── Подпись HMAC ────────────────────────────────────────────────────

    private function sign(array $message): array
    {
        $ts = now()->timestamp;
        $message['ts'] ??= $ts;
        $message['nonce'] ??= bin2hex(random_bytes(8));

        $signature = hash_hmac('sha256', $this->signaturePayload($message), $this->secret);
        $message['sig'] = $signature;

        return $message;
    }

    private function signaturePayload(array $message): string
    {
        unset($message['sig']);

        ksort($message);

        return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function verifySignature(array $message): bool
    {
        if (empty($this->secret)) {
            return true;
        }

        $provided = (string) ($message['sig'] ?? '');
        $expected = hash_hmac('sha256', $this->signaturePayload($message), $this->secret);

        return hash_equals($expected, $provided);
    }

    // ── Redis-мост для команд из других процессов ───────────────────────

    public function redisQueue(): string
    {
        return 'gamedock:agent:out:'.$this->node->id;
    }

    public function popInbound(): array
    {
        try {
            $raw = Redis::connection('default')->lpop($this->redisQueue(), 20);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        return $raw;
    }
}
