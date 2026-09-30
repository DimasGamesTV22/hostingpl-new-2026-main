@extends('layouts.dashboard')

@section('title', ($game->exists ? __('common.edit') : __('common.create')) . ' — ' . __('admin.games'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.games') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('admin.games') }}
        </a>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">{{ $game->exists ? $game->name : __('admin.games.custom_game') }}</h1>

            @if ($game->exists)
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.games.templates', $game) }}" class="btn btn-ghost btn-sm">
                        {{ __('games.install_templates') }}
                    </a>
                    <form method="POST" action="{{ route('admin.games.duplicate', $game) }}"
                          x-data="{ open: false }" class="flex gap-1">
                        @csrf
                        <input type="text" name="name" x-show="open" x-cloak required
                               class="input !py-1 !text-xs w-40" placeholder="Название копии">
                        <button class="btn btn-ghost btn-sm" @click="open = ! open" x-text="open ? 'Создать' : 'Копировать'"></button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <form method="POST"
          action="{{ $game->exists ? route('admin.games.update', $game) : route('admin.games.store') }}"
          class="grid gap-4 lg:grid-cols-3"
          x-data="{ json: 'startup', errors: {} }">
        @csrf
        @if ($game->exists)
            @method('PUT')
        @endif

        <div class="lg:col-span-2 space-y-4">
            <!-- Основное -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.info') }}</h2></div>
                <div class="card-body grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">{{ __('common.name') }}</label>
                        <input type="text" name="name" value="{{ old('name', $game->name) }}" required
                               class="input" maxlength="120">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">Slug</label>
                        <input type="text" name="slug" value="{{ old('slug', $game->slug) }}" class="input" maxlength="80">
                        @error('slug') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('games.family') }}</label>
                        <input type="text" name="family" value="{{ old('family', $game->family) }}" required
                               class="input" maxlength="40" list="families">
                        <datalist id="families">
                            @foreach (['minecraft', 'valve', 'gta', 'samp', 'mta', 'rust', 'unturned', 'ark', 'custom'] as $family)
                                <option value="{{ $family }}"></option>
                            @endforeach
                        </datalist>
                    </div>

                    <div>
                        <label class="label">Рабочий пользователь на ноде</label>
                        <input type="text" name="working_user" value="{{ old('working_user', $game->working_user) }}"
                               class="input" maxlength="64">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="label">Краткое описание</label>
                        <input type="text" name="short_description"
                               value="{{ old('short_description', $game->short_description) }}"
                               class="input" maxlength="300">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="label">Описание</label>
                        <textarea name="description" rows="4" class="input" maxlength="5000">{{ old('description', $game->description) }}</textarea>
                    </div>

                    <div>
                        <label class="label">Иконка (имя файла или URL)</label>
                        <input type="text" name="icon" value="{{ old('icon', $game->icon) }}" class="input" maxlength="190"
                               placeholder="minecraft-java">
                    </div>

                    <div>
                        <label class="label">Образ Docker</label>
                        <input type="text" name="image" value="{{ old('image', $game->image) }}" class="input" maxlength="190"
                               placeholder="ghcr.io/gamedock/minecraft-java:1.21">
                    </div>
                </div>
            </div>

            <!-- Лимиты -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('servers.resources') }}</h2></div>
                <div class="card-body grid sm:grid-cols-3 gap-4">
                    <div>
                        <label class="label">Мин. слоты</label>
                        <input type="number" name="min_slots" value="{{ old('min_slots', $game->min_slots) }}"
                               class="input" min="1" max="10000">
                    </div>
                    <div>
                        <label class="label">Макс. слоты</label>
                        <input type="number" name="max_slots" value="{{ old('max_slots', $game->max_slots) }}"
                               class="input" min="1" max="10000">
                    </div>
                    <div>
                        <label class="label">По умолчанию</label>
                        <input type="number" name="default_slots" value="{{ old('default_slots', $game->default_slots) }}"
                               class="input" min="1" max="10000">
                    </div>

                    <div>
                        <label class="label">Шаг слотов</label>
                        <input type="number" name="slot_step" value="{{ old('slot_step', $game->slot_step) }}"
                               class="input" min="1" max="100">
                    </div>
                    <div>
                        <label class="label">Цена за слот, ₽/мес</label>
                        <input type="number" name="price_per_slot_month"
                               value="{{ old('price_per_slot_month', $game->price_per_slot_month) }}"
                               class="input" min="0" step="0.5">
                    </div>

                    <div>
                        <label class="label">Мин. RAM, МБ</label>
                        <input type="number" name="min_memory_mb" value="{{ old('min_memory_mb', $game->min_memory_mb) }}"
                               class="input" min="256" max="65536" step="128">
                    </div>
                    <div>
                        <label class="label">RAM по умолчанию, МБ</label>
                        <input type="number" name="default_memory_mb"
                               value="{{ old('default_memory_mb', $game->default_memory_mb) }}"
                               class="input" min="256" max="65536" step="128">
                    </div>
                    <div>
                        <label class="label">CPU по умолчанию, %</label>
                        <input type="number" name="default_cpu_percent"
                               value="{{ old('default_cpu_percent', $game->default_cpu_percent) }}"
                               class="input" min="10" max="800" step="5">
                    </div>
                    <div>
                        <label class="label">Диск по умолчанию, МБ</label>
                        <input type="number" name="default_disk_mb"
                               value="{{ old('default_disk_mb', $game->default_disk_mb) }}"
                               class="input" min="1024" max="1048576" step="1024">
                    </div>
                </div>
            </div>

            <!-- JSON -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Конфигурация запуска и установки</h2>
                </div>
                <div class="card-body">
                    <div class="flex gap-1 mb-3">
                        @foreach ([
                            'startup' => 'Startup',
                            'installer' => 'Installer',
                            'config' => 'Config files',
                            'bootstrap' => 'Bootstrap files',
                        ] as $key => $label)
                            <button type="button" class="px-3 py-1.5 rounded-md text-xs"
                                    :class="json === '{{ $key }}' ? 'bg-ink-800 text-ink-100' : 'text-ink-400 hover:text-ink-200'"
                                    @click="json = '{{ $key }}'">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    @error('startup_json') <p class="error mb-2">{{ $message }}</p> @enderror
                    @error('installer_json') <p class="error mb-2">{{ $message }}</p> @enderror
                    @error('config_files_json') <p class="error mb-2">{{ $message }}</p> @enderror
                    @error('bootstrap_files_json') <p class="error mb-2">{{ $message }}</p> @enderror

                    <textarea name="startup_json" x-show="json === 'startup'" x-cloak
                              class="w-full h-80 rounded-lg bg-ink-900 border border-ink-700 text-ink-100
                                     text-[12px] font-mono p-3 focus:border-brand-500 focus:outline-none"
                              spellcheck="false">{{ old('startup_json', $startupJson) }}</textarea>

                    <textarea name="installer_json" x-show="json === 'installer'" x-cloak
                              class="w-full h-80 rounded-lg bg-ink-900 border border-ink-700 text-ink-100
                                     text-[12px] font-mono p-3 focus:border-brand-500 focus:outline-none"
                              spellcheck="false">{{ old('installer_json', $installerJson) }}</textarea>

                    <textarea name="config_files_json" x-show="json === 'config'" x-cloak
                              class="w-full h-80 rounded-lg bg-ink-900 border border-ink-700 text-ink-100
                                     text-[12px] font-mono p-3 focus:border-brand-500 focus:outline-none"
                              spellcheck="false">{{ old('config_files_json', json_encode($game->config_files ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>

                    <textarea name="bootstrap_files_json" x-show="json === 'bootstrap'" x-cloak
                              class="w-full h-80 rounded-lg bg-ink-900 border border-ink-700 text-ink-100
                                     text-[12px] font-mono p-3 focus:border-brand-500 focus:outline-none"
                              spellcheck="false">{{ old('bootstrap_files_json', json_encode($game->bootstrap_files ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>

                    <p class="hint">
                        Подстановки в startup: <code>:game_port</code>, <code>:query_port</code>,
                        <code>:rcon_port</code>, <code>:server_dir</code>, <code>:memory</code>,
                        <code>:slots</code>, <code>:java_version</code>.
                    </p>
                </div>
            </div>
        </div>

        <!-- Правая колонка -->
        <div class="space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Возможности</h2></div>
                <div class="card-body space-y-2">
                    @foreach ([
                        'uses_steamcmd' => 'Использует SteamCMD',
                        'supports_rcon' => 'RCON',
                        'supports_query' => 'Query-протокол',
                        'supports_plugins' => 'Плагины и моды',
                        'supports_bedrock' => 'Bedrock',
                        'supports_auto_update' => 'Автообновление',
                        'supports_custom_builds' => 'Выбор сборки',
                        'supports_cron' => 'Планировщик',
                    ] as $field => $label)
                        <label class="flex items-center gap-2 text-sm text-ink-300">
                            <input type="checkbox" name="{{ $field }}" value="1" class="checkbox"
                                   @checked(old($field, $game->{$field}))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">SteamCMD</h2></div>
                <div class="card-body">
                    <label class="label">App ID</label>
                    <input type="number" name="steam_appid" value="{{ old('steam_appid', $game->steam_appid) }}"
                           class="input" min="1">
                    <p class="hint">Для CS2 — 730, Rust — 252490, ARK — 346110.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.status') }}</h2></div>
                <div class="card-body space-y-2">
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="is_active" value="1" class="checkbox" @checked(old('is_active', $game->is_active))>
                        {{ __('common.active') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="is_public" value="1" class="checkbox" @checked(old('is_public', $game->is_public))>
                        Показывать в публичном каталоге
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="is_featured" value="1" class="checkbox" @checked(old('is_featured', $game->is_featured))>
                        Показывать на главной
                    </label>

                    <div>
                        <label class="label">{{ __('common.sort') }}</label>
                        <input type="number" name="sort" value="{{ old('sort', $game->sort) }}"
                               class="input" min="0" max="999">
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button class="btn btn-primary flex-1">{{ __('common.save') }}</button>
                <a href="{{ route('admin.games') }}" class="btn btn-ghost">{{ __('common.cancel') }}</a>
            </div>

            @if ($game->exists && $game->servers()->exists())
                <p class="text-xs text-ink-500">
                    На этой игре {{ $game->servers()->count() }} серверов — удалить её нельзя.
                </p>
            @elseif ($game->exists)
                <form method="POST" action="{{ route('admin.games.destroy', $game) }}"
                      onsubmit="return confirm('{{ __('common.confirm') }}')">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-danger btn-sm w-full">{{ __('common.delete') }}</button>
                </form>
            @endif
        </div>
    </form>
@endsection
