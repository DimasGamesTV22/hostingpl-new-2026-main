<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\MetricHourly;
use App\Models\Node;
use App\Models\NodeHealthLog;
use App\Models\PublicStatusSnapshot;
use App\Models\Server;
use App\Models\SecretCode;
use App\Services\SecretCodes\SecretCodeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Redis as RedisFacade;

/**
 * Сбор и хранение метрик.
 *
 * Агент шлёт батчи точек (по 5 секунд). Панель:
 *   1. обновляет «горячие» значения в Redis (кольцевой буфер, TTL 7 дней) — оттуда
 *      берутся графики за последние часы;
 *   2. раз в минуту агрегирует в metric_hourly — графики за 30 дней;
 *   3. переносит сырые точки в public_status_snapshots для лендинга.
 */
class MetricsCollector
{
    /** Кольцевой буфер точек в Redis на один сервер. */
    public function push(int $serverId, array $points): void
    {
        try {
            $key = $this->seriesKey($serverId);
            $ttl = (int) setting('hosting.monitoring.raw_ttl', 604800);

            $payload = [];
            $last = null;

            foreach ($points as $point) {
                $ts = (int) ($point['ts'] ?? time());
                $row = [
                    't' => $ts,
                    'c' => (float) ($point['cpu'] ?? 0),
                    'm' => (int) ($point['memory_mb'] ?? 0),
                    'd' => (int) ($point['disk_mb'] ?? 0),
                    'ni' => (int) ($point['net_in'] ?? 0),
                    'no' => (int) ($point['net_out'] ?? 0),
                    'p' => (int) ($point['players'] ?? 0),
                ];

                $payload[] = (string) json_encode($row);
                $last = $row;
            }

            if ($payload === []) {
                return;
            }

            RedisFacade::connection('default')->rpush($key, ...$payload);
            RedisFacade::connection('default')->expire($key, $ttl);

            if ($last !== null) {
                $this->applyHotValues($serverId, $last);
            }
        } catch (\Throwable $e) {
            Log::warning('metrics: ошибка записи точки: '.$e->getMessage());
        }
    }

    private function applyHotValues(int $serverId, array $row): void
    {
        DB::table('servers')
            ->where('id', $serverId)
            ->update([
                'cpu_usage' => $row['c'],
                'memory_usage_mb' => $row['m'],
                'disk_used_mb' => $row['d'],
                'network_in_mbps' => $row['ni'],
                'network_out_mbps' => $row['no'],
                'players_online' => $row['p'],
                'metrics_at' => now(),
            ]);
    }

    /**
     * Данные для графика: сырые точки из Redis или агрегаты из MySQL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function series(Server $server, string $range = '1h'): array
    {
        [$seconds, $aggregate] = $this->rangeConfig($range);

        if ($aggregate) {
            return $this->hourlySeries($server, $seconds);
        }

        return $this->rawSeries($server, $seconds);
    }

    private function rawSeries(Server $server, int $seconds): array
    {
        try {
            $limit = (int) ceil($seconds / (int) setting('hosting.monitoring.interval', 5)) + 10;
            $raw = RedisFacade::connection('default')
                ->lrange($this->seriesKey($server->id), -$limit, -1);
        } catch (\Throwable) {
            return [];
        }

        $cutoff = time() - $seconds;
        $out = [];

        foreach ($raw as $item) {
            $row = json_decode((string) $item, true);
            if (! is_array($row) || ($row['t'] ?? 0) < $cutoff) {
                continue;
            }

            $out[] = [
                't' => $row['t'],
                'cpu' => $row['c'],
                'memory' => $row['m'],
                'players' => $row['p'],
                'net_in' => $row['ni'],
                'net_out' => $row['no'],
            ];
        }

        return $out;
    }

    private function hourlySeries(Server $server, int $seconds): array
    {
        $since = now()->subSeconds($seconds)->timestamp;

        return MetricHourly::where('server_id', $server->id)
            ->where('hour', '>=', $since - ($since % 3600))
            ->orderBy('hour')
            ->get()
            ->map(fn (MetricHourly $m) => [
                't' => $m->hour,
                'cpu' => (float) $m->cpu_avg,
                'cpu_max' => (float) $m->cpu_max,
                'memory' => (int) $m->memory_avg_mb,
                'players' => (int) $m->players_max,
                'net_in' => (int) round($m->network_in_mb / 60),
                'net_out' => (int) round($m->network_out_mb / 60),
                'restarts' => (int) $m->restarts,
                'uptime' => (float) $m->uptime_percent,
            ])
            ->all();
    }

    /** @return array{0:int,1:bool} */
    private function rangeConfig(string $range): array
    {
        return match ($range) {
            '15m' => [900, false],
            '1h' => [3600, false],
            '6h' => [21600, false],
            '24h' => [86400, true],
            '7d' => [604800, true],
            '30d' => [2592000, true],
            default => [3600, false],
        };
    }

    /**
     * Агрегация сырых точек в metric_hourly. Запускается раз в минуту.
     */
    public function aggregate(int $minutes = 5): int
    {
        $from = time() - ($minutes * 60);
        $rows = 0;

        $servers = Server::query()
            ->where('metrics_at', '>=', now()->subMinutes($minutes + 1))
            ->whereNotNull('game_port')
            ->limit(2000)
            ->get();

        foreach ($servers as $server) {
            $points = $this->rawSeries($server, $minutes * 60 + 120);

            $filtered = array_values(array_filter(
                $points,
                static fn (array $p) => $p['t'] >= $from,
            ));

            if (count($filtered) < 2) {
                continue;
            }

            $cpu = array_column($filtered, 'cpu');
            $mem = array_column($filtered, 'memory');
            $players = array_column($filtered, 'players');

            $hour = (int) (now()->startOfHour()->timestamp);

            MetricHourly::updateOrCreate(
                ['server_id' => $server->id, 'hour' => $hour],
                [
                    'node_id' => $server->node_id,
                    'cpu_avg' => round(array_sum($cpu) / count($cpu), 2),
                    'cpu_max' => round(max($cpu), 2),
                    'memory_avg_mb' => (int) round(array_sum($mem) / count($mem)),
                    'memory_max_mb' => (int) max($mem),
                    'disk_used_mb' => (int) $server->disk_used_mb,
                    'network_in_mb' => (int) max($server->network_in_mbps, 0) * $minutes,
                    'network_out_mb' => (int) max($server->network_out_mbps, 0) * $minutes,
                    'players_avg' => round(array_sum($players) / count($players), 2),
                    'players_max' => (int) max($players),
                    'samples' => count($filtered),
                    'restarts' => 0,
                    'uptime_percent' => $server->isRunning() ? 100 : 0,
                ],
            );

            $rows++;
        }

        return $rows;
    }

    /** Обновление публичных снапшотов (раз в 2 минуты). */
    public function refreshPublicSnapshots(): int
    {
        if (! setting_bool('hosting.monitoring.public.enabled', true)) {
            return 0;
        }

        $showNames = setting_bool('hosting.monitoring.public.show_names', true);
        $showAddress = setting_bool('hosting.monitoring.public.show_address', true);
        $showPlayers = setting_bool('hosting.monitoring.public.show_player_count', true);
        $minUptime = (int) setting('hosting.monitoring.public.min_uptime_for_public', 3600);

        $servers = Server::query()
            ->with('game')
            ->where('status', Server::STATUS_RUNNING)
            ->whereNull('deleted_at')
            ->where('uptime_seconds', '>=', $minUptime)
            ->where('expires_at', '>', now())
            ->limit(500)
            ->get();

        $seen = [];
        $count = 0;

        foreach ($servers as $server) {
            $snapshot = PublicStatusSnapshot::updateOrCreate(
                ['server_id' => $server->id],
                [
                    'node_id' => $server->node_id,
                    'game_id' => $server->game_id,
                    'name' => $showNames ? $server->name : $server->game->name.' #'.$server->id,
                    'address' => $showAddress ? (string) $server->address : $server->game->name,
                    'slots' => $showPlayers ? (int) $server->slots : 0,
                    'players' => $showPlayers ? (int) $server->players_online : 0,
                    'uptime_seconds' => (int) $server->uptime_seconds,
                    'status' => $server->isRunning() ? 'online' : 'offline',
                    'checked_at' => now(),
                ],
            );

            $seen[] = $server->id;
            $count++;
        }

        // Серверы, которые исчезли с публичного списка
        PublicStatusSnapshot::whereNotIn('server_id', $seen ?: [0])->delete();

        return $count;
    }

    /** Сводка по ноде для графиков в админке. */
    public function recordNodeHealth(Node $node, array $system): void
    {
        NodeHealthLog::create([
            'node_id' => $node->id,
            'cpu_percent' => (int) ($system['cpu_percent'] ?? 0),
            'memory_used_mb' => (int) ($system['used_memory_mb'] ?? 0),
            'memory_total_mb' => (int) ($system['memory_total_mb'] ?? 0),
            'disk_used_mb' => (int) ($system['used_disk_mb'] ?? 0),
            'disk_total_mb' => (int) ($system['disk_total_mb'] ?? 0),
            'network_in_mbps' => (int) ($system['network_in_mbps'] ?? 0),
            'network_out_mbps' => (int) ($system['network_out_mbps'] ?? 0),
            'running_servers' => (int) ($system['running_servers'] ?? 0),
            'load_1' => (float) ($system['load_1'] ?? 0),
            'latency_ms' => $system['latency_ms'] ?? null,
            'is_online' => true,
        ]);
    }

    private function seriesKey(int $serverId): string
    {
        return 'gamedock:metrics:'.$serverId;
    }
}
