<?php

declare(strict_types=1);

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Server;
use App\Services\Nodes\NodeScheduler;
use App\Services\Servers\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Перенос сервера на другую ноду. Только для администраторов:
 * игроки не должны выбирать, где физически стоит их сервер.
 */
class TransferController extends Controller
{
    public function __construct(
        private readonly NodeScheduler $scheduler,
        private readonly Provisioner $provisioner,
    ) {}

    public function index(Server $server): View
    {
        $this->authorize('transfer', $server);

        $server->load(['game', 'node']);

        $result = $this->scheduler->pick(
            game: $server->game,
            memoryMb: (int) $server->memory_mb,
            diskMb: (int) $server->disk_mb,
            runtime: (string) $server->runtime,
        );

        return view('panel.servers.transfer', [
            'server' => $server,
            'candidates' => $result['candidates'],
            'currentNode' => $server->node,
            'problem' => $result['reason'],
        ]);
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('transfer', $server);

        $data = $request->validate([
            'node_id' => ['required', 'integer', 'exists:nodes,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $target = Node::findOrFail($data['node_id']);

        $problem = $this->scheduler->validate(
            $target,
            $server->game,
            (int) $server->memory_mb,
            (int) $server->disk_mb,
            (string) $server->runtime,
        );

        if ($problem) {
            return back()->with('error', $problem);
        }

        if ((int) $target->id === (int) $server->node_id) {
            return back()->with('error', __('servers.errors.same_node'));
        }

        $oldNode = $server->node;

        $this->provisioner->log(
            $server,
            'setting',
            __('servers.events.transfer_started', ['from' => $oldNode?->name ?? '—', 'to' => $target->name]),
            'warning',
            ['reason' => $data['reason'] ?? null],
            $request->user(),
        );

        // Полная переустановка на новой ноде со свежими портами
        $server->forceFill([
            'node_id' => $target->id,
            'external_id' => null,
            'status' => Server::STATUS_PENDING,
            'status_reason' => null,
        ])->saveQuietly();

        app(\App\Services\Nodes\PortAllocator::class)->movePort($server, $target);

        $this->provisioner->reinstall($server);

        if ($oldNode) {
            $this->scheduler->recalculateUsage($oldNode);
        }

        return redirect()->route('panel.servers.show', $server)
            ->with('success', __('servers.messages.transfer_started', ['node' => $target->name]));
    }
}
