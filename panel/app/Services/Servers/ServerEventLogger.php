<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerEvent;
use App\Models\User;

/**
 * Запись событий в журнал сервера + аудит привилегированных действий.
 */
class ServerEventLogger
{
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
            'title' => mb_substr($title, 0, 190),
            'context' => $context ?: null,
            'ip' => request()?->ip(),
        ]);
    }

    public function power(Server $server, string $action, ?User $actor = null): ServerEvent
    {
        return $this->log($server, 'power', __('servers.events.'.$action), 'info', [], $actor);
    }

    public function files(Server $server, string $action, string $path, ?User $actor = null): ServerEvent
    {
        return $this->log($server, 'file', __('servers.events.'.$action, ['path' => $path]), 'info', [
            'path' => $path,
        ], $actor);
    }

    public function settings(Server $server, array $changes, ?User $actor = null): ServerEvent
    {
        return $this->log($server, 'setting', __('servers.events.settings_changed'), 'info', [
            'changed' => array_keys($changes),
        ], $actor);
    }

    public function error(Server $server, string $message, array $context = []): ServerEvent
    {
        return $this->log($server, 'error', $message, 'error', $context);
    }
}
