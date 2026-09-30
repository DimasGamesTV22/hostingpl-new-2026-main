<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Трансляция консоли игрового сервера в браузер.
 *
 * Агент шлёт строки по WSS → WSS-сервер складывает их в Redis-лист →
 * SSE-контроллер отдаёт их в браузер (Alpine.js). Консоль остаётся живой,
 * а панель не держит ни одного PHP-процесса на каждого зрителя дольше запроса.
 */
class ConsoleBroadcaster
{
    public const TTL = 600;

    public function push(int $serverId, array $line): void
    {
        try {
            $key = $this->key($serverId);
            $buffer = Cache::get($key, []);

            $line['ts'] ??= now()->timestamp;
            $line = array_slice($line, 0, 4);

            $buffer[] = $line;

            if (count($buffer) > (int) setting('hosting.logs.realtime_buffer', 2000)) {
                $buffer = array_slice($buffer, -500);
            }

            Cache::put($key, $buffer, self::TTL);
        } catch (\Throwable $e) {
            Log::warning('Не удалось записать строку консоли: '.$e->getMessage());
        }
    }

    /** Забрать и очистить накопленное (для SSE-подписчика). */
    public function drain(int $serverId): array
    {
        $key = $this->key($serverId);
        $buffer = Cache::get($key, []);

        if ($buffer !== []) {
            Cache::put($key, [], self::TTL);
        }

        return $buffer;
    }

    /** Последние строки без очистки (при открытии консоли). */
    public function tail(int $serverId, int $lines = 200): array
    {
        $buffer = Cache::get($this->key($serverId), []);

        return array_slice($buffer, -$lines);
    }

    public function clear(int $serverId): void
    {
        Cache::forget($this->key($serverId));
    }

    public function system(int $serverId, string $text): void
    {
        $this->push($serverId, ['stream' => 'system', 'text' => $text, 'type' => 'system']);
    }

    public function stats(int $serverId, array $stats): void
    {
        try {
            Cache::put($this->statsKey($serverId), $stats, self::TTL);
        } catch (\Throwable) {
            // не критично
        }
    }

    public function getStats(int $serverId): array
    {
        return Cache::get($this->statsKey($serverId), []);
    }

    public function status(int $serverId, string $status, ?string $reason = null): void
    {
        $this->system($serverId, trim('── статус: '.$status.($reason ? ' ('.$reason.')' : '').' ──'));
    }

    private function key(int $serverId): string
    {
        return 'gamedock:console:'.$serverId;
    }

    private function statsKey(int $serverId): string
    {
        return 'gamedock:console-stats:'.$serverId;
    }
}
