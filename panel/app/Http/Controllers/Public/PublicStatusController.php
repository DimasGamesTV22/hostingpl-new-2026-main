<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Public\PublicStatusService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicStatusController extends Controller
{
    public function __construct(
        private readonly PublicStatusService $public,
        private readonly MetricsCollector $metrics,
    ) {}

    public function index(Request $request): View
    {
        $game = $request->query('game');
        $search = $request->query('q');

        return view('public.status', [
            'servers' => $this->public->servers($game, is_string($search) ? $search : null, 200),
            'games' => \App\Models\Game::public()->ordered()->get(),
            'stats' => $this->public->stats(),
            'game' => $game,
            'search' => $search,
            'refresh' => 30,
        ]);
    }

    public function show(Server $server): View
    {
        $data = $this->public->server($server);

        abort_if($data === null, 404);

        return view('public.server-status', [
            'server' => $server,
            'data' => $data,
            'series' => $this->metrics->series($server, '1h'),
        ]);
    }
}
