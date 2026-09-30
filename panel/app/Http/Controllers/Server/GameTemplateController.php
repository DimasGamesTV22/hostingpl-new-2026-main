<?php

declare(strict_types=1);

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\GameTemplate;
use App\Models\Server;
use App\Services\Games\GameManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GameTemplateController extends Controller
{
    public function __construct(private readonly GameManager $games) {}

    public function index(Request $request, Server $server): View
    {
        $this->authorize('files', $server);

        $server->load('game');

        $installed = $server->templateInstalls()
            ->where('status', 'installed')
            ->pluck('game_template_id')
            ->all();

        return view('panel.servers.plugins', [
            'server' => $server,
            'templates' => $server->game->templates()->active()->ordered()->get(),
            'installed' => $installed,
            'canWrite' => $request->user()->can('writeFiles', $server),
            'gameVersion' => $server->build_version ?? $server->game->default_branch,
        ]);
    }

    public function install(Request $request, Server $server, GameTemplate $template): RedirectResponse
    {
        $this->authorize('writeFiles', $server);

        abort_unless($template->game_id === $server->game_id, 404);

        $result = $this->games->installOnServer($template, $server, $request->user());

        return $result['ok']
            ? back()->with('success', __('games.messages.template_installing', ['name' => $template->name]))
            : back()->with('error', $result['error'] ?? __('games.errors.install_failed'));
    }

    public function remove(Request $request, Server $server, GameTemplate $template): RedirectResponse
    {
        $this->authorize('writeFiles', $server);

        abort_unless($template->game_id === $server->game_id, 404);

        $result = $this->games->removeFromServer($template, $server, $request->user());

        return $result['ok']
            ? back()->with('success', __('games.messages.template_removed', ['name' => $template->name]))
            : back()->with('error', $result['error'] ?? __('games.errors.remove_failed'));
    }
}
