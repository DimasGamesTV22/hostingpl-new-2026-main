<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Единая точка отправки команд ноде.
 *
 * Работает из любого процесса панели (HTTP, воркер, artisan):
 *   • если мы внутри процесса WSS-сервера — сокет доступен напрямую;
 *   • иначе команда кладётся в Redis-список, его забирает тик WSS-сервера.
 *
 * В обоих случаях ответ приходит асинхронно событием `rpc:{type}` и
 * обрабатывается слушателями (см. AgentServiceProvider).
 */
class AgentClient
{
    public const TYPE_PING = 'ping';
    public const TYPE_NODE_INFO = 'node.info';
    public const TYPE_SERVER_CREATE = 'server.create';
    public const TYPE_SERVER_DELETE = 'server.delete';
    public const TYPE_SERVER_INSTALL = 'server.install';
    public const TYPE_SERVER_START = 'server.start';
    public const TYPE_SERVER_STOP = 'server.stop';
    public const TYPE_SERVER_RESTART = 'server.restart';
    public const TYPE_SERVER_KILL = 'server.kill';
    public const TYPE_SERVER_WRITE = 'server.console.write';
    public const TYPE_SERVER_READ = 'server.console.read';
    public const TYPE_SERVER_QUERY = 'server.query';
    public const TYPE_FILES_LIST = 'files.list';
    public const TYPE_FILES_READ = 'files.read';
    public const TYPE_FILES_WRITE = 'files.write';
    public const TYPE_FILES_DELETE = 'files.delete';
    public const TYPE_FILES_MKDIR = 'files.mkdir';
    public const TYPE_FILES_RENAME = 'files.rename';
    public const TYPE_FILES_UPLOAD = 'files.upload';
    public const TYPE_FILES_DOWNLOAD = 'files.download';
    public const TYPE_FILES_SEARCH = 'files.search';
    public const TYPE_BACKUP_CREATE = 'backup.create';
    public const TYPE_BACKUP_RESTORE = 'backup.restore';
    public const TYPE_BACKUP_DELETE = 'backup.delete';
    public const TYPE_BACKUP_LIST = 'backup.list';
    public const TYPE_LOGS_READ = 'logs.read';
    public const TYPE_LOGS_TAIL = 'logs.tail';
    public const TYPE_UPDATE_GAME = 'server.update';
    public const TYPE_TEMPLATE_INSTALL = 'template.install';
    public const TYPE_TEMPLATE_REMOVE = 'template.remove';
    public const TYPE_SCHEDULE_RUN = 'schedule.run';
    public const TYPE_SUBSCRIBE_CONSOLE = 'console.subscribe';
    public const TYPE_UNSUBSCRIBE_CONSOLE = 'console.unsubscribe';
    public const TYPE_CONFIG_READ = 'config.read';
    public const TYPE_CONFIG_WRITE = 'config.write';

    public function __construct(private readonly AgentRegistry $registry) {}

    // ── Низкий уровень ──────────────────────────────────────────────────

    /**
     * Отправить команду ноде.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, mode: string, rid: ?string, error: ?string}
     */
    public function send(int $nodeId, string $type, array $payload = [], ?callable $onResponse = null): array
    {
        $node = Node::find($nodeId);

        if (! $node) {
            return ['ok' => false, 'mode' => 'none', 'rid' => null, 'error' => 'Нода не найдена'];
        }

        $connection = $this->registry->get($nodeId);

        if ($connection && $connection->isOpen()) {
            $rid = $connection->request($type, $payload, $onResponse);

            return ['ok' => true, 'mode' => 'socket', 'rid' => $rid, 'error' => null];
        }

        // Ноды нет в памяти: если режим outbound и разрешён SSH — отвечаем ошибкой явно
        if ($node->connection_mode === 'outbound' && ! $node->allow_ssh_fallback) {
            return [
                'ok' => false, 'mode' => 'none', 'rid' => null,
                'error' => 'Нода в режиме исходящего подключения: запустите WSS-сервер (gamedock:ws-server)',
            ];
        }

        $this->pushToQueue($nodeId, [
            'type' => $type,
            'payload' => $payload,
            'queued_at' => now()->timestamp,
        ]);

        return ['ok' => true, 'mode' => 'queue', 'rid' => null, 'error' => null];
    }

    public function isOnline(int $nodeId): bool
    {
        return $this->registry->has($nodeId);
    }

    public function onlineNodeIds(): array
    {
        return array_keys(array_filter(
            $this->registry->all(),
            static fn (AgentConnection $c) => $c->isOpen(),
        ));
    }

    public function pushToQueue(int $nodeId, array $message): void
    {
        try {
            Redis::connection('default')->rpush(
                $this->queueKey($nodeId),
                (string) json_encode($message, JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable $e) {
            Log::error('Agent: не удалось поставить команду в очередь', [
                'node' => $nodeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function queueKey(int $nodeId): string
    {
        return 'gamedock:agent:out:'.$nodeId;
    }

    public function queueSize(int $nodeId): int
    {
        try {
            return (int) Redis::connection('default')->llen($this->queueKey($nodeId));
        } catch (\Throwable) {
            return 0;
        }
    }

    // ── Ноды ────────────────────────────────────────────────────────────

    public function ping(Node $node): array
    {
        return $this->send($node->id, self::TYPE_PING);
    }

    public function nodeInfo(Node $node): array
    {
        return $this->send($node->id, self::TYPE_NODE_INFO);
    }

    // ── Жизненный цикл сервера ──────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $spec
     */
    public function createServer(Node $node, Server $server, array $spec, ?callable $onResponse = null): array
    {
        return $this->send($node->id, self::TYPE_SERVER_CREATE, [
            'server' => [
                'id' => $server->id,
                'uuid' => $server->uuid,
                'name' => $server->name,
                'runtime' => $server->runtime,
                'game' => [
                    'slug' => $server->game->slug,
                    'family' => $server->game->family,
                    'image' => $server->game->image,
                    'working_user' => $server->game->working_user,
                    'runtime_overrides' => $server->game->runtime_overrides,
                ],
                'startup' => $server->startup,
                'env' => $server->env,
                'resources' => $this->resourceSpec($server),
                'ports' => [
                    'game' => $server->game_port,
                    'query' => $server->query_port,
                    'rcon' => $server->rcon_port,
                ],
                'slots' => $server->slots,
                'watchdog' => [
                    'enabled' => $server->watchdog_enabled,
                    'restart_delay' => (int) setting('hosting.watchdog.restart_delay', 10),
                    'max_restarts' => (int) setting('hosting.watchdog.max_restarts', 5),
                    'window_minutes' => (int) setting('hosting.watchdog.window_minutes', 15),
                ],
            ],
            'spec' => $spec,
        ], $onResponse);
    }

    public function deleteServer(Node $node, Server $server, bool $purge = true, ?callable $onResponse = null): array
    {
        return $this->send($node->id, self::TYPE_SERVER_DELETE, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'external_id' => $server->external_id,
            'runtime' => $server->runtime,
            'purge' => $purge,
        ], $onResponse);
    }

    public function install(Node $node, Server $server, ?callable $onResponse = null): array
    {
        return $this->send($node->id, self::TYPE_SERVER_INSTALL, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'runtime' => $server->runtime,
            'build' => $server->build_version,
            'installer' => $server->game->installer,
            'bootstrap_files' => $server->game->bootstrap_files,
        ], $onResponse);
    }

    public function updateGame(Node $node, Server $server, ?string $build = null): array
    {
        return $this->send($node->id, self::TYPE_UPDATE_GAME, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'build' => $build,
            'installer' => $server->game->installer,
        ]);
    }

    public function start(Node $node, Server $server, bool $viaRcon = true): array
    {
        return $this->send($node->id, self::TYPE_SERVER_START, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'via_rcon' => $viaRcon,
        ]);
    }

    public function stop(Node $node, Server $server, bool $graceful = true): array
    {
        return $this->send($node->id, self::TYPE_SERVER_STOP, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'graceful' => $graceful,
            'timeout' => (int) data_get($server->startup, 'stop_timeout', 30),
        ]);
    }

    public function restart(Node $node, Server $server): array
    {
        return $this->send($node->id, self::TYPE_SERVER_RESTART, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
        ]);
    }

    public function kill(Node $node, Server $server): array
    {
        return $this->send($node->id, self::TYPE_SERVER_KILL, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
        ]);
    }

    public function writeConsole(Node $node, Server $server, string $command): array
    {
        return $this->send($node->id, self::TYPE_SERVER_WRITE, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'command' => $command,
        ]);
    }

    public function subscribeConsole(Node $node, Server $server, int $buffer = 200): array
    {
        return $this->send($node->id, self::TYPE_SUBSCRIBE_CONSOLE, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'buffer' => $buffer,
        ]);
    }

    public function unsubscribeConsole(Node $node, Server $server): array
    {
        return $this->send($node->id, self::TYPE_UNSUBSCRIBE_CONSOLE, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
        ]);
    }

    // ── Файлы ───────────────────────────────────────────────────────────

    public function listFiles(Node $node, Server $server, string $path = '.'): array
    {
        return $this->send($node->id, self::TYPE_FILES_LIST, [
            'server_id' => $server->id, 'path' => $path,
        ]);
    }

    public function readFile(Node $node, Server $server, string $path, int $maxBytes = 1048576): array
    {
        return $this->send($node->id, self::TYPE_FILES_READ, [
            'server_id' => $server->id, 'path' => $path, 'max_bytes' => $maxBytes,
        ]);
    }

    public function writeFile(Node $node, Server $server, string $path, string $content): array
    {
        return $this->send($node->id, self::TYPE_FILES_WRITE, [
            'server_id' => $server->id, 'path' => $path, 'content' => base64_encode($content),
        ]);
    }

    public function deleteFile(Node $node, Server $server, string $path, bool $recursive = false): array
    {
        return $this->send($node->id, self::TYPE_FILES_DELETE, [
            'server_id' => $server->id, 'path' => $path, 'recursive' => $recursive,
        ]);
    }

    public function makeDirectory(Node $node, Server $server, string $path): array
    {
        return $this->send($node->id, self::TYPE_FILES_MKDIR, [
            'server_id' => $server->id, 'path' => $path,
        ]);
    }

    public function renameFile(Node $node, Server $server, string $from, string $to): array
    {
        return $this->send($node->id, self::TYPE_FILES_RENAME, [
            'server_id' => $server->id, 'from' => $from, 'to' => $to,
        ]);
    }

    public function searchFiles(Node $node, Server $server, string $query, string $path = '.'): array
    {
        return $this->send($node->id, self::TYPE_FILES_SEARCH, [
            'server_id' => $server->id, 'query' => $query, 'path' => $path,
        ]);
    }

    // ── Бэкапы ──────────────────────────────────────────────────────────

    public function createBackup(Node $node, Server $server, array $options = []): array
    {
        return $this->send($node->id, self::TYPE_BACKUP_CREATE, array_merge([
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'name' => 'backup-'.now()->format('Ymd-His'),
            'exclude' => (array) setting('hosting.backups.exclude', []),
            'upload' => (bool) setting('hosting.backups.upload_to_s3', true),
            's3' => [
                'bucket' => setting('hosting.backups.s3_bucket'),
                'key' => $server->user_id.'/'.$server->uuid.'/'.now()->format('Y/m/d').'/',
            ],
        ], $options));
    }

    public function restoreBackup(Node $node, Server $server, string $path, bool $stopServer = true): array
    {
        return $this->send($node->id, self::TYPE_BACKUP_RESTORE, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'path' => $path,
            'stop' => $stopServer,
        ]);
    }

    public function deleteBackup(Node $node, Server $server, string $path): array
    {
        return $this->send($node->id, self::TYPE_BACKUP_DELETE, [
            'server_id' => $server->id, 'path' => $path,
        ]);
    }

    public function listBackups(Node $node, Server $server): array
    {
        return $this->send($node->id, self::TYPE_BACKUP_LIST, [
            'server_id' => $server->id, 'uuid' => $server->uuid,
        ]);
    }

    // ── Логи ────────────────────────────────────────────────────────────

    public function readLogs(Node $node, Server $server, int $lines = 200, ?string $file = null): array
    {
        return $this->send($node->id, self::TYPE_LOGS_READ, [
            'server_id' => $server->id, 'lines' => $lines, 'file' => $file,
        ]);
    }

    public function searchLogs(Node $node, Server $server, string $query, int $max = 200): array
    {
        return $this->send($node->id, self::TYPE_LOGS_READ, [
            'server_id' => $server->id, 'query' => $query, 'max' => $max,
        ]);
    }

    // ── Плагины и сборки ────────────────────────────────────────────────

    public function installTemplate(Node $node, Server $server, array $template): array
    {
        return $this->send($node->id, self::TYPE_TEMPLATE_INSTALL, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'template' => $template,
        ]);
    }

    public function removeTemplate(Node $node, Server $server, array $template): array
    {
        return $this->send($node->id, self::TYPE_TEMPLATE_REMOVE, [
            'server_id' => $server->id,
            'uuid' => $server->uuid,
            'template' => $template,
        ]);
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    /**
     * Лимиты ресурсов в формате, понятном агенту (в МБ и процентах).
     *
     * @return array<string, int>
     */
    private function resourceSpec(Server $server): array
    {
        return [
            'memory_mb' => (int) $server->memory_mb,
            'cpu_percent' => (int) $server->cpu_percent,
            'swap_mb' => (int) $server->swap_mb,
            'disk_mb' => (int) $server->disk_mb,
            'network_mbps' => (int) $server->network_mbps,
            'pids' => (int) $server->pids,
        ];
    }
}
