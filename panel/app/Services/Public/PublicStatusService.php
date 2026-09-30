<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Models\Game;
use App\Models\Node;
use App\Models\PublicStatusSnapshot;
use App\Models\Server;
use App\Models\Tariff;
use App\Models\User;
use App\Support\SettingRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Данные публичной части: лендинг, статус серверов, сводная статистика.
 */
class PublicStatusService
{
    public function __construct(private readonly SettingRepository $settings) {}

    /**
     * Список серверов для публичного мониторинга.
     *
     * @return array<int, array<string, mixed>>
     */
    public function servers(?string $game = null, ?string $search = null, int $limit = 100): array
    {
        if (! $this->settings->get('hosting.monitoring.public.enabled', true)) {
            return [];
        }

        $minutes = (int) ceil((int) $this->settings->get('hosting.monitoring.public.min_uptime_for_public', 3600) / 60);

        return PublicStatusSnapshot::query()
            ->with('game:id,name,slug,family,icon')
            ->when($game, fn ($q) => $q->whereHas('game', fn ($g) => $g->where('slug', $game)))
            ->when($search, fn ($q) => $q->where(function ($s) use ($search) {
                $s->where('name', 'like', '%'.$search.'%')
                    ->orWhere('address', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('players')
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get()
            ->map(fn (PublicStatusSnapshot $s) => [
                'id' => $s->server_id,
                'name' => $s->name,
                'game' => $s->game?->name,
                'game_slug' => $s->game?->slug,
                'family' => $s->game?->family,
                'icon' => $s->game?->icon,
                'address' => $s->address,
                'players' => $s->players,
                'slots' => $s->slots,
                'uptime' => duration_human((int) $s->uptime_seconds),
                'status' => $s->status,
                'checked_at' => $s->checked_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Детальная страница сервера в публичном статусе.
     *
     * @return array<string, mixed>|null
     */
    public function server(Server $server): ?array
    {
        if (! $this->settings->get('hosting.monitoring.public.enabled', true)) {
            return null;
        }

        $snapshot = PublicStatusSnapshot::where('server_id', $server->id)->first();

        if (! $snapshot) {
            return null;
        }

        return [
            'id' => $server->id,
            'name' => $snapshot->name,
            'game' => $server->game->name,
            'game_slug' => $server->game->slug,
            'family' => $server->game->family,
            'icon' => $server->game->icon,
            'address' => $snapshot->address,
            'players' => $snapshot->players,
            'slots' => $snapshot->slots,
            'uptime_seconds' => $server->uptime_seconds,
            'uptime' => duration_human((int) $server->uptime_seconds),
            'status' => $server->isRunning() ? 'online' : 'offline',
            'node' => $server->node?->name,
            'country' => $server->node?->country,
            'version' => $server->build_version,
            'last_update' => $server->metrics_at?->diffForHumans(),
        ];
    }

    /**
     * Сводная статистика хостинга (для главной страницы и API).
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return Cache::remember('gamedock:public:stats', 60, function () {
            $servers = Server::where('status', Server::STATUS_RUNNING)->count();
            $players = (int) Server::where('status', Server::STATUS_RUNNING)->sum('players_online');
            $users = User::where('status', User::STATUS_ACTIVE)->count();
            $games = Game::where('is_active', true)->where('is_public', true)->count();
            $nodes = Node::where('status', Node::STATUS_ONLINE)->count();

            $uptime = Cache::get('gamedock:public:uptime', 99.98);

            return [
                'servers' => $servers,
                'servers_total' => Server::count(),
                'players' => $players,
                'users' => $users,
                'games' => $games,
                'nodes' => $nodes,
                'online_nodes' => $nodes,
                'uptime' => round((float) $uptime, 2),
            ];
        });
    }

    /**
     * Последние анонсы для главной.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Announcement>
     */
    public function announcements(int $limit = 3)
    {
        return \App\Models\Announcement::published()->limit($limit)->get();
    }

    /**
     * Популярные игры для главной.
     *
     * @return \Illuminate\Support\Collection<int, Game>
     */
    public function featuredGames(int $limit = 8)
    {
        return Game::public()->ordered()->limit($limit)->get();
    }

    /**
     * Тарифы для лендинга.
     *
     * @return \Illuminate\Support\Collection<int, Tariff>
     */
    public function tariffs(bool $onlyPublic = true)
    {
        return Tariff::query()
            ->when($onlyPublic, fn ($q) => $q->public())
            ->ordered()
            ->get();
    }
}
