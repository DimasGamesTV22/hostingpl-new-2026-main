<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Monitoring\ConsoleBroadcaster;
use App\Services\Monitoring\MetricsCollector;
use App\Services\SecretCodes\SecretCodeResolver;
use App\Services\Servers\ServerEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Входящие HTTP-вебхуки от агентов.
 * Используется нодами, у которых нет постоянного WSS-соединения
 * (например, за NAT без статического IP, либо как резервный канал).
 */
class AgentWebhookController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Node $node */
        $node = $request->attributes->get('agent_node');

        $system = (array) $request->input('system', []);

        $node->forceFill([
            'status' => Node::STATUS_ONLINE,
            'last_heartbeat_at' => now(),
            'missed_heartbeats' => 0,
            'cpu_cores' => $system['cpu_cores'] ?? $node->cpu_cores,
            'memory_total_mb' => $system['memory_total_mb'] ?? $node->memory_total_mb,
            'disk_total_mb' => $system['disk_total_mb'] ?? $node->disk_total_mb,
            'disk_free_mb' => $system['disk_free_mb'] ?? $node->disk_free_mb,
            'used_memory_mb' => $system['used_memory_mb'] ?? $node->used_memory_mb,
            'used_disk_mb' => $system['used_disk_mb'] ?? $node->used_disk_mb,
            'load_1' => $system['load_1'] ?? $node->load_1,
            'load_5' => $system['load_5'] ?? $node->load_5,
            'load_15' => $system['load_15'] ?? $node->load_15,
            'agent_version' => $request->input('version', $node->agent_version),
            'runtimes_available' => $request->input('runtimes', $node->runtimes_available),
            'inbound_ip' => $request->ip(),
        ])->save();

        return response()->json(['ok' => true, 'time' => now()->timestamp]);
    }

    public function serverStatus(Request $request): JsonResponse
    {
        $server = $this->findServer($request);

        if (! $server) {
            return response()->json(['message' => 'Server not found'], 404);
        }

        $status = (string) $request->input('status', Server::STATUS_STOPPED);

        $server->forceFill(array_filter([
            'status' => $status,
            'status_reason' => $request->input('reason'),
            'external_id' => $request->input('external_id'),
            'last_started_at' => $status === Server::STATUS_RUNNING ? now() : null,
            'last_stopped_at' => in_array($status, [Server::STATUS_STOPPED, Server::STATUS_CRASHED], true) ? now() : null,
            'last_crash_at' => $status === Server::STATUS_CRASHED ? now() : null,
            'crash_count' => $status === Server::STATUS_CRASHED ? (int) $server->crash_count + 1 : null,
        ], static fn ($v) => $v !== null))->save();

        app(ConsoleBroadcaster::class)->status($server->id, $status, $request->input('reason'));

        if ($status === Server::STATUS_RUNNING && $request->input('auto_start')) {
            app(\App\Services\Servers\Provisioner::class)->log($server, 'power', __('servers.events.started'), 'info');
        }

        return response()->json(['ok' => true]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $collector = app(MetricsCollector::class);
        $count = 0;

        foreach ((array) $request->input('servers', []) as $item) {
            $serverId = (int) ($item['server_id'] ?? 0);

            if ($serverId && ! empty($item['points'])) {
                $collector->push($serverId, (array) $item['points']);
                $count += count((array) $item['points']);
            }
        }

        if (! empty($request->input('system'))) {
            /** @var Node $node */
            $node = $request->attributes->get('agent_node');
            $collector->recordNodeHealth($node, (array) $request->input('system'));
        }

        return response()->json(['ok' => true, 'points' => $count]);
    }

    public function console(Request $request): JsonResponse
    {
        $server = $this->findServer($request);

        if (! $server) {
            return response()->json(['message' => 'Server not found'], 404);
        }

        $lines = (array) $request->input('lines', []);

        if ($lines === []) {
            return response()->json(['ok' => true, 'lines' => 0]);
        }

        $broadcaster = app(ConsoleBroadcaster::class);

        foreach ($lines as $line) {
            $broadcaster->push($server->id, is_array($line) ? $line : ['text' => (string) $line]);
        }

        if (! empty($request->input('stats'))) {
            $broadcaster->stats($server->id, (array) $request->input('stats'));
        }

        return response()->json(['ok' => true, 'lines' => count($lines)]);
    }

    public function install(Request $request): JsonResponse
    {
        $server = $this->findServer($request);

        if (! $server) {
            return response()->json(['message' => 'Server not found'], 404);
        }

        $event = (string) $request->input('event', 'progress');

        match ($event) {
            'progress' => $server->forceFill([
                'status' => Server::STATUS_INSTALLING,
                'install_progress' => (int) $request->input('progress', 0),
            ])->save(),

            'completed' => $server->forceFill([
                'status' => Server::STATUS_INSTALLED,
                'install_progress' => 100,
                'installed_at' => now(),
                'external_id' => $request->input('external_id'),
                'install_manifest' => $request->input('files'),
            ])->save(),

            'failed' => $server->forceFill([
                'status' => Server::STATUS_ERROR,
                'status_reason' => mb_substr((string) $request->input('error', 'Ошибка установки'), 0, 250),
            ])->save(),

            default => null,
        };

        if (! empty($request->input('log'))) {
            $server->forceFill([
                'install_log' => mb_substr((string) $request->input('log'), -60000),
            ])->saveQuietly();
        }

        if (! empty($request->input('step'))) {
            \App\Models\ServerInstallLog::create([
                'server_id' => $server->id,
                'step' => (int) $request->input('step', 0),
                'name' => (string) $request->input('name', ''),
                'status' => $request->input('status', 'running'),
                'output' => $request->input('output'),
                'progress' => (int) $request->input('progress', 0),
                'duration_ms' => (int) $request->input('duration_ms', 0),
                'error' => $request->input('error'),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function chat(Request $request): JsonResponse
    {
        $server = $this->findServer($request);

        if (! $server) {
            return response()->json(['message' => 'Server not found'], 404);
        }

        $result = app(SecretCodeResolver::class)->handleChatMessage([
            'server_id' => $server->id,
            'text' => (string) $request->input('text', ''),
            'player_id' => $request->input('player_id'),
            'player_name' => $request->input('player_name'),
            'ip' => $request->input('ip', $request->ip()),
        ]);

        if (! $result['matched'] || ! $result['code']) {
            return response()->json(['ok' => true, 'matched' => false]);
        }

        return response()->json([
            'ok' => true,
            'matched' => true,
            'message' => $result['message'],
            'reward' => $result['code']->rewardLabel(),
        ]);
    }

    public function backup(Request $request): JsonResponse
    {
        $server = $this->findServer($request);

        if (! $server) {
            return response()->json(['message' => 'Server not found'], 404);
        }

        $service = app(\App\Services\Backups\BackupService::class);

        if ($request->input('status') === 'failed') {
            $service->markFailed($server, (array) $request->all());
        } else {
            $service->markCompleted($server, (array) $request->all());
        }

        return response()->json(['ok' => true]);
    }

    private function findServer(Request $request): ?Server
    {
        if ($uuid = $request->input('uuid')) {
            return Server::where('uuid', $uuid)->first();
        }

        if ($id = $request->input('server_id')) {
            /** @var Node $node */
            $node = $request->attributes->get('agent_node');

            return Server::where('id', (int) $id)
                ->where('node_id', $node->id)
                ->first();
        }

        if ($external = $request->input('external_id')) {
            return Server::where('external_id', $external)->first();
        }

        return null;
    }
}
