<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameTemplate;
use App\Services\Games\GameManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminGameController extends Controller
{
    public function __construct(private readonly GameManager $games) {}

    public function index(Request $request): View
    {
        $games = Game::withCount(['servers', 'templates'])
            ->when($request->query('q'), fn ($q, $term) => $q->search($term))
            ->when($request->query('family'), fn ($q, $family) => $q->where('family', $family))
            ->orderByDesc('is_featured')
            ->orderBy('sort')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('admin.games.index', [
            'games' => $games,
            'families' => Game::distinct()->orderBy('family')->pluck('family')->all(),
            'filters' => $request->only(['q', 'family']),
        ]);
    }

    public function create(): View
    {
        return view('admin.games.edit', [
            'game' => new Game([
                'family' => 'custom',
                'runtime_overrides' => null,
                'min_slots' => 1,
                'max_slots' => 200,
                'default_slots' => 10,
                'slot_step' => 1,
                'price_per_slot_month' => 5,
                'default_memory_mb' => 1024,
                'min_memory_mb' => 512,
                'default_cpu_percent' => 50,
                'default_disk_mb' => 10240,
                'working_user' => 'gamedock',
                'is_active' => true,
                'is_public' => true,
                'is_custom' => true,
            ]),
            'startupJson' => $this->defaultStartupJson(),
            'installerJson' => json_encode(['type' => 'script', 'script' => '', 'timeout' => 1800], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $game = $this->games->create($this->payload($request));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors())->with('error', __('admin.games.errors.invalid_config'));
        }

        Auditor::log('admin.game_create', 'Добавлена игра «'.$game->name.'»', $game);

        return redirect()->route('admin.games.edit', $game)->with('success', __('admin.messages.game_created'));
    }

    public function edit(Game $game): View
    {
        return view('admin.games.edit', [
            'game' => $game,
            'startupJson' => json_encode($game->startup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'installerJson' => json_encode($game->installer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function update(Request $request, Game $game): RedirectResponse
    {
        try {
            $this->games->update($game, $this->payload($request));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors())->with('error', __('admin.games.errors.invalid_config'));
        }

        Auditor::log('admin.game_update', 'Обновлена игра «'.$game->name.'»', $game);

        return back()->with('success', __('admin.messages.game_updated'));
    }

    public function duplicate(Request $request, Game $game): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $copy = $this->games->duplicate($game, $data['name']);

        return redirect()->route('admin.games.edit', $copy)->with('success', __('admin.messages.game_duplicated'));
    }

    public function toggle(Game $game): RedirectResponse
    {
        $game->forceFill(['is_active' => ! $game->is_active])->save();

        return back()->with('success', __('admin.messages.game_toggled'));
    }

    public function destroy(Game $game): RedirectResponse
    {
        try {
            $this->games->delete($game);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.games')->with('success', __('admin.messages.game_deleted'));
    }

    // ── Шаблоны ─────────────────────────────────────────────────────────

    public function templates(Game $game): View
    {
        return view('admin.games.templates', [
            'game' => $game,
            'templates' => $game->templates()->orderBy('type')->orderBy('sort')->get(),
        ]);
    }

    public function storeTemplate(Request $request, Game $game): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash'],
            'type' => ['required', Rule::in(['plugin', 'mod', 'script', 'config', 'build', 'datapack'])],
            'version' => ['nullable', 'string', 'max:40'],
            'game_version' => ['nullable', 'string', 'max:60'],
            'source_type' => ['required', Rule::in(['url', 'builtin', 's3'])],
            'source_url' => ['nullable', 'url', 'max:1022'],
            'builtin_path' => ['nullable', 'string', 'max:512'],
            'target_path' => ['nullable', 'string', 'max:512'],
            'description' => ['nullable', 'string', 'max:1000'],
            'post_commands' => ['nullable', 'array'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $template = $this->games->createTemplate($game, array_merge($data, [
            'slug' => $data['slug'] ?: \Illuminate\Support\Str::slug($data['name']).'-'.substr(md5($data['name']), 0, 4),
        ]));

        return back()->with('success', __('admin.messages.template_created', ['name' => $template->name]));
    }

    public function updateTemplate(Request $request, GameTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(['plugin', 'mod', 'script', 'config', 'build', 'datapack'])],
            'version' => ['nullable', 'string', 'max:40'],
            'source_type' => ['sometimes', Rule::in(['url', 'builtin', 's3'])],
            'source_url' => ['nullable', 'url', 'max:1022'],
            'builtin_path' => ['nullable', 'string', 'max:512'],
            'target_path' => ['nullable', 'string', 'max:512'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $this->games->updateTemplate($template, $data);

        return back()->with('success', __('admin.messages.template_updated'));
    }

    public function destroyTemplate(GameTemplate $template): RedirectResponse
    {
        $this->games->deleteTemplate($template);

        return back()->with('success', __('admin.messages.template_deleted'));
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    private function payload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80', 'alpha_dash'],
            'family' => ['required', 'string', 'max:40'],
            'short_description' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'max:190'],
            'banner' => ['nullable', 'string', 'max:1022'],
            'image' => ['nullable', 'string', 'max:190'],
            'working_user' => ['nullable', 'string', 'max:64'],
            'uses_steamcmd' => ['nullable', 'boolean'],
            'steam_appid' => ['nullable', 'integer'],
            'min_slots' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'max_slots' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'default_slots' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'slot_step' => ['nullable', 'integer', 'min:1', 'max:100'],
            'price_per_slot_month' => ['nullable', 'numeric', 'min:0'],
            'default_memory_mb' => ['nullable', 'integer', 'min:256', 'max:65536'],
            'min_memory_mb' => ['nullable', 'integer', 'min:256', 'max:65536'],
            'default_cpu_percent' => ['nullable', 'integer', 'min:10', 'max:800'],
            'default_disk_mb' => ['nullable', 'integer', 'min:1024', 'max:1048576'],
            'supports_rcon' => ['nullable', 'boolean'],
            'supports_query' => ['nullable', 'boolean'],
            'supports_plugins' => ['nullable', 'boolean'],
            'supports_bedrock' => ['nullable', 'boolean'],
            'supports_auto_update' => ['nullable', 'boolean'],
            'supports_custom_builds' => ['nullable', 'boolean'],
            'supports_cron' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'startup_json' => ['nullable', 'string'],
            'installer_json' => ['nullable', 'string'],
            'config_files_json' => ['nullable', 'string'],
            'bootstrap_files_json' => ['nullable', 'string'],
        ]);

        foreach (['startup_json' => 'startup', 'installer_json' => 'installer', 'config_files_json' => 'config_files', 'bootstrap_files_json' => 'bootstrap_files'] as $field => $target) {
            if (! empty($data[$field])) {
                $decoded = json_decode($data[$field], true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Illuminate\Validation\ValidationException(
                        tap(\Illuminate\Support\Facades\Validator::make([], []), fn ($v) => $v),
                        [$field => __('admin.games.errors.invalid_json')],
                    );
                }

                $data[$target] = $decoded;
                unset($data[$field]);
            } else {
                unset($data[$field]);
            }
        }

        foreach (['uses_steamcmd', 'supports_rcon', 'supports_query', 'supports_plugins', 'supports_bedrock', 'supports_auto_update', 'supports_custom_builds', 'supports_cron', 'is_active', 'is_public', 'is_featured'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        return $data;
    }

    private function defaultStartupJson(): string
    {
        return json_encode([
            'exec' => './server',
            'args' => ['{game_port}'],
            'cwd' => '.',
            'env' => [],
            'user' => 'gamedock',
            'stop_signal' => 'SIGTERM',
            'stop_timeout' => 30,
            'rcon' => ['type' => 'source', 'port_from' => 'rcon_port'],
            'query' => ['type' => 'source', 'port_from' => 'query_port'],
            'healthcheck' => ['type' => 'query', 'interval' => 30],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
