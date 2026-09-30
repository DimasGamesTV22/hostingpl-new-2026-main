<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Server;
use App\Services\Monitoring\ConsoleBroadcaster;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Monitoring\AlertService;
use App\Services\Public\PublicStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Публичные эндпоинты без авторизации: статистика и статус.
 */
class PublicStatusController extends Controller
{
    public function __construct(private readonly PublicStatusService $public) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->public->servers(
                $request->query('game'),
                $request->query('q'),
                (int) $request->query('limit', 100),
            ),
        ]);
    }

    public function nodes(): JsonResponse
    {
        $nodes = Node::where('status', Node::STATUS_ONLINE)
            ->get()
            ->map(fn (Node $n) => [
                'id' => $n->id,
                'name' => $n->name,
                'country' => $n->country,
                'region' => $n->region,
                'flag' => $n->flagEmoji(),
                'servers' => (int) $n->total_servers,
                'running' => (int) $n->running_servers,
                'uptime_percent' => $n->uptimePercent(1),
            ]);

        return response()->json(['data' => $nodes]);
    }

    public function stats(): JsonResponse
    {
        return response()->json(['data' => $this->public->stats()]);
    }
}
