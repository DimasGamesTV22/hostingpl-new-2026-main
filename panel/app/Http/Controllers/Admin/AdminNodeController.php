<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\Agent\AgentClient;
use App\Services\Nodes\NodeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminNodeController extends Controller
{
    public function __construct(
        private readonly NodeManager $nodes,
        private readonly AgentClient $agent,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.nodes.index', [
            'nodes' => Node::withCount('servers')->orderByDesc('is_default')->orderBy('name')->get(),
            'modes' => ['single' => 'Одна нода', 'manual' => 'Ручной выбор', 'auto' => 'Автораспределение'],
            'currentMode' => node_mode(),
            'runtimes' => array_keys((array) config('hosting.runtime.available')),
        ]);
    }

    public function create(): View
    {
        return view('admin.nodes.edit', [
            'node' => new Node([
                'runtime' => default_runtime(),
                'connection_mode' => 'inbound',
                'max_servers' => 50,
                'allocatable_percent' => 85,
                'reserved_memory_mb' => 1024,
                'weight' => 100,
                'region_priority' => 50,
                'agent_port' => 9222,
                'is_active' => true,
            ]),
            'runtimes' => (array) config('hosting.runtime.available'),
            'regions' => $this->regions(),
        ]);
    }

    public function edit(Node $node): View
    {
        return view('admin.nodes.edit', [
            'node' => $node,
            'runtimes' => (array) config('hosting.runtime.available'),
            'regions' => $this->regions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validate($request);

        $node = $this->nodes->create($data);

        Auditor::log('admin.node_create', 'Добавлена нода «'.$node->name.'»', $node);

        return redirect()->route('admin.nodes.show', $node)
            ->with('success', __('admin.messages.node_created'))
            ->with('node_token', $node->plainToken());
    }

    public function show(Request $request, Node $node): View
    {
        return view('admin.nodes.show', [
            'node' => $node->loadCount('servers'),
            'diagnostics' => $this->nodes->diagnostics($node),
            'config' => $this->nodes->agentConfig($node),
            'servers' => $node->servers()->with('user:id,name', 'game:id,name')->orderByDesc('created_at')->limit(30)->get(),
            'health' => $node->healthLogs()->orderByDesc('created_at')->limit(60)->get(),
            'installCommand' => app(\App\Services\Nodes\NodeScheduler::class)->installCommand($node),
        ]);
    }

    public function update(Request $request, Node $node): RedirectResponse
    {
        $data = $this->validate($request, $node);

        $this->nodes->update($node, $data);

        Auditor::log('admin.node_update', 'Обновлена нода «'.$node->name.'»', $node);

        return back()->with('success', __('admin.messages.node_updated'));
    }

    public function ping(Node $node): JsonResponse
    {
        $result = $this->agent->ping($node);

        return response()->json([
            'ok' => $result['ok'],
            'mode' => $result['mode'],
            'error' => $result['error'],
            'online' => $this->agent->isOnline($node->id),
            'status' => $node->fresh()?->status,
        ]);
    }

    public function rotateToken(Node $node): RedirectResponse
    {
        $token = $this->nodes->rotateToken($node);

        Auditor::log('admin.node_token', 'Обновлён токен ноды «'.$node->name.'»', $node);

        return back()
            ->with('success', __('admin.messages.token_rotated'))
            ->with('node_token', $token);
    }

    public function seedPorts(Request $request, Node $node): RedirectResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:100', 'max:20000']]);

        $count = $this->nodes->seedPorts($node, (int) ($data['limit'] ?? 2000));

        return back()->with('success', __('admin.messages.ports_seeded', ['count' => $count]));
    }

    public function sync(Node $node): RedirectResponse
    {
        $this->nodes->syncRuntimes();

        return back()->with('success', __('admin.messages.nodes_synced'));
    }

    public function destroy(Node $node): RedirectResponse
    {
        try {
            $this->nodes->delete($node);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        Auditor::log('admin.node_delete', 'Удалена нода «'.$node->name.'»', null, ['id' => $node->id]);

        return redirect()->route('admin.nodes')->with('success', __('admin.messages.node_deleted'));
    }

    private function validate(Request $request, ?Node $node = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'connection_mode' => ['required', Rule::in(['inbound', 'outbound'])],
            'host' => ['nullable', 'string', 'max:190'],
            'agent_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'tls' => ['nullable', 'boolean'],
            'runtime' => ['required', Rule::in(array_keys((array) config('hosting.runtime.available')))],
            'runtime_options' => ['nullable', 'array'],
            'country' => ['nullable', 'string', 'max:2'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:64'],
            'continent' => ['nullable', 'string', 'max:32'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'flagship' => ['nullable', 'string', 'max:190'],
            'max_servers' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_memory_mb' => ['nullable', 'integer', 'min:1024'],
            'max_disk_mb' => ['nullable', 'integer', 'min:10240'],
            'max_cpu_percent' => ['nullable', 'integer', 'min:100'],
            'allocatable_percent' => ['required', 'integer', 'min:10', 'max:100'],
            'reserved_memory_mb' => ['required', 'integer', 'min:0', 'max:65536'],
            'weight' => ['required', 'integer', 'min:1', 'max:1000'],
            'region_priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'prefer_over_region' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'allow_ssh_fallback' => ['nullable', 'boolean'],
        ]);
    }

    private function regions(): array
    {
        return ['europe', 'asia', 'north-america', 'south-america', 'middle-east', 'africa', 'oceania'];
    }
}
