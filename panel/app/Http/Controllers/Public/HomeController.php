<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Game;
use App\Services\Public\PublicStatusService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(private readonly PublicStatusService $public) {}

    /**
     * Инвокаемый контроллер.
     *
     * Маршрут задан строкой без метода — Route::get('/', HomeController::class).
     * Laravel в этом случае требует __invoke и без него падает ещё на этапе
     * загрузки маршрутов: «Invalid route action», а не при первом запросе.
     */
    public function __invoke(): View
    {
        return $this->index();
    }

    public function index(): View
    {
        return view('public.index', [
            'stats' => $this->public->stats(),
            'tariffs' => $this->public->tariffs(),
            'games' => $this->public->featuredGames(8),
            'announcements' => $this->public->announcements(3),
            'servers' => $this->public->servers(limit: 8),
            'nodeMode' => node_mode(),
        ]);
    }

    public function games(Request $request): View
    {
        $family = $request->query('family');
        $search = $request->query('q');

        $games = Game::public()
            ->ordered()
            ->when($family, fn ($q) => $q->where('family', $family))
            ->when($search, function ($q) use ($search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn ($inner) => $inner
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$like]));
            })
            ->get();

        return view('public.games', [
            'games' => $games,
            'families' => Game::public()->distinct()->pluck('family')->sort()->all(),
            'family' => $family,
            'search' => $search,
        ]);
    }

    public function game(Game $game): View
    {
        abort_unless($game->is_active && $game->is_public, 404);

        $game->load(['templates' => fn ($q) => $q->active()->ordered()]);

        $servers = $this->public->servers(game: $game->slug, limit: 20);

        return view('public.game', [
            'game' => $game,
            'servers' => $servers,
            'tariffs' => $this->public->tariffs(),
        ]);
    }

    public function tariffs(): View
    {
        return view('public.tariffs', [
            'tariffs' => $this->public->tariffs(),
            'stats' => $this->public->stats(),
        ]);
    }
}
