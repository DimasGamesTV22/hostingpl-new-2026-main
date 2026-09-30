<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentRegistry;
use App\Support\Crypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Управление нодами игровых серверов.
 */
class NodeManager
{
    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly AgentClient $agent,
        private readonly PortAllocator $ports,
        private readonly NodeScheduler $scheduler,
    ) {}

    public function create(array $data): Node
    {
        $node = new Node($this->normalize($data));

        $node->uuid ??= (string) Str::uuid();

        // Токен агента генерируем сами, чтобы админ не придумывал
        $node->setTokenAttribute($data['token'] ?? Str::random(48));
        $node->save();

        return $node->refresh();
    }

    public function update(Node $node, array $data): Node
    {
        $node->fill($this->normalize($data));

        if (! empty($data['token'])) {
            $node->setTokenAttribute($data['token']);
        }

        $node->save();

        return $node->refresh();
    }

    public function delete(Node $node): bool
    {
        if ($node->servers()->exists()) {
            throw new \RuntimeException(__('nodes.errors.has_servers'));
        }

        $this->ports->nodePoolUsage($node);

        \App\Models\ResourceAllocation::where('node_id', $node->id)->delete();

        return $node->delete();
    }

    public function rotateToken(Node $node): string
    {
        return $node->rotateToken();
    }

    private function normalize(array $data): array
    {
        $fields = [
            'name', 'slug', 'description', 'connection_mode', 'host', 'agent_port',
            'tls', 'country', 'city', 'region', 'continent', 'latitude', 'longitude',
            'timezone', 'flagship', 'banner', 'runtime', 'runtime_options',
            'max_servers', 'max_memory_mb', 'max_disk_mb', 'max_cpu_percent',
            'allocatable_percent', 'reserved_memory_mb',
            'status', 'weight', 'region_priority', 'prefer_over_region',
            'is_default', 'is_active', 'allow_ssh_fallback',
        ];

        $out = array_intersect_key($data, array_flip($fields));

        if (isset($out['name']) && blank($data['slug'] ?? null)) {
            $out['slug'] = Str::slug($out['name']);
        }

        // Уникальность slug
        if (! empty($out['slug'])) {
            $base = $out['slug'];
            $slug = $base;
            $i = 1;

            while (Node::where('slug', $slug)->exists()) {
                $slug = $base.'-'.(++$i);
            }

            $out['slug'] = $slug;
        }

        return $out;
    }

    /**
     * Синхронизация статусов и поддерживаемых рантаймов.
     */
    public function syncRuntimes(): int
    {
        $changed = 0;

        Node::query()->each(function (Node $node) {
            $node->refreshStatus();
            $changed++;
        });

        return $changed;
    }

    /**
     * Проверка связи с нодой (ping).
     */
    public function ping(Node $node): array
    {
        return $this->agent->ping($node);
    }

    /**
     * Импорт портов в пул (для отображения занятости в админке).
     */
    public function seedPorts(Node $node, int $limit = 2000): int
    {
        return $this->ports->seedPool($node, $limit);
    }

    /**
     * Полная информация о ноде для страницы админки.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(Node $node): array
    {
        $pool = $this->ports->nodePoolUsage($node);

        return [
            'status' => $node->status,
            'status_label' => $node->statusLabel(),
            'online' => $node->isOnline(),
            'in_registry' => $this->registry->has($node->id),
            'heartbeat_age' => $node->heartbeatAgeSeconds(),
            'agent_version' => $node->agent_version,
            'runtimes' => $node->runtimes(),
            'connection_mode' => $node->connection_mode,
            'queue_size' => $this->agent->queueSize($node->id),
            'memory' => [
                'total' => $node->memory_total_mb,
                'allocatable' => $node->allocatableMemoryMb(),
                'used' => $node->used_memory_mb,
                'free' => $node->freeMemoryMb(),
                'percent' => $node->memoryUsagePercent(),
            ],
            'disk' => [
                'total' => $node->disk_total_mb,
                'allocatable' => $node->allocatableDiskMb(),
                'used' => $node->used_disk_mb,
                'free' => $node->freeDiskMb(),
                'percent' => $node->diskUsagePercent(),
            ],
            'servers' => [
                'total' => $node->total_servers,
                'running' => $node->running_servers,
                'limit' => $node->max_servers,
            ],
            'ports' => $pool,
            'uptime_percent' => $node->uptimePercent(1),
        ];
    }

    /**
     * Найти ноду по имени или ID (для API и CLI).
     */
    public function resolve(string|int $identifier): ?Node
    {
        if (is_numeric($identifier)) {
            return Node::find((int) $identifier);
        }

        return Node::where('slug', $identifier)
            ->orWhere('name', $identifier)
            ->first();
    }

    /**
     * Сгенерировать конфигурацию агента для копирования.
     *
     * @return array<string, mixed>
     */
    public function agentConfig(Node $node): array
    {
        $token = $node->plainToken() ?: '';

        return [
            'panel' => config('app.url'),
            'ws_url' => rtrim((string) config('app.url'), '/').'/agent/ws',
            'node_id' => $node->id,
            'token' => $token,
            'runtime' => $node->runtime,
            'servers_root' => (string) setting('hosting.paths.servers_root'),
            'backups_root' => (string) setting('hosting.paths.backups_root'),
            'system_user' => (string) setting('hosting.paths.user'),
            'install_command' => $this->scheduler->installCommand($node),
        ];
    }
}
