<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Audit\Auditor;
use App\Models\Game;
use App\Models\Server;
use App\Models\ServerEvent;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Games\StartupBuilder;
use App\Services\Nodes\NodeScheduler;
use App\Services\Nodes\PortAllocator;
use App\Support\Crypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Создание, изменение и удаление игровых серверов.
 */
class Provisioner
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly NodeScheduler $scheduler,
        private readonly PortAllocator $ports,
        private readonly StartupBuilder $startup,
    ) {}

    /**
     * Создать сервер.
     *
     * @param  array{
     *   name: string, game_id: int, memory_mb: int, disk_mb: int, slots: int,
     *   cpu_percent?: int, network_mbps?: int, pids?: int, tariff_id?: int|null,
     *   region?: ?string, node_id?: ?int, build?: ?string, watchdog?: bool,
     *   sub_accounts?: bool, port?: ?int, start_after?: bool
     * }  $data
     */
    public function create(User $user, Game $game, array $data): Server
    {
        $tariff = isset($data['tariff_id']) ? Tariff::find($data['tariff_id']) : null;
        $runtime = $this->resolveRuntime($tariff, $data);

        $memoryMb = (int) ($data['memory_mb'] ?? $game->default_memory_mb);
        $diskMb = (int) ($data['disk_mb'] ?? $game->default_disk_mb);
        $slots = (int) ($data['slots'] ?? $game->default_slots);

        // ── Выбор ноды ───────────────────────────────────────────────────
        $manualNode = isset($data['node_id']) ? \App\Models\Node::find($data['node_id']) : null;

        if ($manualNode) {
            $problem = $this->scheduler->validate($manualNode, $game, $memoryMb, $diskMb, $runtime);
            if ($problem) {
                throw new ProvisioningException($problem);
            }
            $node = $manualNode;
        } else {
            $result = $this->scheduler->pick(
                game: $game,
                memoryMb: $memoryMb,
                diskMb: $diskMb,
                runtime: $runtime,
                region: $data['region'] ?? null,
            );

            $node = $result['node'];

            if (! $node) {
                throw new ProvisioningException($result['reason'] ?? __('servers.errors.no_node'));
            }
        }

        // ── Создание записи ──────────────────────────────────────────────
        $server = DB::transaction(function () use (
            $user, $game, $tariff, $node, $runtime, $data, $memoryMb, $diskMb, $slots
        ) {
            $server = Server::create([
                'user_id' => $user->id,
                'game_id' => $game->id,
                'node_id' => $node->id,
                'tariff_id' => $tariff?->id,
                'name' => $data['name'],
                'runtime' => $runtime,
                'status' => Server::STATUS_PENDING,
                'build_version' => $data['build'] ?? null,
                'memory_mb' => $memoryMb,
                'cpu_percent' => (int) ($data['cpu_percent'] ?? $game->default_cpu_percent),
                'swap_mb' => (int) ($data['swap_mb'] ?? 0),
                'disk_mb' => $diskMb,
                'network_mbps' => (int) ($data['network_mbps'] ?? 25),
                'pids' => (int) ($data['pids'] ?? 512),
                'slots' => $slots,
                'base_slots' => $slots,
                'watchdog_enabled' => (bool) ($data['watchdog'] ?? setting_bool('hosting.watchdog.enabled', true)),
                'sub_accounts_enabled' => (bool) ($data['sub_accounts'] ?? true),
                'expires_at' => $this->initialExpiry($tariff),
                'last_install_attempt_at' => now(),
            ]);

            // Порты выделяем внутри той же транзакции, чтобы не было гонок
            $this->ports->allocate($server, preferredPort: isset($data['port']) ? (int) $data['port'] : null);

            return $server;
        });

        $this->refreshStartup($server);

        $this->log($server, 'install', __('servers.events.created', ['name' => $server->name]), 'info', [
            'game' => $game->name,
            'node' => $node->name,
            'memory' => mb_gb($memoryMb),
            'slots' => $slots,
        ], $user);

        $this->scheduler->recalculateUsage($node);

        // ── Команда агенту ───────────────────────────────────────────────
        $this->dispatchCreate($server);

        return $server->refresh();
    }

    /** Отправить агенту спецификацию (создание + запуск установки). */
    public function dispatchCreate(Server $server): void
    {
        $node = $server->node;

        if (! $node) {
            $this->fail($server, 'Нода недоступна');

            return;
        }

        $server->forceFill([
            'status' => Server::STATUS_INSTALLING,
            'install_progress' => 0,
        ])->save();

        $result = $this->agent->createServer($node, $server, [
            'name' => $server->name,
            'user_id' => $server->user_id,
            'created_at' => $server->created_at?->toIso8601String(),
            'servers_root' => (string) setting('hosting.paths.servers_root', '/home/gamedock/servers'),
            'backups_root' => (string) setting('hosting.paths.backups_root', '/home/gamedock/backups'),
            'system_user' => (string) setting('hosting.paths.user', 'gamedock'),
            'templates_root' => (string) setting('hosting.paths.templates_root', '/opt/gamedock/game-images'),
            'node' => [
                'id' => $node->id,
                'host' => $node->host,
                'flagship' => $node->flagship,
                'country' => $node->country,
                'runtime_options' => $node->runtime_options,
            ],
        ]);

        if (! $result['ok']) {
            $this->fail($server, $result['error'] ?? 'Не удалось связаться с агентом');
        }
    }

    /** Повторная попытка установки (кнопка «Переустановить»). */
    public function reinstall(Server $server, ?callable $onDone = null): void
    {
        $server->forceFill([
            'status' => Server::STATUS_INSTALLING,
            'install_progress' => 0,
            'install_attempts' => (int) $server->install_attempts + 1,
            'last_install_attempt_at' => now(),
            'status_reason' => null,
        ])->save();

        $this->log($server, 'install', __('servers.events.reinstall'), 'info');

        $node = $server->node;
        if (! $node) {
            $this->fail($server, 'Нода недоступна');

            return;
        }

        $this->agent->install($node, $server);
    }

    public function updateBuild(Server $server, ?string $build = null): void
    {
        $node = $server->node;

        if (! $node) {
            return;
        }

        $result = $this->agent->updateGame($node, $server, $build);

        if (! $result['ok']) {
            $this->log($server, 'error', $result['error'] ?? 'Ошибка обновления', 'error');
        } else {
            $this->log($server, 'setting', __('servers.events.update_queued'), 'info', ['build' => $build]);
        }
    }

    // ── Питание ─────────────────────────────────────────────────────────

    public function start(Server $server, ?User $actor = null): void
    {
        if (! $server->canStart()) {
            return;
        }

        $node = $server->node;
        if (! $node) {
            $this->fail($server, 'Нода недоступна');

            return;
        }

        $server->forceFill([
            'status' => Server::STATUS_STARTING,
            'status_reason' => null,
            'last_started_at' => now(),
        ])->save();

        $result = $this->agent->start($node, $server);

        if (! $result['ok']) {
            $server->forceFill(['status' => Server::STATUS_ERROR, 'status_reason' => $result['error']])->save();
        }

        $this->log($server, 'power', __('servers.events.starting'), 'info', [], $actor);
    }

    public function stop(Server $server, bool $graceful = true, ?User $actor = null): void
    {
        $node = $server->node;

        $server->forceFill(['status' => Server::STATUS_STOPPING])->save();

        if ($node) {
            $result = $graceful
                ? $this->agent->stop($node, $server)
                : $this->agent->kill($node, $server);

            if (! $result['ok']) {
                $server->forceFill([
                    'status' => Server::STATUS_ERROR,
                    'status_reason' => $result['error'],
                ])->save();
            }
        }

        $this->log($server, 'power', $graceful ? __('servers.events.stopping') : __('servers.events.killing'), 'info', [], $actor);
    }

    public function restart(Server $server, ?User $actor = null): void
    {
        $node = $server->node;

        $server->forceFill(['status' => Server::STATUS_STARTING, 'restart_count' => (int) $server->restart_count + 1])->save();

        if ($node) {
            $result = $this->agent->restart($node, $server);
            if (! $result['ok']) {
                $server->forceFill(['status' => Server::STATUS_ERROR, 'status_reason' => $result['error']])->save();
            }
        }

        $this->log($server, 'power', __('servers.events.restarting'), 'info', [], $actor);
    }

    public function kill(Server $server, ?User $actor = null): void
    {
        $this->stop($server, false, $actor);
    }

    // ── Удаление ────────────────────────────────────────────────────────

    public function delete(Server $server, bool $purge = true, ?User $actor = null): void
    {
        $node = $server->node;

        $server->forceFill(['status' => Server::STATUS_DELETING])->save();

        if ($node) {
            $result = $this->agent->deleteServer($node, $server, $purge);
            if (! $result['ok']) {
                Log::warning('Не удалось отправить удаление агенту', [
                    'server' => $server->id,
                    'error' => $result['error'],
                ]);
            }
        }

        $this->log($server, 'power', $purge ? __('servers.events.deleting') : __('servers.events.disabling'), 'warning', [], $actor);

        $this->ports->releaseAll($server);

        $server->delete();

        if ($node) {
            $this->scheduler->recalculateUsage($node);
        }
    }

    // ── Ресурсы и порты ─────────────────────────────────────────────────

    /**
     * Изменить лимиты (память, диск, CPU, слоты).
     */
    public function updateResources(Server $server, array $changes, ?User $actor = null): void
    {
        $allowed = ['memory_mb', 'cpu_percent', 'swap_mb', 'disk_mb', 'network_mbps', 'pids', 'slots', 'watchdog_enabled'];
        $diff = array_intersect_key($changes, array_flip($allowed));

        if ($diff === []) {
            return;
        }

        $old = $server->only(array_keys($diff));
        $server->fill($diff)->save();

        $this->log($server, 'setting', __('servers.events.resources_changed'), 'info', [
            'before' => $old,
            'after' => $server->only(array_keys($diff)),
        ], $actor);

        $node = $server->node;
        if ($node) {
            // Агент применяет лимиты на лету, сервер перезапускать не нужно
            $this->agent->send($node->id, AgentClient::TYPE_SERVER_CREATE, [
                'server' => [
                    'id' => $server->id,
                    'uuid' => $server->uuid,
                    'runtime' => $server->runtime,
                    'resources' => [
                        'memory_mb' => (int) $server->memory_mb,
                        'cpu_percent' => (int) $server->cpu_percent,
                        'swap_mb' => (int) $server->swap_mb,
                        'disk_mb' => (int) $server->disk_mb,
                        'network_mbps' => (int) $server->network_mbps,
                        'pids' => (int) $server->pids,
                    ],
                ],
                'spec' => ['action' => 'update_resources'],
            ]);
        }

        if ($node) {
            $this->scheduler->recalculateUsage($node);
        }
    }

    public function changePort(Server $server, int $port, ?User $actor = null): void
    {
        $node = $server->node;

        if (! $node) {
            return;
        }

        if (! $this->ports->isAvailable($node, $port, $server->id)) {
            throw new ProvisioningException(__('nodes.errors.port_taken', ['port' => $port]));
        }

        $this->ports->releaseAll($server);
        $this->ports->allocate($server, PortAllocator::KIND_GAME, $port);
        $this->ports->allocate($server, PortAllocator::KIND_QUERY);
        $this->ports->allocate($server, PortAllocator::KIND_RCON);

        $this->log($server, 'setting', __('servers.events.port_changed', ['port' => $port]), 'info', [], $actor);
    }

    // ── Стартовая конфигурация ─────────────────────────────────────────

    /** Пересобирает startup/env под текущую игру, сборку и ресурсы. */
    public function refreshStartup(Server $server): void
    {
        $startup = $this->startup->build($server);

        $server->forceFill([
            'startup' => $startup['startup'],
            'env' => $startup['env'],
            'install_command' => $startup['install_command'],
        ])->save();
    }

    // ── Заморозка / разморозка ──────────────────────────────────────────

    public function suspend(Server $server, string $reason, ?User $actor = null): void
    {
        $this->stop($server, true, $actor);

        $server->forceFill([
            'status' => Server::STATUS_SUSPENDED,
            'is_frozen' => true,
            'suspended_at' => now(),
            'suspended_reason' => mb_substr($reason, 0, 250),
            'purge_at' => now()->addDays((int) setting('hosting.billing.delete_after_stop_days', 14)),
        ])->save();

        $this->log($server, 'power', __('servers.events.suspended'), 'warning', ['reason' => $reason], $actor);
    }

    public function resume(Server $server, ?User $actor = null): void
    {
        $server->forceFill([
            'status' => Server::STATUS_STOPPED,
            'is_frozen' => false,
            'suspended_at' => null,
            'suspended_reason' => null,
            'purge_at' => null,
        ])->save();

        $this->log($server, 'power', __('servers.events.resumed'), 'info', [], $actor);
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    private function resolveRuntime(?Tariff $tariff, array $data): string
    {
        if (! empty($data['runtime'])) {
            return (string) $data['runtime'];
        }

        $runtime = default_runtime();

        if ($tariff) {
            // у тарифа рантайм не задаётся — используем дефолтный
            $runtime = $runtime ?: 'docker';
        }

        return in_array($runtime, ['docker', 'podman', 'lxc', 'native'], true) ? $runtime : 'docker';
    }

    private function initialExpiry(?Tariff $tariff): ?\Illuminate\Support\Carbon
    {
        $trial = setting('hosting.marketing.trial');

        if (setting_bool('hosting.marketing.trial.enabled') && $tariff?->is_trial) {
            return now()->addDays((int) $trial['days']);
        }

        if (! $tariff) {
            return null;
        }

        return now()->addDays($tariff->duration_days ?: 30);
    }

    public function fail(Server $server, string $reason): void
    {
        $server->forceFill([
            'status' => Server::STATUS_ERROR,
            'status_reason' => mb_substr($reason, 0, 250),
        ])->save();

        $this->log($server, 'error', $reason, 'error');
    }

    public function log(
        Server $server,
        string $type,
        string $title,
        string $level = 'info',
        array $context = [],
        ?User $actor = null,
    ): ServerEvent {
        return ServerEvent::create([
            'server_id' => $server->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'level' => $level,
            'title' => $title,
            'context' => $context ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
