@extends('layouts.dashboard')

@section('title', __('games.install_templates') . ' — ' . $game->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.games') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('admin.games') }}
        </a>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <img src="{{ game_image_url($game->icon, $game->family) }}" alt=""
                     class="h-10 w-10 rounded" onerror="this.style.display='none'">
                <div>
                    <h1 class="text-2xl font-bold">{{ $game->name }}</h1>
                    <p class="text-sm text-ink-400">{{ __('games.install_templates') }} · {{ $templates->count() }}</p>
                </div>
            </div>

            <a href="{{ route('admin.games.edit', $game) }}" class="btn btn-ghost btn-sm">{{ __('common.edit') }}</a>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Список -->
        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">{{ __('games.install_templates') }}</h2>
            </div>

            @if ($templates->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-ink-400">
                    {{ __('games.no_templates_hint') }}
                </div>
            @else
                <div class="divide-y divide-ink-800">
                    @foreach ($templates as $template)
                        <div @class(['px-5 py-4', 'opacity-60' => ! $template->is_active])>
                            <div class="flex flex-wrap items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-medium">{{ $template->name }}</span>
                                        <span class="badge-gray">{{ __('games.template_types.' . $template->type, $template->type) }}</span>
                                        @if ($template->is_official)
                                            <span class="badge-blue">{{ __('games.official') }}</span>
                                        @endif
                                        @if (! $template->is_active)
                                            <span class="badge-red">{{ __('common.disabled') }}</span>
                                        @endif
                                    </div>

                                    <div class="text-xs text-ink-500 mt-1 space-y-0.5">
                                        <div>slug: <code>{{ $template->slug }}</code>
                                            @if ($template->version) · v{{ $template->version }} @endif
                                            @if ($template->game_version) · {{ $template->game_version }} @endif
                                        </div>
                                        <div>{{ __('games.source_type') }}:
                                            {{ __('games.source_types.' . $template->source_type, $template->source_type) }}
                                            @if ($template->source_url)
                                                · <a href="{{ $template->source_url }}" target="_blank" rel="noopener"
                                                      class="link break-all">{{ Str::limit($template->source_url, 60) }}</a>
                                            @endif
                                            @if ($template->builtin_path)
                                                · <code>{{ $template->builtin_path }}</code>
                                            @endif
                                        </div>
                                        <div>{{ __('games.target_path') }}: <code>{{ $template->targetPath() }}</code></div>
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('admin.games.templates.update', $template) }}"
                                      class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    @method('PUT')

                                    <input type="text" name="name" value="{{ $template->name }}" required
                                           class="input !py-1 !text-xs w-40" maxlength="120">
                                    <input type="text" name="version" value="{{ $template->version }}"
                                           class="input !py-1 !text-xs w-20" maxlength="40" placeholder="вер.">
                                    <input type="number" name="sort" value="{{ $template->sort }}"
                                           class="input !py-1 !text-xs w-16" min="0" max="999">
                                    <label class="flex items-center gap-1 text-xs text-ink-400">
                                        <input type="checkbox" name="is_active" value="1" class="checkbox"
                                               @checked($template->is_active)>
                                        on
                                    </label>
                                    <button class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Добавление -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('games.add_template') }}</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.games.templates.store', $game) }}" class="space-y-3">
                    @csrf

                    <div>
                        <label class="label">{{ __('common.name') }}</label>
                        <input type="text" name="name" required class="input" maxlength="120" value="{{ old('name') }}">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">{{ __('games.template_type') }}</label>
                            <select name="type" class="select">
                                @foreach (['plugin', 'mod', 'script', 'config', 'build', 'datapack'] as $type)
                                    <option value="{{ $type }}" @selected(old('type') === $type)>
                                        {{ __('games.template_types.' . $type) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="label">{{ __('games.source_type') }}</label>
                            <select name="source_type" class="select" x-data="{ s: @js(old('source_type', 'url')) }" x-model="s">
                                @foreach (['url', 'builtin', 's3'] as $source)
                                    <option value="{{ $source }}">{{ __('games.source_types.' . $source) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div x-show="s === 'url'">
                        <label class="label">{{ __('games.source_url') }}</label>
                        <input type="url" name="source_url" class="input" maxlength="1022"
                               placeholder="https://github.com/.../releases/download/.../plugin.jar"
                               value="{{ old('source_url') }}">
                        @error('source_url') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div x-show="s === 'builtin'" x-cloak>
                        <label class="label">{{ __('games.builtin_path') }}</label>
                        <input type="text" name="builtin_path" class="input" maxlength="512"
                               placeholder="minecraft/plugins/essentials.jar" value="{{ old('builtin_path') }}">
                    </div>

                    <div>
                        <label class="label">{{ __('games.target_path') }}</label>
                        <input type="text" name="target_path" class="input font-mono" maxlength="512"
                               placeholder="plugins/" value="{{ old('target_path', 'plugins/') }}">
                        @error('target_path') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Версия</label>
                            <input type="text" name="version" class="input" maxlength="40" value="{{ old('version') }}">
                        </div>
                        <div>
                            <label class="label">Версия игры</label>
                            <input type="text" name="game_version" class="input" maxlength="60" value="{{ old('game_version') }}">
                        </div>
                    </div>

                    <div>
                        <label class="label">Описание</label>
                        <textarea name="description" rows="3" class="input" maxlength="1000">{{ old('description') }}</textarea>
                    </div>

                    <div>
                        <label class="label">Команды после установки (по одной на строку)</label>
                        <textarea name="post_commands[]" rows="2" class="input font-mono !text-xs"
                                  placeholder="say Плагин установлен"></textarea>
                    </div>

                    <button class="btn btn-primary btn-sm w-full">{{ __('common.add') }}</button>
                </form>
            </div>
        </div>
    </div>
@endsection
