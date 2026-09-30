<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Server;
use App\Models\Tariff;
use App\Services\Billing\BillingService;
use App\Services\Servers\ProvisioningException;
use App\Services\Servers\Provisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function __construct(
        private readonly Provisioner $provisioner,
        private readonly BillingService $billing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $servers = $request->user()->servers()
            ->with('game:id,name,slug,family')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->limit((int) $request->query('limit', 50))
            ->get()
            ->map(fn (Server $s) => $this->summary($s));

        return response()->json(['data' => $servers]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Server::class);

        $game = Game::findOrFail($request->integer('game_id'));

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'tariff_id' => ['nullable', 'integer', 'exists:tariffs,id'],
            'memory_mb' => ['nullable', 'integer', 'min:512', 'max:65536'],
            'disk_mb' => ['nullable', 'integer', 'min:2048', 'max:1048576'],
            'slots' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'cpu_percent' => ['nullable', 'integer', 'min:10', 'max:800'],
            'build' => ['nullable', 'string', 'max:64'],
        ]);

        $data['memory_mb'] = max($game->min_memory_mb, (int) ($data['memory_mb'] ?? $game->default_memory_mb));
        $data['slots'] = max($game->min_slots, min($game->max_slots, (int) ($data['slots'] ?? $game->default_slots)));

        if ($tariff = Tariff::find($data['tariff_id'] ?? null)) {
            $data['memory_mb'] = min($data['memory_mb'], $tariff->memory_mb);
        }

        try {
            $server = $this->provisioner->create($request->user(), $game, $data);
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->billing->scheduleFirstCharge($server);

        return response()->json(['data' => $this->summary($server->refresh())], 201);
    }

    public function show(Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        return response()->json([
            'data' => array_merge($this->summary($server), [
                'build_version' => $server->build_version,
                'startup_command' => app(\App\Services\Games\StartupBuilder::class)->displayCommand($server),
                'watchdog_enabled' => $server->watchdog_enabled,
                'sub_accounts_enabled' => $server->sub_accounts_enabled,
                'node' => $server->node?->only(['id', 'name', 'country', 'region']),
                'tariff' => $server->tariff?->only(['id', 'slug', 'name']),
                'install_progress' => $server->install_progress,
            ]),
        ]);
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $this->authorize('update', $server);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'memory_mb' => ['sometimes', 'integer', 'min:512', 'max:65536'],
            'slots' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'disk_mb' => ['sometimes', 'integer', 'min:2048', 'max:1048576'],
            'watchdog_enabled' => ['sometimes', 'boolean'],
        ]);

        $this->provisioner->updateResources($server, $data, $request->user());

        return response()->json(['data' => $this->summary($server->refresh())]);
    }

    public function destroy(Server $server): JsonResponse
    {
        $this->authorize('delete', $server);

        $this->provisioner->delete($server, true, request()->user());

        return response()->json(null, 204);
    }

    public function start(Server $server): JsonResponse
    {
        $this->authorize('power', $server);

        $this->provisioner->start($server, request()->user());

        return response()->json(['ok' => true, 'status' => $server->fresh()->status]);
    }

    public function stop(Server $server): JsonResponse
    {
        $this->authorize('power', $server);

        $this->provisioner->stop($server, true, request()->user());

        return response()->json(['ok' => true, 'status' => $server->fresh()->status]);
    }

    public function restart(Server $server): JsonResponse
    {
        $this->authorize('power', $server);

        $this->provisioner->restart($server, request()->user());

        return response()->json(['ok' => true, 'status' => $server->fresh()->status]);
    }

    public function kill(Server $server): JsonResponse
    {
        $this->authorize('power', $server);

        $this->provisioner->kill($server, request()->user());

        return response()->json(['ok' => true, 'status' => $server->fresh()->status]);
    }

    public function console(Request $request, Server $server): JsonResponse
    {
        $this->authorize('console', $server);

        $lines = app(\App\Services\Monitoring\ConsoleBroadcaster::class)->tail($server->id, (int) $request->query('lines', 200));

        return response()->json([
            'data' => array_map(static fn (array $l) => [
                'stream' => $l['stream'] ?? 'stdout',
                'text' => $l['text'] ?? '',
                'ts' => $l['ts'] ?? null,
            ], $lines),
            'stats' => app(\App\Services\Monitoring\ConsoleBroadcaster::class)->getStats($server->id),
        ]);
    }

    public function sendConsole(Request $request, Server $server): JsonResponse
    {
        $this->authorize('console', $server);

        $data = $request->validate(['command' => ['required', 'string', 'max:2000']]);

        if (! $server->node) {
            return response()->json(['message' => 'Нода недоступна'], 503);
        }

        $result = app(\App\Services\Agent\AgentClient::class)
            ->writeConsole($server->node, $server, $data['command']);

        return response()->json(['ok' => $result['ok'], 'queued' => $result['mode'] === 'queue']);
    }

    public function metrics(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        $range = (string) $request->query('range', '1h');

        return response()->json([
            'data' => app(\App\Services\Monitoring\MetricsCollector::class)->series($server, $range),
            'range' => $range,
            'current' => [
                'cpu' => (float) $server->cpu_usage,
                'memory_mb' => (int) $server->memory_usage_mb,
                'players' => (int) $server->players_online,
                'slots' => (int) $server->slots,
                'uptime' => (int) $server->uptime_seconds,
            ],
        ]);
    }

    public function backups(Server $server): JsonResponse
    {
        $this->authorize('backup', $server);

        return response()->json([
            'data' => $server->snapshots()
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($s) => [
                    'uuid' => $s->uuid,
                    'name' => $s->name,
                    'type' => $s->type,
                    'status' => $s->status,
                    'size' => (int) $s->size_bytes,
                    'locked' => $s->is_locked,
                    'created_at' => $s->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function createBackup(Request $request, Server $server): JsonResponse
    {
        $this->authorize('backup', $server);

        $snapshot = app(\App\Services\Backups\BackupService::class)->create($server, $request->user(), [
            'name' => $request->input('name'),
        ]);

        return response()->json(['data' => ['uuid' => $snapshot->uuid, 'name' => $snapshot->name]], 201);
    }

    public function files(Request $request, Server $server): JsonResponse
    {
        $this->authorize('files', $server);

        $listing = app(\App\Services\Agent\FileService::class)
            ->list($server, (string) $request->query('path', '.'));

        return response()->json($listing);
    }

    public function events(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        return response()->json([
            'data' => $server->events()
                ->orderByDesc('created_at')
                ->limit((int) $request->query('limit', 50))
                ->get()
                ->map(fn ($e) => [
                    'type' => $e->type,
                    'level' => $e->level,
                    'title' => $e->title,
                    'context' => $e->context,
                    'created_at' => $e->created_at?->toIso8601String(),
                ]),
        ]);
    }

    private function summary(Server $server): array
    {
        return [
            'id' => $server->id,
            'uuid' => $server->uuid,
            'name' => $server->name,
            'status' => $server->status,
            'status_reason' => $server->status_reason,
            'game' => [
                'id' => $server->game_id,
                'name' => $server->game->name,
                'slug' => $server->game->slug,
            ],
            'address' => $server->address,
            'game_port' => $server->game_port,
            'query_port' => $server->query_port,
            'rcon_port' => $server->rcon_port,
            'slots' => (int) $server->slots,
            'players' => (int) $server->players_online,
            'resources' => [
                'memory_mb' => (int) $server->memory_mb,
                'cpu_percent' => (int) $server->cpu_percent,
                'disk_mb' => (int) $server->disk_mb,
                'network_mbps' => (int) $server->network_mbps,
                'pids' => (int) $server->pids,
            ],
            'usage' => [
                'cpu' => (float) $server->cpu_usage,
                'memory_mb' => (int) $server->memory_usage_mb,
                'disk_mb' => (int) $server->disk_used_mb,
            ],
            'expires_at' => $server->expires_at?->toIso8601String(),
            'is_frozen' => (bool) $server->is_frozen,
            'created_at' => $server->created_at?->toIso8601String(),
        ];
    }
}
