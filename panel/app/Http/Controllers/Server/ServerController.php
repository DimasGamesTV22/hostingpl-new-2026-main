<?php

declare(strict_types=1);

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Server;
use App\Models\Tariff;
use App\Services\Billing\BillingService;
use App\Services\Games\ConfigFileService;
use App\Services\Games\GameManager;
use App\Services\Games\StartupBuilder;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Nodes\PortAllocator;
use App\Services\Servers\ProvisioningException;
use App\Services\Servers\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function __construct(
        private readonly Provisioner $provisioner,
        private readonly StartupBuilder $startup,
        private readonly ConfigFileService $configs,
        private readonly MetricsCollector $metrics,
        private readonly BillingService $billing,
        private readonly GameManager $games,
        private readonly PortAllocator $ports,
    ) {}

    // ── Список ──────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Server::class);

        $servers = Server::query()
            ->where('user_id', $request->user()->id)
            ->with(['game', 'node', 'tariff'])
            ->orderByRaw("FIELD(status, 'running', 'starting', 'installing', 'pending', 'crashed', 'error', 'stopping', 'stopped', 'suspended', 'installed')")
            ->orderByDesc('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('panel.servers.index', [
            'servers' => $servers,
            'quota' => [
                'used' => Server::where('user_id', $request->user()->id)->count(),
                'total' => setting_int('hosting.account.max_servers_per_user', 20),
            ],
            'trialEndsAt' => $request->user()->trial_ends_at,
        ]);
    }

    public function all(Request $request): View
    {
        $servers = Server::query()
            ->where('user_id', $request->user()->id)
            ->with(['game', 'node'])
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('panel.servers.all', compact('servers'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', Server::class);

        $games = Game::public()->ordered()->get();
        $tariffs = Tariff::public()->ordered()->with('prices')->get();

        if ($games->isEmpty()) {
            return redirect()->route('panel.dashboard')->with('error', __('servers.errors.no_games'));
        }

        return view('panel.servers.create', [
            'games' => $games,
            'tariffs' => $tariffs,
            'nodeMode' => node_mode(),
            'regions' => $this->regions(),
            'ports' => $this->ports->purchasableRanges(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Server::class);

        $game = Game::findOrFail($request->integer('game_id'));

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'tariff_id' => ['nullable', 'integer', 'exists:tariffs,id'],
            'memory_mb' => ['nullable', 'integer', 'min:512', 'max:65536'],
            'disk_mb' => ['nullable', 'integer', 'min:2048', 'max:1048576'],
            'slots' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'cpu_percent' => ['nullable', 'integer', 'min:10', 'max:800'],
            'network_mbps' => ['nullable', 'integer', 'min:5', 'max:1000'],
            'pids' => ['nullable', 'integer', 'min:64', 'max:8192'],
            'build' => ['nullable', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:64'],
            'node_id' => ['nullable', 'integer', 'exists:nodes,id'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'watchdog' => ['nullable', 'boolean'],
            'sub_accounts' => ['nullable', 'boolean'],
        ]);

        // Приводим к допустимым пределам игры
        $validated['memory_mb'] = max($game->min_memory_mb, (int) ($validated['memory_mb'] ?? $game->default_memory_mb));
        $validated['disk_mb'] = (int) ($validated['disk_mb'] ?? $game->default_disk_mb);
        $validated['slots'] = max($game->min_slots, min($game->max_slots, (int) ($validated['slots'] ?? $game->default_slots)));

        if ($tariff = Tariff::find($validated['tariff_id'] ?? null)) {
            $quota = $request->user()->servers()->where('tariff_id', $tariff->id)->count();
            if ($quota >= $tariff->max_servers) {
                return back()->withInput()->with('error', __('servers.errors.tariff_limit', [
                    'tariff' => $tariff->name,
                    'limit' => $tariff->max_servers,
                ]));
            }

            // Не даём выйти за лимиты тарифа
            $validated['memory_mb'] = min($validated['memory_mb'], $tariff->memory_mb);
            $validated['slots'] = min($validated['slots'], max($tariff->slots, $game->min_slots));
        }

        if (! empty($validated['port']) && ! setting_bool('hosting.ports.allow_purchase', true)) {
            $validated['port'] = null;
        }

        try {
            $server = $this->provisioner->create($request->user(), $game, $validated);
        } catch (ProvisioningException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Начисление первой оплаты
        $this->billing->scheduleFirstCharge($server);

        return redirect()->route('panel.servers.show', $server)
            ->with('success', __('servers.messages.created'));
    }

    public function show(Request $request, Server $server): View
    {
        $this->authorize('view', $server);

        $server->load(['game', 'node', 'tariff']);

        return view('panel.servers.show', [
            'server' => $server,
            'series' => $this->metrics->series($server, $request->query('range', '1h')),
            'range' => $request->query('range', '1h'),
            'events' => $server->events()->latest()->limit(15)->get(),
            'installLogs' => $server->isInstalling() ? $server->installLogs()->orderBy('step')->get() : collect(),
            'command' => $this->startup->displayCommand($server),
            'permissions' => [
                'power' => $request->user()->can('power', $server),
                'console' => $request->user()->can('console', $server),
                'files' => $request->user()->can('files', $server),
                'settings' => $request->user()->can('update', $server),
            ],
        ]);
    }

    public function events(Request $request, Server $server): View
    {
        $this->authorize('view', $server);

        return view('panel.servers.events', [
            'server' => $server,
            'events' => $server->events()->latest()->paginate(50),
        ]);
    }

    // ── Питание ─────────────────────────────────────────────────────────

    public function start(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('power', $server);

        if (! $server->canStart()) {
            return back()->with('error', __('servers.errors.cannot_start', ['status' => $server->statusLabel()]));
        }

        if ($server->isExpired()) {
            return back()->with('error', __('servers.errors.expired'));
        }

        $this->provisioner->start($server, $request->user());

        return back()->with('success', __('servers.messages.starting'));
    }

    public function stop(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('power', $server);

        $this->provisioner->stop($server, true, $request->user());

        return back()->with('success', __('servers.messages.stopping'));
    }

    public function restart(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('power', $server);

        $this->provisioner->restart($server, $request->user());

        return back()->with('success', __('servers.messages.restarting'));
    }

    public function kill(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('power', $server);

        $this->provisioner->kill($server, $request->user());

        return back()->with('warning', __('servers.messages.killing'));
    }

    // ── Настройки ───────────────────────────────────────────────────────

    public function settings(Request $request, Server $server): View
    {
        $this->authorize('update', $server);

        $server->load('game');

        return view('panel.servers.settings', [
            'server' => $server,
            'configFiles' => $server->game->configFiles(),
            'builds' => $server->game->buildList(),
            'ports' => $this->ports->purchasableRanges(),
            'canReinstall' => $request->user()->can('reinstall', $server),
        ]);
    }

    public function updateGeneral(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'watchdog_enabled' => ['nullable', 'boolean'],
            'sub_accounts_enabled' => ['nullable', 'boolean'],
            'build_version' => ['nullable', 'string', 'max:64'],
        ]);

        $server->forceFill(array_filter([
            'name' => $data['name'],
            'watchdog_enabled' => $request->boolean('watchdog_enabled'),
            'sub_accounts_enabled' => $request->boolean('sub_accounts_enabled'),
            'build_version' => $data['build_version'] ?? null,
        ], static fn ($v) => $v !== null))->save();

        $this->provisioner->log($server, 'setting', __('servers.events.general_updated'), 'info', [], $request->user());

        return back()->with('success', __('servers.messages.saved'));
    }

    public function updateResources(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $game = $server->game;
        $tariff = $server->tariff;

        $maxMemory = $tariff ? $tariff->memory_mb : 65536;
        $maxDisk = $tariff ? $tariff->disk_mb : 1048576;
        $maxSlots = $tariff ? max($tariff->slots, $game->min_slots) : $game->max_slots;

        $data = $request->validate([
            'memory_mb' => ['required', 'integer', 'min:'.max(512, $game->min_memory_mb), 'max:'.$maxMemory],
            'cpu_percent' => ['required', 'integer', 'min:10', 'max:800'],
            'disk_mb' => ['required', 'integer', 'min:2048', 'max:'.$maxDisk],
            'network_mbps' => ['required', 'integer', 'min:5', 'max:1000'],
            'pids' => ['required', 'integer', 'min:64', 'max:8192'],
            'slots' => ['required', 'integer', 'min:'.$game->min_slots, 'max:'.$maxSlots],
        ]);

        $data['slots'] = min($data['slots'], $maxSlots);

        $this->provisioner->updateResources($server, $data, $request->user());

        return back()->with('success', __('servers.messages.resources_updated'));
    }

    public function updateConfig(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:512'],
            'values' => ['required', 'array'],
        ]);

        $definition = $server->game->configFile($data['path']);

        if (! $definition) {
            return back()->with('error', __('servers.errors.unknown_config'));
        }

        // Валидируем каждое поле по схеме
        $clean = [];
        $errors = [];

        foreach ((array) $definition['fields'] as $field) {
            $key = $field['key'] ?? null;

            if ($key === null || ! empty($field['read_only'])) {
                continue;
            }

            $value = data_get($data['values'], $key, null);

            if ($value === null) {
                continue;
            }

            $problem = $this->configs->validateValue($key, $value, $field);
            if ($problem) {
                $errors[$key] = $problem;

                continue;
            }

            $clean[$key] = $value;
        }

        if ($errors) {
            return back()->withInput()->with('error', implode(' ', $errors));
        }

        $written = app(\App\Services\Agent\FileService::class)
            ->updateConfig($server, $data['path'], $clean, $definition);

        if (! $written['ok']) {
            return back()->with('error', $written['error'] ?? __('servers.errors.config_write_failed'));
        }

        $server->forceFill([
            'config_values' => array_merge((array) $server->config_values, $clean),
        ])->save();

        $this->provisioner->log(
            $server,
            'setting',
            __('servers.events.config_updated', ['file' => $definition['label'] ?? $data['path']]),
            'info',
            ['keys' => array_keys($clean)],
            $request->user(),
        );

        return back()->with('success', __('servers.messages.config_saved'));
    }

    public function reinstall(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('reinstall', $server);

        $server->load('game');

        $data = $request->validate([
            'build' => ['nullable', 'string', 'max:64'],
            'wipe' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('wipe')) {
            $this->provisioner->log(
                $server,
                'install',
                __('servers.events.wipe_warning'),
                'warning',
                ['files' => $server->install_manifest],
                $request->user(),
            );
        }

        $server->forceFill(['build_version' => $data['build'] ?? $server->build_version])->save();

        $this->provisioner->reinstall($server);

        return back()->with('success', __('servers.messages.reinstalling'));
    }

    public function updateGame(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $data = $request->validate([
            'build' => ['nullable', 'string', 'max:64'],
        ]);

        $this->provisioner->updateBuild($server, $data['build'] ?? null);

        return back()->with('success', __('servers.messages.updating'));
    }

    public function builds(Server $server): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $server);

        return response()->json([
            'builds' => $server->game->buildList(),
            'current' => $server->build_version,
        ]);
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('delete', $server);

        $data = $request->validate([
            'name' => ['required', 'string'],
            'purge' => ['nullable', 'boolean'],
        ]);

        // Защита от удаления чужого сервера по невнимательности
        if (! hash_equals($server->name, $data['name'])) {
            return back()->with('error', __('servers.errors.name_mismatch'));
        }

        $this->provisioner->delete($server, $request->boolean('purge', true), $request->user());

        return redirect()->route('panel.servers.index')->with('success', __('servers.messages.deleted'));
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    private function regions(): array
    {
        return \App\Models\Node::active()
            ->whereNotNull('region')
            ->distinct()
            ->orderBy('region')
            ->pluck('region')
            ->all();
    }
}
