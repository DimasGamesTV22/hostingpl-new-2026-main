<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\Agent\AgentClient;
use Illuminate\Support\Facades\Log;

/**
 * Уборка «мёртвых» серверов: сервер в статусе installing/scheduled, но агент
 * давно не отвечает, — переводится в error. Серверы, помеченные на удаление,
 * удаляются при следующем появлении ноды в сети.
 */
class ServerReaper
{
    public function __construct(private readonly AgentClient $agent) {}

    public function reap(): array
    {
        $stats = ['error' => 0, 'deleted' => 0, 'crashed' => 0];

        // 1. Установки, которые висят дольше двух часов
        $stuck = Server::query()
            ->whereIn('status', [Server::STATUS_PENDING, Server::STATUS_INSTALLING])
            ->where('last_install_attempt_at', '<', now()->subHours(2))
            ->limit(50)
            ->get();

        foreach ($stuck as $server) {
            $server->forceFill([
                'status' => Server::STATUS_ERROR,
                'status_reason' => 'Установка не завершилась за 2 часа. Попробуйте переустановить сервер.',
            ])->save();

            $stats['error']++;
        }

        // 2. Переходные статусы (starting/stopping), зависшие дольше 10 минут
        $transitional = Server::query()
            ->whereIn('status', [Server::STATUS_STARTING, Server::STATUS_STOPPING])
            ->where('status_changed_at', '<', now()->subMinutes(10))
            ->limit(100)
            ->get();

        foreach ($transitional as $server) {
            $fallback = $server->status === Server::STATUS_STARTING
                ? Server::STATUS_STOPPED
                : Server::STATUS_STOPPED;

            $server->forceFill([
                'status' => $fallback,
                'status_reason' => 'Агент не подтвердил смену статуса',
            ])->save();

            $stats['crashed']++;
        }

        // 3. Серверы, ожидающие удаления, на нодах, которые снова онлайн
        $pendingDelete = Server::query()
            ->withTrashed()
            ->where('status', Server::STATUS_DELETING)
            ->whereHas('node', fn ($q) => $q->where('status', 'online'))
            ->limit(20)
            ->get();

        foreach ($pendingDelete as $server) {
            if ($server->node) {
                $this->agent->deleteServer($server->node, $server, true);
            }

            $server->forceDelete();
            $stats['deleted']++;
        }

        if (array_sum($stats) > 0) {
            Log::info('ServerReaper: '.json_encode($stats));
        }

        return $stats;
    }
}
