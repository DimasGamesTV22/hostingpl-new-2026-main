{{-- Вкладка «Плагины и моды» --}}
@php $server = $server ?? null; @endphp

@include('panel.servers.partials.plugins', [
    'server' => $server,
    'templates' => $server->game->templates()->active()->ordered()->get(),
    'installed' => $server->templateInstalls()->where('status', 'installed')->pluck('game_template_id')->all(),
    'canWrite' => auth()->user()->can('writeFiles', $server),
    'gameVersion' => $server->build_version,
])
