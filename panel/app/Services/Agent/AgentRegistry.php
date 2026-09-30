<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Node;
use App\Support\Crypto;
use Illuminate\Support\Facades\Log;

/**
 * Реестр активных WSS-соединений панель <-> агент.
 *
 * Транспорт один из двух (config/hosting.php → agent.inbound):
 *  - inbound  — агент сам подключается к панели; здесь держим сокеты
 *  - outbound — панель подключается к агенту; здесь держим клиентов
 */
class AgentRegistry
{
    /** @var array<int, AgentConnection> node_id => connection */
    private array $connections = [];

    public function add(Node $node, AgentConnection $connection): void
    {
        $this->connections[$node->id] = $connection;
    }

    public function remove(int $nodeId): void
    {
        unset($this->connections[$nodeId]);
    }

    public function get(int $nodeId): ?AgentConnection
    {
        return $this->connections[$nodeId] ?? null;
    }

    public function has(int $nodeId): bool
    {
        return isset($this->connections[$nodeId]) && $this->connections[$nodeId]->isOpen();
    }

    /** @return array<int, AgentConnection> */
    public function all(): array
    {
        return $this->connections;
    }

    public function node(?int $nodeId): ?Node
    {
        return $nodeId ? Node::find($nodeId) : null;
    }

    /** Аутентификация входящего подключения агента по токену. */
    public function authenticateAgent(string $token): ?Node
    {
        $hash = hash('sha256', $token);

        $node = Node::where('token_hash', $hash)->first();

        if (! $node) {
            Log::warning('Agent: неизвестный токен при подключении', ['hash' => substr($hash, 0, 12)]);
            return null;
        }

        if (! $node->is_active) {
            return null;
        }

        // Одна нода — одно соединение: рвём предыдущее
        if ($this->has($node->id)) {
            $this->connections[$node->id]->close('replaced');
        }

        $node->forceFill([
            'inbound_ip' => request()->ip(),
            'last_heartbeat_at' => now(),
            'missed_heartbeats' => 0,
        ])->save();

        return $node;
    }

    /** Расшифровать секрет ноды (общий ключ HMAC). */
    public function sharedSecret(Node $node): ?string
    {
        $token = $node->plainToken() ?: Crypto::decrypt($node->token);

        return $token;
    }
}
