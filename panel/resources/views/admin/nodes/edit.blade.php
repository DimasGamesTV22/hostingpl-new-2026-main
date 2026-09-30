@extends('layouts.dashboard')

@section('title', ($node->exists ? __('common.edit') : __('nodes.create')) . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.nodes') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('nodes.title') }}
        </a>
        <h1 class="text-2xl font-bold">{{ $node->exists ? $node->name : __('nodes.create') }}</h1>
    </div>

    @if (session('node_token'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30">
            <p class="text-sm text-emerald-200 mb-2">{{ __('nodes.token_saved') }}</p>
            <code class="block font-mono text-xs bg-ink-900 border border-ink-700 rounded px-3 py-2 break-all">
                {{ session('node_token') }}
            </code>
        </div>
    @endif

    <form method="POST"
          action="{{ $node->exists ? route('admin.nodes.update', $node) : route('admin.nodes.store') }}"
          class="grid gap-4 lg:grid-cols-3">
        @csrf
        @if ($node->exists)
            @method('PUT')
        @endif

        <div class="lg:col-span-2 space-y-4">
            <!-- Основное -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.info') }}</h2></div>
                <div class="card-body grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">{{ __('common.name') }}</label>
                        <input type="text" name="name" value="{{ old('name', $node->name) }}" required class="input" maxlength="120">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">Slug</label>
                        <input type="text" name="slug" value="{{ old('slug', $node->slug) }}" class="input" maxlength="120">
                        @error('slug') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="label">{{ __('common.note') }}</label>
                        <textarea name="description" rows="2" class="input" maxlength="2000">{{ old('description', $node->description) }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Связь -->
            <div class="card" x-data="{ mode: @js(old('connection_mode', $node->connection_mode)) }">
                <div class="card-header"><h2 class="card-title">{{ __('nav.nodes') }} ↔ {{ setting('hosting.branding.name') }}</h2></div>
                <div class="card-body grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">{{ __('nodes.connection_mode') }}</label>
                        <select name="connection_mode" class="select" x-model="mode">
                            <option value="inbound" @selected(old('connection_mode', $node->connection_mode) === 'inbound')>
                                {{ __('nodes.inbound') }}
                            </option>
                            <option value="outbound" @selected(old('connection_mode', $node->connection_mode) === 'outbound')>
                                {{ __('nodes.outbound') }}
                            </option>
                        </select>
                        <p class="hint">
                            Входящий режим проще: агент сам подключается к панели по
                            <code>wss://ваш-домен/agent/ws</code>.
                        </p>
                    </div>

                    <div x-show="mode === 'outbound'" x-cloak>
                        <label class="label">{{ __('nodes.agent_url') }}</label>
                        <input type="text" name="host" value="{{ old('host', $node->host) }}" class="input" maxlength="190"
                               placeholder="10.0.0.5 или agent.example.com">
                        @error('host') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div x-show="mode === 'outbound'" x-cloak>
                        <label class="label">{{ __('nodes.agent_port') }}</label>
                        <input type="number" name="agent_port" value="{{ old('agent_port', $node->agent_port) }}"
                               class="input" min="1" max="65535">
                    </div>

                    <label class="flex items-center gap-2 text-sm" x-show="mode === 'outbound'" x-cloak>
                        <input type="checkbox" name="tls" value="1" class="checkbox" @checked($node->tls)>
                        TLS (wss / https)
                    </label>
                </div>
            </div>

            <!-- География -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('nodes.region') }}</h2></div>
                <div class="card-body grid sm:grid-cols-3 gap-4">
                    <div>
                        <label class="label">{{ __('nodes.flagship') }}</label>
                        <input type="text" name="flagship" value="{{ old('flagship', $node->flagship) }}" class="input" maxlength="190"
                               placeholder="game.example.com">
                        <p class="hint">{{ __('nodes.flagship_hint') }}</p>
                    </div>

                    <div>
                        <label class="label">{{ __('nodes.region') }}</label>
                        <select name="region" class="select">
                            <option value="">—</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region }}" @selected(old('region', $node->region) === $region)>{{ $region }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label">{{ __('nodes.country') }}</label>
                        <input type="text" name="country" value="{{ old('country', $node->country) }}" class="input"
                               maxlength="2" placeholder="DE" style="text-transform: uppercase">
                    </div>

                    <div>
                        <label class="label">{{ __('nodes.city') }}</label>
                        <input type="text" name="city" value="{{ old('city', $node->city) }}" class="input" maxlength="120">
                    </div>

                    <div>
                        <label class="label">{{ __('profile.timezone') }}</label>
                        <input type="text" name="timezone" value="{{ old('timezone', $node->timezone) }}" class="input" maxlength="64">
                    </div>
                </div>
            </div>

            <!-- Рантайм -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('nodes.runtime') }}</h2></div>
                <div class="card-body grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">{{ __('nodes.runtime') }}</label>
                        <select name="runtime" class="select">
                            @foreach ($runtimes as $key => $label)
                                <option value="{{ $key }}" @selected(old('runtime', $node->runtime) === $key)>
                                    {{ is_array($label) ? ($label['label'] ?? $key) : ($runtimes[$key] ?? $key) }}
                                </option>
                            @endforeach
                        </select>
                        @error('runtime') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-end gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="allow_ssh_fallback" value="1" class="checkbox"
                                   @checked($node->allow_ssh_fallback)>
                            SSH-резерв
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Правая колонка -->
        <div class="space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Лимиты</h2></div>
                <div class="card-body space-y-3">
                    <div>
                        <label class="label">{{ __('nodes.max_servers') }}</label>
                        <input type="number" name="max_servers" value="{{ old('max_servers', $node->max_servers) }}"
                               required class="input" min="1" max="10000">
                    </div>

                    <div>
                        <label class="label">Лимит RAM, МБ (пусто = не ограничивать)</label>
                        <input type="number" name="max_memory_mb" value="{{ old('max_memory_mb', $node->max_memory_mb) }}"
                               class="input" min="1024" step="1024">
                    </div>

                    <div>
                        <label class="label">Лимит диска, МБ</label>
                        <input type="number" name="max_disk_mb" value="{{ old('max_disk_mb', $node->max_disk_mb) }}"
                               class="input" min="10240" step="10240">
                    </div>

                    <div>
                        <label class="label">Лимит CPU, %</label>
                        <input type="number" name="max_cpu_percent" value="{{ old('max_cpu_percent', $node->max_cpu_percent) }}"
                               class="input" min="100" step="10">
                    </div>

                    <div>
                        <label class="label">{{ __('nodes.allocatable') }}</label>
                        <input type="number" name="allocatable_percent"
                               value="{{ old('allocatable_percent', $node->allocatable_percent) }}"
                               required class="input" min="10" max="100">
                    </div>

                    <div>
                        <label class="label">{{ __('nodes.reserved_memory') }}</label>
                        <input type="number" name="reserved_memory_mb"
                               value="{{ old('reserved_memory_mb', $node->reserved_memory_mb) }}"
                               required class="input" min="0" max="65536" step="256">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Балансировка</h2></div>
                <div class="card-body space-y-3">
                    <div>
                        <label class="label">{{ __('nodes.weight') }}</label>
                        <input type="number" name="weight" value="{{ old('weight', $node->weight) }}"
                               required class="input" min="1" max="1000">
                        <p class="hint">{{ __('nodes.weight_hint') }}</p>
                    </div>

                    <div>
                        <label class="label">Приоритет региона</label>
                        <input type="number" name="region_priority"
                               value="{{ old('region_priority', $node->region_priority) }}"
                               class="input" min="0" max="100">
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="prefer_over_region" value="1" class="checkbox"
                               @checked($node->prefer_over_region)>
                        Предпочитать даже при другом регионе
                    </label>

                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_default" value="1" class="checkbox" @checked($node->is_default)>
                        Нода по умолчанию
                    </label>

                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_active" value="1" class="checkbox" @checked($node->is_active)>
                        {{ __('common.enabled') }}
                    </label>
                </div>
            </div>

            <div class="flex gap-2">
                <button class="btn btn-primary flex-1">{{ __('common.save') }}</button>
                <a href="{{ route('admin.nodes') }}" class="btn btn-ghost">{{ __('common.cancel') }}</a>
            </div>
        </div>
    </form>
@endsection
