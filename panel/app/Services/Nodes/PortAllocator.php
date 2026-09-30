<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use App\Models\ResourceAllocation;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Выдача и освобождение портов.
 *
 * Порты хранятся в таблице resource_allocations — это защищает от двух
 * одновременных запросов: уникальный индекс (node_id, port) не даст выдать
 * один порт дважды, а вся выдача оборачивается в транзакцию.
 */
class PortAllocator
{
    public const KIND_GAME = 'game';
    public const KIND_QUERY = 'query';
    public const KIND_RCON = 'rcon';

    /**
     * Выделить порт для сервера.
     *
     * @param  string|null  $preferredPort  запрос на конкретный порт (покупка)
     */
    public function allocate(
        Server $server,
        ?string $kind = null,
        ?int $preferredPort = null,
        bool $isPrimary = false,
    ): int {
        $node = $server->node;

        if (! $node) {
            throw new \RuntimeException('У сервера не назначена нода.');
        }

        $kinds = $kind !== null ? [$kind] : [self::KIND_GAME, self::KIND_QUERY, self::KIND_RCON];
        $assigned = [];

        DB::transaction(function () use ($server, $node, $kinds, $preferredPort, $isPrimary, &$assigned) {
            foreach ($kinds as $k) {
                $port = $preferredPort && $k === self::KIND_GAME
                    ? $this->claimPreferred($node, (int) $preferredPort)
                    : $this->claimFree($node, $k);

                if ($port === null) {
                    throw new \RuntimeException(__('nodes.errors.no_free_port', ['kind' => $k]));
                }

                ResourceAllocation::create([
                    'node_id' => $node->id,
                    'server_id' => $server->id,
                    'kind' => $k,
                    'port' => $port,
                    'is_primary' => $k === self::KIND_GAME || $isPrimary,
                ]);

                $assigned[$k] = $port;
            }
        });

        foreach ($assigned as $k => $port) {
            match ($k) {
                self::KIND_GAME => $server->game_port = $port,
                self::KIND_QUERY => $server->query_port = $port,
                self::KIND_RCON => $server->rcon_port = $port,
            };
        }

        $server->address = $this->composeAddress($node, (int) $server->game_port);
        $server->query_address = $this->composeAddress($node, (int) $server->query_port);
        $server->save();

        return (int) $server->game_port;
    }

    /** Покупка конкретного порта: диапазоны и цены берём из config/hosting.php. */
    public function claimPreferred(Node $node, int $port): ?int
    {
        if ($this->isTaken($node, $port)) {
            return null;
        }

        $ranges = (array) setting('hosting.ports.range.game', [25000, 25999]);
        $autoRange = (int) $ranges[0];

        // Явно купленные «красивые» порты
        $purchasable = (array) setting('hosting.ports.allow_purchase_prices', []);
        foreach ($purchasable as $band) {
            if ($port >= (int) $band['from'] && $port <= (int) $band['to']) {
                return $port;
            }
        }

        if ($port >= $autoRange && $port <= (int) $ranges[1]) {
            return $port;
        }

        return null;
    }

    private function claimFree(Node $node, string $kind): ?int
    {
        $range = (array) setting('hosting.ports.range.'.$kind, [25000, 25999]);
        [$from, $to] = [(int) $range[0], (int) $range[1]];

        // Один запрос вместо тысячи: берём занятые порты и ищем первую дыру
        $used = ResourceAllocation::where('node_id', $node->id)
            ->whereBetween('port', [$from, $to])
            ->orderBy('port')
            ->pluck('port')
            ->map(static fn ($p) => (int) $p)
            ->all();

        $candidate = $from;
        foreach ($used as $port) {
            if ($port === $candidate) {
                $candidate++;

                continue;
            }

            if ($port > $candidate) {
                return $candidate;
            }
        }

        return $candidate <= $to ? $candidate : null;
    }

    private function isTaken(Node $node, int $port): bool
    {
        return ResourceAllocation::where('node_id', $node->id)->where('port', $port)->exists();
    }

    /** Перевести сервер на другой порт (например, при переезде на ноду). */
    public function movePort(Server $server, Node $newNode): void
    {
        $this->releaseAll($server);

        $server->node_id = $newNode->id;
        $server->save();

        $this->allocate($server);
    }

    public function releaseAll(Server $server): void
    {
        ResourceAllocation::where('server_id', $server->id)->delete();
    }

    public function release(Node $node, int $port): void
    {
        ResourceAllocation::where('node_id', $node->id)->where('port', $port)->delete();
    }

    /** Проверка, что порт свободен (для валидации форм). */
    public function isAvailable(Node $node, int $port, ?int $ignoreServerId = null): bool
    {
        $query = ResourceAllocation::where('node_id', $node->id)->where('port', $port);

        if ($ignoreServerId) {
            $query->where(fn ($q) => $q->where('server_id', $ignoreServerId)->orWhereNull('server_id'));
        }

        return ! $query->exists();
    }

    /**
     * Стоимость «красивого» порта по диапазонам из настроек.
     */
    public function purchasePrice(int $port): ?float
    {
        $bands = (array) setting('hosting.ports.allow_purchase_prices', []);

        foreach ($bands as $band) {
            if ($port >= (int) $band['from'] && $port <= (int) $band['to']) {
                return (float) $band['price'];
            }
        }

        return null;
    }

    /** Список покупаемых диапазонов для интерфейса. */
    public function purchasableRanges(): array
    {
        $out = [];

        foreach ((array) setting('hosting.ports.allow_purchase_prices', []) as $key => $band) {
            $out[] = [
                'key' => $key,
                'range' => $band['from'].'–'.$band['to'],
                'price' => money($band['price']),
                'free' => $this->countFreeInBand((int) $band['from'], (int) $band['to']),
            ];
        }

        return $out;
    }

    private function countFreeInBand(int $from, int $to): int
    {
        $used = ResourceAllocation::whereBetween('port', [$from, $to])->count();

        return max(0, ($to - $from + 1) - $used);
    }

    public function composeAddress(Node $node, ?int $port): ?string
    {
        if (! $port) {
            return null;
        }

        $host = $node->flagship ?: $node->host;

        return $host ? $host.':'.$port : (string) $port;
    }

    /**
     * Импорт всех свободных портов в пул ноды (разовое заполнение таблицы),
     * чтобы портрет ноды в админке показывал занятость без обращения к агенту.
     */
    public function seedPool(Node $node, ?int $limit = 2000): int
    {
        $created = 0;

        $ranges = (array) setting('hosting.ports.range', []);

        foreach ($ranges as $kind => $range) {
            [$from, $to] = [(int) $range[0], (int) $range[1]];
            $count = min($limit, $to - $from + 1);

            for ($i = 0; $i < $count; $i++) {
                $port = $from + $i;

                if (ResourceAllocation::where('node_id', $node->id)->where('port', $port)->exists()) {
                    continue;
                }

                ResourceAllocation::create([
                    'node_id' => $node->id,
                    'kind' => $kind,
                    'port' => $port,
                    'is_reserved' => true,
                ]);

                $created++;
            }
        }

        return $created;
    }

    public function nodePoolUsage(Node $node): array
    {
        $all = ResourceAllocation::where('node_id', $node->id)->get();
        $used = $all->whereNotNull('server_id')->count();

        return [
            'total' => $all->count(),
            'used' => $used,
            'free' => $all->count() - $used,
            'by_kind' => $all->groupBy('kind')->map->count(),
        ];
    }
}
