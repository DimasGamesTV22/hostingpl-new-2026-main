@extends('layouts.dashboard')

@section('title', $node->name . ' — ' . __('nodes.title'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.nodes') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('nodes.title') }}
        </a>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="text-2xl font-bold">{{ $node->displayName() }}</h1>
                    <span class="badge-{{ $node->statusColor() }}">{{ $node->statusLabel() }}</span>
                    @if ($node->is_default)
                        <span class="badge-indigo">по умолчанию</span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-ink-400">
                    {{ __('nodes.runtimes.' . $node->runtime, $node->runtime) }}
                    @if ($node->region) · {{ $node->region }} @endif
                    @if ($node->city) · {{ $node->city }} @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2" x-data="{ busy: false, result: null }">
                <form method="POST" action="{{ route('admin.nodes.ping', $node) }}">
                    @csrf
                    <button class="btn btn-secondary btn-sm">{{ __('common.test_connection') }}</button>
                </form>

                <form method="POST" action="{{ route('admin.nodes.token', $node) }}"
                      onsubmit="return confirm('{{ __('common.confirm') }}?')">
                    @csrf
                    <button class="btn btn-ghost btn-sm">{{ __('nodes.token') }} ↻</button>
                </form>

                <form method="POST" action="{{ route('admin.nodes.ports', $node) }}">
                    @csrf
                    <button class="btn btn-ghost btn-sm">{{ __('nodes.ports_pool') }} +</button>
                </form>

                <a href="{{ route('admin.nodes.edit', $node) }}" class="btn btn-primary btn-sm">{{ __('common.edit') }}</a>
            </div>
        </div>
    </div>

    @if (session('node_token'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30">
            <p class="text-sm text-emerald-200 mb-2">{{ __('nodes.token_saved') }}</p>
            <code class="block font-mono text-xs bg-ink-900 border border-ink-700 rounded px-3 py-2 break-all">
                {{ session('node_token') }}
            </code>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Диагностика -->
        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">{{ __('nodes.diagnostics') }}</h2>
                <span class="text-xs text-ink-500">
                    {{ $diagnostics['agent_version'] ?? '—' }} ·
                    {{ __('nodes.heartbeat') }} {{ $diagnostics['heartbeat_age'] ?? '—' }}с назад
                </span>
            </div>

            <div class="card-body">
                @if (($diagnostics['status'] ?? '') === 'offline')
                    <div class="mb-4 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-200 text-sm">
                        {{ __('nodes.errors.all_offline') }}
                        Проверьте: запущен ли агент на ноде, правильный ли токен,
                        открыт ли порт WSS-сервера панели.
                    </div>
                @endif

                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-ink-900 p-4">
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-ink-400">{{ __('nodes.free_memory') }}</span>
                            <span class="tabular-nums">
                                {{ mb_gb($diagnostics['memory']['used']) }} /
                                {{ mb_gb($diagnostics['memory']['allocatable']) }}
                            </span>
                        </div>
                        <div class="h-1.5 rounded-full bg-ink-800 overflow-hidden">
                            <div @class(['h-full', 'bg-emerald-500' => $diagnostics['memory']['percent'] < 70, 'bg-amber-500' => $diagnostics['memory']['percent'] >= 70 && $diagnostics['memory']['percent'] < 90, 'bg-red-500' => $diagnostics['memory']['percent'] >= 90])
                                 style="width: {{ min(100, $diagnostics['memory']['percent']) }}%"></div>
                        </div>
                        <div class="mt-2 text-xs text-ink-500">
                            всего на ноде: {{ mb_gb($diagnostics['memory']['total']) }} ·
                            свободно: {{ mb_gb($diagnostics['memory']['free']) }}
                        </div>
                    </div>

                    <div class="rounded-lg bg-ink-900 p-4">
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-ink-400">{{ __('nodes.free_disk') }}</span>
                            <span class="tabular-nums">
                                {{ mb_gb($diagnostics['disk']['used']) }} /
                                {{ mb_gb($diagnostics['disk']['allocatable']) }}
                            </span>
                        </div>
                        <div class="h-1.5 rounded-full bg-ink-800 overflow-hidden">
                            <div @class(['h-full', 'bg-emerald-500' => $diagnostics['disk']['percent'] < 70, 'bg-amber-500' => $diagnostics['disk']['percent'] >= 70 && $diagnostics['disk']['percent'] < 90, 'bg-red-500' => $diagnostics['disk']['percent'] >= 90])
                                 style="width: {{ min(100, $diagnostics['disk']['percent']) }}%"></div>
                        </div>
                        <div class="mt-2 text-xs text-ink-500">
                            всего: {{ mb_gb($diagnostics['disk']['total']) }} ·
                            свободно: {{ mb_gb($diagnostics['disk']['free']) }}
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid sm:grid-cols-3 gap-3 text-sm">
                    <div class="rounded-lg bg-ink-900 p-3">
                        <div class="text-[10px] uppercase tracking-wider text-ink-500">{{ __('admin.servers') }}</div>
                        <div class="mt-0.5 font-semibold tabular-nums">
                            {{ $diagnostics['servers']['running'] }} / {{ $diagnostics['servers']['total'] }}
                        </div>
                        <div class="text-xs text-ink-500">лимит: {{ $diagnostics['servers']['limit'] }}</div>
                    </div>

                    <div class="rounded-lg bg-ink-900 p-3">
                        <div class="text-[10px] uppercase tracking-wider text-ink-500">{{ __('nodes.queue') }}</div>
                        <div class="mt-0.5 font-semibold tabular-nums">{{ $diagnostics['queue_size'] }}</div>
                        <div class="text-xs text-ink-500">команд в очереди</div>
                    </div>

                    <div class="rounded-lg bg-ink-900 p-3">
                        <div class="text-[10px] uppercase tracking-wider text-ink-500">{{ __('common.uptime') }}</div>
                        <div class="mt-0.5 font-semibold tabular-nums">
                            {{ $diagnostics['uptime_percent'] !== null ? $diagnostics['uptime_percent'] . '%' : '—' }}
                        </div>
                        <div class="text-xs text-ink-500">за сутки</div>
                    </div>
                </div>

                <div class="mt-4">
                    <p class="label">{{ __('nodes.runtimes_available') }}</p>
                    <div class="flex flex-wrap gap-2">
                        @forelse ((array) ($diagnostics['runtimes'] ?? []) as $runtime)
                            <span class="badge-green">{{ $runtime }}</span>
                        @empty
                            <span class="text-xs text-ink-500">—</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <!-- Установка агента -->
        <div class="space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('nodes.install_command') }}</h2></div>
                <div class="card-body">
                    <p class="text-xs text-ink-400 mb-3">
                        Выполните на сервере ноды от root. Токен уже подставлен.
                    </p>
                    <pre class="code text-[11px] whitespace-pre-wrap break-all">{{ $installCommand }}</pre>

                    <div class="mt-3">
                        <p class="label">Ручная настройка</p>
                        <pre class="code text-[11px]">{
    "panel": "{{ $config['panel'] }}",
    "ws_url": "{{ $config['ws_url'] }}",
    "node_id": {{ $config['node_id'] }},
    "token": "{{ $config['token'] }}",
    "runtime": "{{ $config['runtime'] }}",
    "servers_root": "{{ $config['servers_root'] }}",
    "backups_root": "{{ $config['backups_root'] }}"
}</pre>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title text-red-400">Удаление ноды</h2></div>
                <div class="card-body" x-data="{ open: false }">
                    <p class="text-xs text-ink-400 mb-3">
                        Ноду можно удалить, только если на ней нет серверов.
                    </p>
                    <button class="btn btn-danger btn-sm w-full" @click="open = ! open">
                        <span x-text="open ? 'Отмена' : 'Удалить ноду'"></span>
                    </button>

                    <form x-show="open" x-cloak method="POST" action="{{ route('admin.nodes.destroy', $node) }}"
                          class="mt-3" onsubmit="return confirm('{{ __('common.confirm') }}')">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="confirm" value="{{ $node->name }}">
                        <button class="btn btn-danger btn-sm w-full">
                            {{ __('common.confirm') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Серверы -->
    <div class="card mt-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('admin.servers') }}</h2>
            <span class="text-xs text-ink-500">{{ $servers->count() }}</span>
        </div>

        @if ($servers->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.name') }}</th>
                            <th>{{ __('admin.users') }}</th>
                            <th>{{ __('admin.games') }}</th>
                            <th class="w-28">{{ __('common.status') }}</th>
                            <th class="w-32">{{ __('common.memory') }}</th>
                            <th class="w-24">{{ __('common.port') }}</th>
                            <th class="w-16"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            <tr>
                                <td>
                                    <a href="{{ route('panel.servers.show', $server) }}"
                                       class="font-medium hover:text-brand-300">{{ $server->name }}</a>
                                </td>
                                <td class="text-ink-400">{{ $server->user?->name ?? '—' }}</td>
                                <td class="text-ink-400">{{ $server->game?->name }}</td>
                                <td><span class="badge-{{ $server->statusColor() }}">{{ $server->statusLabel() }}</span></td>
                                <td class="text-xs tabular-nums">{{ mb_gb($server->memory_mb) }}</td>
                                <td class="font-mono text-xs">{{ $server->game_port ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm"
                                       title="{{ __('servers.transfer') }}">
                                        @include('partials.icon', ['name' => 'chevron', 'class' => 'w-4 h-4'])
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- История состояния -->
    <div class="card mt-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('common.updated') }} — {{ __('nodes.diagnostics') }}</h2>
            <span class="text-xs text-ink-500">{{ $health->count() }}</span>
        </div>

        @if ($health->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th class="w-20">{{ __('common.status') }}</th>
                            <th class="w-24">CPU</th>
                            <th class="w-40">{{ __('common.memory') }}</th>
                            <th class="w-40">{{ __('common.disk') }}</th>
                            <th class="w-24">Load</th>
                            <th class="w-24">Сеть</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($health as $log)
                            <tr>
                                <td class="whitespace-nowrap text-xs text-ink-400">{{ $log->created_at->format('d.m.Y H:i') }}</td>
                                <td>
                                    <span class="status-dot {{ $log->is_online ? 'status-online' : 'status-offline' }}"></span>
                                </td>
                                <td class="text-xs tabular-nums">{{ $log->cpu_percent }}%</td>
                                <td class="text-xs tabular-nums">
                                    {{ mb_gb($log->memory_used_mb) }} / {{ mb_gb($log->memory_total_mb) }}
                                </td>
                                <td class="text-xs tabular-nums">
                                    {{ mb_gb($log->disk_used_mb) }} / {{ mb_gb($log->disk_total_mb) }}
                                </td>
                                <td class="text-xs tabular-nums">{{ $log->load_1 }}</td>
                                <td class="text-xs tabular-nums">
                                    ↓{{ $log->network_in_mbps }} ↑{{ $log->network_out_mbps }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
