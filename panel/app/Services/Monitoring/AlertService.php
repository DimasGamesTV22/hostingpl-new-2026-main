<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Node;
use App\Models\Server;
use App\Services\Notify\Notifier;
use App\Services\Notify\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Пороги и алерты. Держит состояние «уже оповестили», чтобы не спамить.
 */
class AlertService
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly TelegramService $telegram,
    ) {}

    public function checkServerUsage(Server $server): void
    {
        if (! $server->isRunning()) {
            return;
        }

        $key = "gamedock:alert:server:{$server->id}";

        if (setting_bool('hosting.monitoring.alerts.high_ram', true)) {
            $threshold = (float) setting('hosting.monitoring.alerts.high_ram_threshold', 90);
            $usage = $server->memoryUsagePercent();

            if ($usage >= $threshold && ! $this->alerted($key, 'ram')) {
                $this->notifier->user(
                    $server->user,
                    'server.high_ram',
                    __('notifications.high_ram', ['name' => $server->name]),
                    __('notifications.high_ram_body', ['usage' => $usage, 'limit' => mb_gb($server->memory_mb)]),
                    ['level' => 'warning', 'telegram' => true],
                );

                $this->telegram->sendMessage($this->telegram->highResources($server, ['RAM' => $usage]));
            }
        }

        if (setting_bool('hosting.monitoring.alerts.high_cpu', true)) {
            $threshold = (float) setting('hosting.monitoring.alerts.high_cpu_threshold', 95);
            $usage = (float) $server->cpu_usage;

            if ($usage >= $threshold && ! $this->alerted($key, 'cpu')) {
                $this->notifier->user(
                    $server->user,
                    'server.high_cpu',
                    __('notifications.high_cpu', ['name' => $server->name, 'usage' => round($usage)]),
                    '',
                    ['level' => 'warning', 'telegram' => true],
                );
            }
        }

        if (setting_bool('hosting.monitoring.alerts.disk_low', true)) {
            $threshold = (float) setting('hosting.monitoring.alerts.disk_low_threshold', 90);
            $usage = $server->disk_mb > 0 ? round($server->disk_used_mb / $server->disk_mb * 100, 1) : 0;

            if ($usage >= $threshold && ! $this->alerted($key, 'disk')) {
                $this->notifier->user(
                    $server->user,
                    'server.disk_low',
                    __('notifications.disk_low', ['name' => $server->name, 'usage' => $usage]),
                    __('notifications.disk_low_body', ['used' => mb_gb((int) $server->disk_used_mb), 'total' => mb_gb((int) $server->disk_mb)]),
                    ['level' => 'danger', 'telegram' => true],
                );
            }
        }
    }

    public function checkServerDown(Server $server): void
    {
        $after = (int) setting('hosting.monitoring.alerts.server_down_after_seconds', 120);
        $threshold = now()->subSeconds($after);

        if (! setting_bool('hosting.monitoring.alerts.server_down', true)) {
            return;
        }

        $statusChangedAt = $server->status_changed_at ?? $server->updated_at;

        if (! $statusChangedAt || $statusChangedAt->gt($threshold)) {
            return;
        }

        if (! in_array($server->status, [Server::STATUS_CRASHED, Server::STATUS_ERROR], true)) {
            return;
        }

        $key = "gamedock:alert:down:{$server->id}";

        if (Cache::get($key)) {
            return;
        }

        Cache::put($key, 1, now()->addHour());

        $this->notifier->serverDown($server);
    }

    public function checkNodeOffline(Node $node): void
    {
        if (! setting_bool('hosting.monitoring.alerts.node_offline', true)) {
            return;
        }

        $key = "gamedock:alert:node:{$node->id}";

        if (Cache::get($key)) {
            return;
        }

        Cache::put($key, 1, now()->addMinutes(30));

        $this->notifier->nodeOffline($node);
    }

    public function checkLowBalance(Server $server): void
    {
        if (! setting_bool('hosting.monitoring.alerts.balance_low', true)) {
            return;
        }

        $threshold = (float) setting('hosting.monitoring.alerts.balance_low_threshold', 50);
        $user = $server->user;

        if (! $user || (float) $user->balance > $threshold) {
            return;
        }

        $key = "gamedock:alert:balance:{$user->id}";

        if (Cache::get($key)) {
            return;
        }

        Cache::put($key, 1, now()->addDay());

        $this->notifier->lowBalance($user, $threshold);
    }

    /** Не превышен ли лимит алертов в час (антиспам). */
    private function alerted(string $key, string $type): bool
    {
        $rateKey = "gamedock:alert:rate:{$key}:{$type}";

        try {
            $count = (int) Cache::get($rateKey, 0);
            $limit = (int) setting('hosting.monitoring.alerts.rate_limit_per_hour', 10);

            if ($count >= $limit) {
                return true;
            }

            Cache::put($rateKey, $count + 1, now()->addHour());
        } catch (\Throwable $e) {
            Log::debug('rate limit check failed: '.$e->getMessage());
        }

        return false;
    }
}
