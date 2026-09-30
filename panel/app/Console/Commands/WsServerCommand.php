<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\Server;
use App\Models\ServerCommand;
use App\Services\Agent\AgentConnection;
use App\Services\Agent\AgentRegistry;
use App\Services\Agent\Ws\WebSocketServer;
use App\Services\Monitoring\ConsoleBroadcaster;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Monitoring\AlertService;
use App\Services\SecretCodes\SecretCodeResolver;
use App\Services\Servers\ServerEventLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputOption;

/**
 * WSS-сервер панели: держит постоянные соединения с агентами игровых нод.
 *
 * Запуск (systemd-юнит gamedock-agent-server):
 *   php artisan gamedock:ws-server --host=0.0.0.0 --port=9222
 *
 * Здесь же крутится тик: забирает команды из Redis, сливает буферы,
 * проверяет таймауты и heartbeat'ы.
 */
class WsServerCommand extends Command
{
    protected $signature = 'gamedock:ws-server
        {--host=0.0.0.0 : Адрес прослушивания}
        {--port=9222 : Порт WSS}
        {--token= : Общий секрет для подписи (если не задан — берётся из config)}
        {--max-payload=8388608 : Максимальный размер сообщения в байтах}';

    protected $description = 'WebSocket-сервер для связи панели с агентами игровых нод';

    private WebSocketServer $server;

    private AgentRegistry $registry;

    private float $lastTick = 0.0;

    private float $startedAt;

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');

        $this->server = new WebSocketServer(
            host: $host,
            port: $port,
            maxPayload: (int) $this->option('max-payload'),
        );

        $this->registry = app(AgentRegistry::class);

        $this->startedAt = microtime(true);

        try {
            $this->server->listen();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("GameDock WSS-сервер слушает ws://{$host}:{$port}");
        $this->line('  Агенты подключаются сюда. Остановка: Ctrl+C');

        $this->server->onOpen(function (int $fd, array $info) {
            $this->handleOpen($fd, $info);
        });

        $this->server->onMessage(function (int $fd, string $raw) {
            $this->handleMessage($fd, $raw);
        });

        $this->server->onClose(function (int $fd, int $code, string $reason) {
            $this->handleClose($fd, $code, $reason);
        });

        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->server->stop());
            pcntl_signal(SIGINT, fn () => $this->server->stop());
        }

        $this->server->run(function () {
            $this->tick();
        });

        $this->info('WSS-сервер остановлен.');

        return self::SUCCESS;
    }

    // ── Подключения ─────────────────────────────────────────────────────

    private function handleOpen(int $fd, array $info): void
    {
        $headers = array_change_key_case($info['headers'], CASE_LOWER);
        $token = $headers['x-gamedock-token'] ?? null;

        if (blank($token)) {
            $this->server->closeClient($fd, 4001, 'missing token');
            Log::warning('Agent: подключение без токена', ['ip' => $info['ip'] ?? null]);

            return;
        }

        $node = $this->registry->authenticateAgent((string) $token);

        if (! $node) {
            $this->server->closeClient($fd, 4003, 'unauthorized');
            Log::warning('Agent: отказ в подключении, токен не найден', ['ip' => $info['ip'] ?? null]);

            return;
        }

        $secret = $node->plainToken() ?: (string) config('app.key');
        $connection = new AgentConnection($fd, $node, $this->server, $secret);

        $this->bindEvents($connection);

        $this->registry->add($node, $connection);

        $this->line(sprintf('  [%s] нода #%d «%s» подключена', now()->format('H:i:s'), $node->id, $node->name));

        // Просим актуальные данные
        $connection->sendNow(['type' => 'node.info', 'payload' => ['requested' => true]]);
    }

    private function handleClose(int $fd, int $code, string $reason): void
    {
        foreach ($this->registry->all() as $nodeId => $connection) {
            if ($connection->fd !== $fd) {
                continue;
            }

            $node = $connection->node;

            $node->forceFill([
                'status' => Node::STATUS_OFFLINE,
                'missed_heartbeats' => (int) $node->missed_heartbeats + 1,
            ])->save();

            $this->registry->remove($nodeId);

            $this->line(sprintf('  [%s] нода #%d «%s» отключена (%s)', now()->format('H:i:s'), $nodeId, $node->name, $reason));

            return;
        }
    }

    private function handleMessage(int $fd, string $raw): void
    {
        $connection = $this->findByFd($fd);

        if (! $connection) {
            return;
        }

        $connection->handleRaw($raw);
    }

    // ── Подписки на события агента ──────────────────────────────────────

    private function bindEvents(AgentConnection $connection): void
    {
        $console = app(ConsoleBroadcaster::class);
        $metrics = app(MetricsCollector::class);
        $alerts = app(AlertService::class);
        $codes = app(SecretCodeResolver::class);
        $events = app(ServerEventLogger::class);

        // ── Консоль ─────────────────────────────────────────────────────
        $connection->listen('console.output', function (array $data) use ($console) {
            $serverId = (int) ($data['server_id'] ?? 0);
            if (! $serverId) {
                return;
            }

            $console->push($serverId, [
                'stream' => $data['stream'] ?? 'stdout',
                'text' => (string) ($data['text'] ?? ''),
                'type' => $data['type'] ?? 'stdout',
                'ts' => $data['ts'] ?? now()->timestamp,
            ]);
        });

        $connection->listen('console.stats', function (array $data) use ($console) {
            $serverId = (int) ($data['server_id'] ?? 0);
            if ($serverId) {
                $console->stats($serverId, $data['stats'] ?? []);
            }
        });

        $connection->listen('server.status', function (array $data) use ($console, $events) {
            $server = Server::find((int) ($data['server_id'] ?? 0));

            if (! $server) {
                return;
            }

            $status = (string) ($data['status'] ?? Server::STATUS_STOPPED);
            $changes = [
                'status' => $status,
                'status_reason' => $data['reason'] ?? null,
            ];

            if ($status === Server::STATUS_RUNNING) {
                $changes['last_started_at'] = now();
                $changes['installed_at'] = $server->installed_at ?? now();
                $changes['install_progress'] = 100;
            }

            if ($status === Server::STATUS_STOPPED) {
                $changes['last_stopped_at'] = now();
                $changes['uptime_seconds'] = 0;
            }

            if ($status === Server::STATUS_CRASHED) {
                $changes['last_crash_at'] = now();
                $changes['crash_count'] = (int) $server->crash_count + 1;
                $changes['uptime_seconds'] = 0;
            }

            $server->forceFill($changes)->save();

            $console->status($server->id, $status, $data['reason'] ?? null);

            if ($status === Server::STATUS_CRASHED) {
                $events->log($server, 'error', __('servers.events.crashed', [
                    'reason' => $data['reason'] ?? 'неизвестно',
                ]), 'error', ['uptime' => $data['uptime'] ?? null]);
            }
        });

        // ── Установка ───────────────────────────────────────────────────
        $connection->listen('install.progress', function (array $data) use ($events) {
            $server = Server::find((int) ($data['server_id'] ?? 0));
            if (! $server) {
                return;
            }

            $server->forceFill([
                'status' => Server::STATUS_INSTALLING,
                'install_progress' => (int) ($data['progress'] ?? 0),
            ])->save();

            if (! empty($data['log'])) {
                $server->forceFill(['install_log' => mb_substr((string) $data['log'], -60000)])->save();
            }
        });

        $connection->listen('install.step', function (array $data) {
            $server = Server::find((int) ($data['server_id'] ?? 0));
            if (! $server) {
                return;
            }

            \App\Models\ServerInstallLog::create([
                'server_id' => $server->id,
                'step' => (int) ($data['step'] ?? 0),
                'name' => (string) ($data['name'] ?? ''),
                'status' => (string) ($data['status'] ?? 'running'),
                'output' => isset($data['output']) ? mb_substr((string) $data['output'], 0, 20000) : null,
                'progress' => (int) ($data['progress'] ?? 0),
                'duration_ms' => (int) ($data['duration_ms'] ?? 0),
                'error' => $data['error'] ?? null,
            ]);
        });

        $connection->listen('install.completed', function (array $data) use ($events) {
            $server = Server::find((int) ($data['server_id'] ?? 0));
            if (! $server) {
                return;
            }

            $server->forceFill([
                'status' => Server::STATUS_INSTALLED,
                'install_progress' => 100,
                'installed_at' => now(),
                'external_id' => $data['external_id'] ?? $server->external_id,
                'install_manifest' => $data['files'] ?? null,
                'status_reason' => null,
            ])->save();

            $events->log($server, 'install', __('servers.events.installed'), 'success', [
                'duration' => $data['duration_ms'] ?? null,
            ]);
        });

        $connection->listen('install.failed', function (array $data) use ($events) {
            $server = Server::find((int) ($data['server_id'] ?? 0));
            if (! $server) {
                return;
            }

            $server->forceFill([
                'status' => Server::STATUS_ERROR,
                'status_reason' => mb_substr((string) ($data['error'] ?? 'Ошибка установки'), 0, 250),
            ])->save();

            $events->log($server, 'install', __('servers.events.install_failed'), 'error', [
                'error' => $data['error'] ?? null,
                'step' => $data['step'] ?? null,
            ]);
        });

        // ── Метрики ─────────────────────────────────────────────────────
        $connection->listen('metrics.push', function (array $data) use ($metrics) {
            $points = $data['points'] ?? [];

            foreach (($data['servers'] ?? []) as $item) {
                $serverId = (int) ($item['server_id'] ?? 0);

                if ($serverId && ! empty($item['points'])) {
                    $metrics->push($serverId, $item['points']);
                }
            }

            // Ноды и её системные метрики
            $nodeId = (int) ($data['node_id'] ?? $connection->node->id);
            if (! empty($data['system'])) {
                $metrics->recordNodeHealth($connection->node, $data['system']);
            }
        });

        // ── Бэкапы ──────────────────────────────────────────────────────
        $connection->listen('backup.completed', function (array $data) {
            $server = Server::find((int) ($data['server_id'] ?? 0));
            if ($server) {
                app(\App\Services\Backups\BackupService::class)->markCompleted($server, $data);
            }
        });

        $connection->listen('backup.failed', function (array $data) {
            $server = Server::find((int) ($data['server_id'] ?? 0));
            if ($server) {
                app(\App\Services\Backups\BackupService::class)->markFailed($server, $data);
            }
        });

        // ── Секретные коды (перехват чата) ──────────────────────────────
        $connection->listen('chat.message', function (array $data) use ($codes, $console) {
            $result = $codes->handleChatMessage($data);

            if ($result['matched']) {
                $serverId = (int) ($data['server_id'] ?? 0);

                if (! empty($result['message'])) {
                    // Ответ уходит прямо в игровой чат
                    $connection->sendNow([
                        'type' => 'server.console.write',
                        'payload' => [
                            'server_id' => $serverId,
                            'command' => $this->chatSayCommand($result['message']),
                            'from_panel' => true,
                        ],
                    ]);

                    $console->system($serverId, '[секретный код] '.$result['message']);
                }
            }
        });

        // ── Обновление игры ─────────────────────────────────────────────
        $connection->listen('update.completed', function (array $data) use ($events) {
            $server = Server::find((int) ($data['server_id'] ?? 0));

            if ($server) {
                $server->forceFill(['build_version' => $data['build'] ?? $server->build_version])->save();
                $events->log($server, 'setting', __('servers.events.updated', ['build' => $data['build'] ?? '']), 'success');
            }
        });

        // ── Дисковое пространство ───────────────────────────────────────
        $connection->listen('node.alert', function (array $data) use ($alerts) {
            $type = (string) ($data['type'] ?? '');

            if ($type === 'disk_low' || $type === 'memory_low') {
                Log::warning('Agent alert: '.$type, [
                    'node' => $connection->node->id,
                    'data' => $data,
                ]);
            }
        });

        // ── Команды, накопленные в БД (для надёжности) ─────────────────
        $connection->listen('__noop', static function () {
        });
    }

    private function chatSayCommand(string $message): string
    {
        // SAMP/MTA: /me или say, Rust — тоже say. Универсально: broadcast
        return trim(strip_tags($message));
    }

    // ── Тик ─────────────────────────────────────────────────────────────

    private function tick(): void
    {
        $now = microtime(true);

        // Не чаще 20 раз в секунду
        if ($now - $this->lastTick < 0.05) {
            return;
        }

        $this->lastTick = $now;

        foreach ($this->registry->all() as $nodeId => $connection) {
            if (! $connection->isOpen()) {
                continue;
            }

            $connection->flush();
            $connection->expireStale((float) config('hosting.agent.rpc_timeout', 15));

            $this->pumpQueue($connection);
        }
    }

    /** Забрать команды, поставленные HTTP-воркерами в Redis. */
    private function pumpQueue(AgentConnection $connection): void
    {
        $messages = $connection->popInbound();

        foreach ($messages as $message) {
            $type = (string) ($message['type'] ?? 'ping');
            $payload = (array) ($message['payload'] ?? []);
            $serverId = (int) ($payload['server_id'] ?? 0);

            // Команды, влияющие на состояние сервера, логируем в БД
            $tracked = in_array($type, [
                'server.start', 'server.stop', 'server.restart', 'server.kill',
                'server.create', 'server.delete', 'server.install', 'server.update',
                'backup.create', 'backup.restore',
            ], true);

            $record = null;

            if ($tracked && $serverId > 0) {
                $record = ServerCommand::create([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'server_id' => $serverId,
                    'type' => $type,
                    'payload' => $payload,
                    'status' => ServerCommand::STATUS_SENT,
                    'sent_at' => now(),
                ]);
            }

            $connection->request($type, $payload, function (array $response) use ($record) {
                if (! $record) {
                    return;
                }

                $ok = (bool) ($response['ok'] ?? $response['status'] === 'ok');

                $record->forceFill([
                    'status' => $ok ? ServerCommand::STATUS_DONE : ServerCommand::STATUS_FAILED,
                    'result' => $response['result'] ?? $response,
                    'error' => $ok ? null : mb_substr((string) ($response['error'] ?? 'ошибка агента'), 0, 500),
                    'finished_at' => now(),
                ])->save();
            });
        }
    }

    private function findByFd(int $fd): ?AgentConnection
    {
        foreach ($this->registry->all() as $connection) {
            if ($connection->fd === $fd) {
                return $connection;
            }
        }

        return null;
    }
}
