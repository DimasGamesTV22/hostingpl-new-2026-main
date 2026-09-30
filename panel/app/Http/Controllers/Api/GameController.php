<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Games\StartupBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function __construct(private readonly StartupBuilder $startup) {}

    /** Публичный список игр. */
    public function publicIndex(): JsonResponse
    {
        return response()->json([
            'data' => Game::public()->ordered()->get()->map(fn (Game $g) => $this->summary($g)),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $games = Game::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->public())
            ->when($request->query('family'), fn ($q, $f) => $q->where('family', $f))
            ->ordered()
            ->get()
            ->map(fn (Game $g) => $this->summary($g));

        return response()->json(['data' => $games]);
    }

    public function show(Game $game): JsonResponse
    {
        $this->authorize('viewAny', Game::class);

        return response()->json([
            'data' => array_merge($this->summary($game), [
                'description' => $game->description,
                'startup' => $game->startup,
                'installer' => $game->installer,
                'config_files' => $game->config_files,
                'builds' => $game->buildList(),
                'templates' => $game->templates()->active()->ordered()->get()->map(fn ($t) => [
                    'slug' => $t->slug,
                    'name' => $t->name,
                    'type' => $t->type,
                    'version' => $t->version,
                    'description' => $t->description,
                    'installed_by_default' => false,
                ]),
            ]),
        ]);
    }

    private function summary(Game $game): array
    {
        return [
            'id' => $game->id,
            'slug' => $game->slug,
            'name' => $game->name,
            'family' => $game->family,
            'short_description' => $game->short_description,
            'icon' => $game->icon,
            'banner' => $game->banner,
            'slots' => [
                'min' => $game->min_slots,
                'max' => $game->max_slots,
                'default' => $game->default_slots,
            ],
            'resources' => [
                'memory_mb' => $game->default_memory_mb,
                'min_memory_mb' => $game->min_memory_mb,
                'cpu_percent' => $game->default_cpu_percent,
                'disk_mb' => $game->default_disk_mb,
            ],
            'price_per_slot_month' => (float) $game->price_per_slot_month,
            'features' => [
                'rcon' => $game->supports_rcon,
                'query' => $game->supports_query,
                'plugins' => $game->supports_plugins,
                'bedrock' => $game->supports_bedrock,
                'builds' => $game->supports_custom_builds,
                'auto_update' => $game->supports_auto_update,
            ],
        ];
    }
}
