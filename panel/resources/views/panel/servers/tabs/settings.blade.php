{{-- Вкладка «Настройки»: общие, ресурсы, конфиги игры, сборка --}}
@php $server = $server ?? null; @endphp

<div class="grid lg:grid-cols-2 gap-4">
    <!-- Общие -->
    @if ($permissions['settings'] ?? auth()->user()->can('update', $server))
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('common.settings') }}</h2></div>
            <div class="card-body">
                <form method="POST" action="{{ route('panel.server.settings.general', $server) }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="label">{{ __('common.name') }}</label>
                        <input type="text" name="name" value="{{ $server->name }}" required class="input" maxlength="80">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    @if (! empty($builds))
                        <div>
                            <label class="label">{{ __('servers.build') }}</label>
                            <select name="build_version" class="select">
                                @foreach ($builds as $build)
                                    <option value="{{ $build['id'] ?? '' }}" @selected($server->build_version === ($build['id'] ?? ''))>
                                        {{ $build['name'] ?? ($build['id'] ?? '') }}
                                        @if (! empty($build['version'])) ({{ $build['version'] }}) @endif
                                    </option>
                                @endforeach
                            </select>
                            <p class="hint">Смена сборки требует переустановки сервера.</p>
                        </div>
                    @endif

                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="watchdog_enabled" value="1" class="checkbox"
                               @checked($server->watchdog_enabled)>
                        {{ __('servers.watchdog') }}
                    </label>

                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="sub_accounts_enabled" value="1" class="checkbox"
                               @checked($server->sub_accounts_enabled)>
                        {{ __('servers.sub_accounts_enabled') }}
                    </label>

                    <button class="btn btn-primary btn-sm">{{ __('common.save') }}</button>
                </form>
            </div>
        </div>
    @endif

    <!-- Ресурсы -->
    <div class="card">
        <div class="card-header"><h2 class="card-title">{{ __('servers.resources') }}</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('panel.server.settings.resources', $server) }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">{{ __('common.memory') }} (МБ)</label>
                        <input type="number" name="memory_mb" value="{{ $server->memory_mb }}" required
                               min="{{ $server->game->min_memory_mb }}" max="{{ $server->tariff?->memory_mb ?: 65536 }}"
                               class="input">
                    </div>

                    <div>
                        <label class="label">{{ __('common.disk') }} (МБ)</label>
                        <input type="number" name="disk_mb" value="{{ $server->disk_mb }}" required
                               min="2048" max="{{ $server->tariff?->disk_mb ?: 1048576 }}" class="input">
                    </div>

                    <div>
                        <label class="label">{{ __('common.cpu') }} (%)</label>
                        <input type="number" name="cpu_percent" value="{{ $server->cpu_percent }}" required
                               min="10" max="800" class="input">
                    </div>

                    <div>
                        <label class="label">{{ __('common.network') }} (Мбит/с)</label>
                        <input type="number" name="network_mbps" value="{{ $server->network_mbps }}" required
                               min="5" max="1000" class="input">
                    </div>

                    <div>
                        <label class="label">{{ __('common.slots') }}</label>
                        <input type="number" name="slots" value="{{ $server->slots }}" required
                               min="{{ $server->game->min_slots }}"
                               max="{{ $server->tariff?->slots ?: $server->game->max_slots }}" class="input">
                    </div>

                    <div>
                        <label class="label">Процессов (pids)</label>
                        <input type="number" name="pids" value="{{ $server->pids }}" required
                               min="64" max="8192" class="input">
                    </div>
                </div>

                @if ($server->tariff)
                    <p class="hint">
                        Тариф «{{ $server->tariff->name }}» ограничивает:
                        {{ mb_gb($server->tariff->memory_mb) }} RAM,
                        {{ mb_gb($server->tariff->disk_mb) }} диска,
                        {{ $server->tariff->slots }} слотов.
                        Больше выставить нельзя.
                    </p>
                @endif

                <button class="btn btn-primary btn-sm">{{ __('common.save') }}</button>
            </form>
        </div>
    </div>

    <!-- Конфиги игры -->
    @foreach ($configFiles as $configFile)
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">{{ $configFile['label'] ?? $configFile['path'] }}</h2>
                <code class="text-xs text-ink-500">{{ $configFile['path'] }}</code>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('panel.server.settings.config', $server) }}"
                      class="grid sm:grid-cols-2 gap-4">
                    @csrf
                    <input type="hidden" name="path" value="{{ $configFile['path'] }}">

                    @foreach ($configFile['fields'] as $field)
                        @php
                            $key = $field['key'];
                            $value = data_get($server->config_values, $key, $field['default'] ?? null);
                            $inputName = 'values[' . $key . ']';
                        @endphp

                        <div @class(['sm:col-span-2' => in_array($field['type'] ?? 'text', ['text'], true) && ($field['maxlength'] ?? 0) > 60])>
                            <label class="label">
                                {{ $field['label'] ?? $key }}
                                @if ($field['read_only'] ?? false)
                                    <span class="text-ink-500 font-normal">({{ __('common.read_only') ?? 'только чтение' }})</span>
                                @endif
                            </label>

                            @if ($field['read_only'] ?? false)
                                <input type="text" value="{{ $value }}" disabled class="input">
                            @elseif (($field['type'] ?? 'text') === 'bool')
                                <label class="flex items-center gap-2 h-9">
                                    <input type="hidden" name="{{ $inputName }}" value="0">
                                    <input type="checkbox" name="{{ $inputName }}" value="1" class="checkbox"
                                           @checked((bool) $value)>
                                    <span class="text-sm text-ink-400">{{ $value ? __('common.yes') : __('common.no') }}</span>
                                </label>
                            @elseif (($field['type'] ?? 'text') === 'select')
                                <select name="{{ $inputName }}" class="select">
                                    @foreach (($field['options'] ?? []) as $option)
                                        <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="{{ ($field['type'] ?? 'text') === 'password' ? 'password' : (($field['type'] ?? 'text') === 'number' || ($field['type'] ?? 'text') === 'port' ? 'number' : 'text') }}"
                                       name="{{ $inputName }}" value="{{ $value }}"
                                       @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
                                       @if (isset($field['max'])) max="{{ $field['max'] }}" @endif
                                       @if (isset($field['maxlength'])) maxlength="{{ $field['maxlength'] }}" @endif
                                       class="input">
                            @endif

                            @error($inputName) <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach

                    <div class="sm:col-span-2">
                        <button class="btn btn-primary btn-sm">{{ __('common.apply') }}</button>
                        <span class="text-xs text-ink-500 ml-2">
                            Сервер перезапустится, чтобы применить настройки.
                        </span>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    <!-- Обслуживание -->
    @if ($canReinstall ?? false)
        <div class="card lg:col-span-2 border-amber-500/30">
            <div class="card-header"><h2 class="card-title text-amber-400">{{ __('common.update') }}</h2></div>
            <div class="card-body" x-data="{ confirm: false }">
                <div class="grid sm:grid-cols-2 gap-4">
                    <form method="POST" action="{{ route('panel.server.update', $server) }}" class="space-y-3">
                        @csrf
                        <p class="text-sm text-ink-400">{{ __('servers.update_game') }} — обновляет файлы игры, не трогая конфиги.</p>
                        <button class="btn btn-secondary btn-sm">{{ __('servers.update_game') }}</button>
                    </form>

                    <form method="POST" action="{{ route('panel.server.reinstall', $server) }}" class="space-y-3"
                          x-data="{ wipe: false }">
                        @csrf
                        <p class="text-sm text-ink-400">{{ __('servers.reinstall') }} — полная переустановка игры.</p>
                        <label class="flex items-center gap-2 text-sm text-red-300">
                            <input type="checkbox" name="wipe" value="1" x-model="wipe" class="checkbox">
                            Удалить все файлы (полный сброс)
                        </label>
                        <button class="btn btn-danger btn-sm"
                                x-bind:disabled="wipe && !confirm"
                                onclick="if (this.disabled) { if (confirm('Удалить ВСЕ файлы сервера? Действие необратимо!')) { document.querySelector('[name=wipe]').focus(); } }">
                            {{ __('servers.reinstall') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
