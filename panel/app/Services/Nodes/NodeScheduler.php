<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Game;
use App\Models\Node;
use App\Models\Server;
use App\Support\Crypto;
use Illuminate\Support\Str;

/**
 * Выбор ноды для нового сервера.
 *
 * Три режима (config/hosting.php → node_mode, меняется в админке):
 *   single — единственная нода, ничего не выбираем
 *   manual — ноду выбирает администратор вручную
 *   auto   — панель распределяет сама:
 *              • фильтрует по региону (если указал пользователь),
 *              • отсекает ноды без нужного рантайма и без запаса ресурсов,
 *              • сортирует по «свободности» (память + диск + слоты),
 *              • учитывает weight и region_priority.
 */
class NodeScheduler
{
    /**
     * @return array{node: ?Node, candidates: \Illuminate\Support\Collection<int, Node>, reason: ?string}
     */
    public function pick(
        Game $game,
        int $memoryMb,
        int $diskMb,
        ?string $runtime = null,
        ?string $region = null,
        ?int $excludeNodeId = null,
        ?string $country = null,
    ): array {
        $runtime ??= default_runtime();
        $mode = node_mode();

        $candidates = Node::query()
            ->active()
            ->when($excludeNodeId, fn ($q) => $q->where('id', '!=', $excludeNodeId))
            ->get()
            ->filter(function (Node $node) use ($game, $memoryMb, $diskMb, $runtime, $region, $country) {
                if (! $node->isUsable()) {
                    return false;
                }

                if (! $node->supportsRuntime($runtime)) {
                    return false;
                }

                if ($node->serverCount() >= (int) $node->max_servers) {
                    return false;
                }

                if ($node->freeMemoryMb() < $memoryMb) {
                    return false;
                }

                if ($node->freeDiskMb() < $diskMb) {
                    return false;
                }

                if ($region && $node->region !== $region && ! $node->prefer_over_region) {
                    return false;
                }

                if ($country && $node->country && strtoupper($node->country) !== strtoupper($country)) {
                    return false;
                }

                // Нода должна уметь отдавать нужный образ, если он требуется
                if ($game->image && ! $node->supportsRuntime($runtime)) {
                    return false;
                }

                return true;
            })
            ->values();

        if ($candidates->isEmpty()) {
            return [
                'node' => null,
                'candidates' => $candidates,
                'reason' => $this->diagnose($game, $memoryMb, $diskMb, $runtime),
            ];
        }

        if ($mode === 'single') {
            return ['node' => $candidates->first(), 'candidates' => $candidates, 'reason' => null];
        }

        $best = $candidates->sortByDesc(fn (Node $n) => $this->score($n, $memoryMb, $diskMb))->first();

        return ['node' => $best, 'candidates' => $candidates, 'reason' => null];
    }

    /** Ручной выбор админом: проверяем, что выбранная нода вообще подходит. */
    public function validate(Node $node, Game $game, int $memoryMb, int $diskMb, ?string $runtime = null): ?string
    {
        $runtime ??= default_runtime();

        if (! $node->is_active) {
            return __('nodes.errors.disabled');
        }

        if (! $node->isOnline()) {
            return __('nodes.errors.offline');
        }

        if ($node->status === Node::STATUS_MAINTENANCE) {
            return __('nodes.errors.maintenance');
        }

        if (! $node->supportsRuntime($runtime)) {
            return __('nodes.errors.no_runtime', ['runtime' => $runtime]);
        }

        if ($node->serverCount() >= (int) $node->max_servers) {
            return __('nodes.errors.max_servers');
        }

        if ($node->freeMemoryMb() < $memoryMb) {
            return __('nodes.errors.not_enough_memory', [
                'need' => mb_gb($memoryMb),
                'free' => mb_gb($node->freeMemoryMb()),
            ]);
        }

        if ($node->freeDiskMb() < $diskMb) {
            return __('nodes.errors.not_enough_disk', [
                'need' => mb_gb($diskMb),
                'free' => mb_gb($node->freeDiskMb()),
            ]);
        }

        return null;
    }

    /**
     * Балл ноды: чем больше свободно и чем ближе регион — тем лучше.
     * Значение используется только для сортировки.
     */
    public function score(Node $node, int $memoryMb, int $diskMb): float
    {
        $memAlloc = max(1, $node->allocatableMemoryMb());
        $memFree = max(1, $node->freeMemoryMb());
        $diskAlloc = max(1, $node->allocatableDiskMb());
        $diskFree = max(1, $node->freeDiskMb());

        $memoryRatio = min(1.0, $memFree / max(1, $memoryMb));
        $diskRatio = min(1.0, $diskFree / max(1, $diskMb));
        $serverSlots = 1 - ($node->serverCount() / max(1, (int) $node->max_servers));

        $weight = max(1, (int) $node->weight) / 100;
        $regionBonus = ((int) $node->region_priority) / 100;

        $balance = ($memoryRatio * 0.45) + ($diskRatio * 0.2) + (max(0, $serverSlots) * 0.2) + 0.15;

        return round($balance * $weight + $regionBonus, 4);
    }

    /** Человеческое объяснение, почему нода не нашлась. */
    private function diagnose(Game $game, int $memoryMb, int $diskMb, string $runtime): string
    {
        $all = Node::query()->count();

        if ($all === 0) {
            return __('nodes.errors.no_nodes');
        }

        $online = Node::query()->online()->count();
        if ($online === 0) {
            return __('nodes.errors.all_offline');
        }

        $withRuntime = Node::query()->online()
            ->get()
            ->filter(fn (Node $n) => $n->supportsRuntime($runtime))
            ->count();

        if ($withRuntime === 0) {
            return __('nodes.errors.no_runtime', ['runtime' => $runtime]);
        }

        $withMemory = Node::query()->online()
            ->get()
            ->filter(fn (Node $n) => $n->freeMemoryMb() >= $memoryMb)
            ->count();

        if ($withMemory === 0) {
            return __('nodes.errors.not_enough_memory_global', ['need' => mb_gb($memoryMb)]);
        }

        return __('nodes.errors.no_match');
    }

    /**
     * Пересчёт занятых ресурсов ноды по её серверам.
     * Вызывается после создания/удаления сервера и при восстановлении из бэкапа.
     */
    public function recalculateUsage(Node $node): void
    {
        $servers = $node->servers()->get();

        $node->forceFill([
            'used_memory_mb' => (int) $servers->sum('memory_mb'),
            'used_disk_mb' => (int) $servers->sum('disk_mb'),
            'total_servers' => $servers->count(),
            'running_servers' => $servers->where('status', Server::STATUS_RUNNING)->count(),
        ])->save();
    }

    /**
     * Пересчёт занятости всех нод (для cron-команды).
     */
    public function recalculateAll(): int
    {
        $count = 0;

        Node::query()->each(function (Node $node) {
            $this->recalculateUsage($node);
            $count++;
        });

        return $count;
    }

    /** Регион по умолчанию для пользователя (по его прошлым серверам или IP). */
    public function preferredRegion(?Server $previous = null): ?string
    {
        if ($previous?->node?->region) {
            return $previous->node->region;
        }

        $cookie = request()?->cookie('gd_region');

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    /** Команда подключения агента — показывается в админке при добавлении ноды. */
    public function installCommand(Node $node): string
    {
        $token = $node->plainToken() ?: ($node->token ? Crypto::decrypt($node->token) : '');

        return sprintf(
            'sudo ./agent.sh --node %s --token %s --panel %s --runtime %s',
            $node->id,
            $token ?: Str::random(12),
            config('app.url'),
            $node->runtime,
        );
    }
}
