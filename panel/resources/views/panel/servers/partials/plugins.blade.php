@php
    /**
     * Плагины и моды (1-click установка).
     * Ожидает: $server, $templates, $installed (массив id), $canWrite, $gameVersion
     */
    $typeLabels = [
        'plugin'   => 'Плагин',
        'mod'      => 'Мод',
        'script'   => 'Скрипт',
        'config'   => 'Конфиг',
        'build'    => 'Сборка',
        'datapack' => 'Датапак',
    ];
@endphp

<div class="space-y-4">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                @include('partials.icon', ['name' => 'gamepad', 'class' => 'w-5 h-5'])
                {{ __('servers.plugins') }}
            </h2>
            <span class="text-xs text-ink-500">
                {{ __('games.installed_count', ['done' => count($installed)]) }}
                @if ($gameVersion) · {{ $server->game->name }} {{ $gameVersion }} @endif
            </span>
        </div>

        @if (! $server->game->supports_plugins)
            <div class="px-5 py-3 bg-amber-500/10 border-b border-amber-500/20 text-xs text-amber-200">
                Для этой игры не включена установка плагинов из панели.
                Файлы всё равно можно загрузить вручную через файловый менеджер.
            </div>
        @endif

        @if ($templates->isEmpty())
            <div class="px-5 py-12 text-center">
                <div class="mx-auto w-14 h-14 grid place-items-center rounded-xl bg-ink-800 text-ink-500 mb-4">
                    @include('partials.icon', ['name' => 'gamepad', 'class' => 'w-7 h-7'])
                </div>
                <p class="text-sm font-medium">{{ __('games.no_templates') }}</p>
                <p class="mt-1 text-xs text-ink-400">{{ __('games.no_templates_hint') }}</p>
            </div>
        @else
            <div class="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($templates as $template)
                    @php $isInstalled = in_array($template->id, (array) $installed, true); @endphp

                    <div @class([
                        'rounded-lg border p-4 flex flex-col',
                        'border-emerald-500/40 bg-emerald-500/5' => $isInstalled,
                        'border-ink-700 bg-ink-900' => ! $isInstalled,
                    ])>
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-sm truncate">{{ $template->name }}</div>
                                <div class="text-xs text-ink-500 mt-0.5 flex items-center gap-1.5 flex-wrap">
                                    <span class="badge-gray">{{ $typeLabels[$template->type] ?? $template->type }}</span>
                                    @if ($template->version)
                                        <span>v{{ $template->version }}</span>
                                    @endif
                                    @if ($template->is_official)
                                        <span class="badge-blue">{{ __('games.official') }}</span>
                                    @endif
                                </div>
                            </div>
                            @if ($isInstalled)
                                <span class="badge-green shrink-0">{{ __('games.installed') }}</span>
                            @endif
                        </div>

                        @if ($template->description)
                            <p class="mt-2 text-xs text-ink-400 line-clamp-3">{{ $template->description }}</p>
                        @endif

                        <div class="mt-2 text-[11px] text-ink-500 space-y-0.5">
                            <div>{{ __('games.target_path') }}: <code>{{ $template->targetPath() }}</code></div>
                            @if ($template->game_version)
                                <div>{{ __('servers.build') }}: <code>{{ $template->game_version }}</code></div>
                            @endif
                            @if ($template->size_bytes)
                                <div>{{ __('common.size') }}: {{ bytes_human($template->size_bytes) }}</div>
                            @endif
                        </div>

                        <div class="mt-3 pt-3 border-t border-ink-800 flex items-center gap-2">
                            <a href="{{ $template->resolveUrl() }}" target="_blank" rel="noopener"
                               class="btn btn-ghost btn-sm">
                                @include('partials.icon', ['name' => 'file', 'class' => 'w-3.5 h-3.5'])
                                {{ __('games.source') }}
                            </a>

                            @if ($canWrite)
                                @if ($isInstalled)
                                    <form method="POST" action="{{ route('panel.server.plugins.remove', [$server, $template]) }}"
                                          class="ml-auto"
                                          onsubmit="return confirm('{{ __('games.remove_confirm', ['name' => $template->name]) }}')">
                                        @csrf
                                        <button class="btn btn-secondary btn-sm">
                                            {{ __('games.remove') }}
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('panel.server.plugins.install', [$server, $template]) }}"
                                          class="ml-auto">
                                        @csrf
                                        <button class="btn btn-primary btn-sm">
                                            @include('partials.icon', ['name' => 'download', 'class' => 'w-3.5 h-3.5'])
                                            {{ __('games.install') }}
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
