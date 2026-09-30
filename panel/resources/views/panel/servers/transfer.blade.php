@extends('layouts.dashboard')

@section('title', __('servers.transfer') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ $server->name }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('servers.transfer') }}</h1>
    </div>

    @if ($problem)
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-200 text-sm">
            {{ $problem }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('servers.node') }}</h2></div>
            <div class="card-body text-sm space-y-2">
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('common.status') }}</span>
                    <span class="badge-{{ $currentNode?->statusColor() ?? 'gray' }}">{{ $currentNode?->statusLabel() ?? '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-ink-400">Runtime</span>
                    <span class="font-mono">{{ $server->runtime ?? default_runtime() }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('common.memory') }}</span>
                    <span>{{ mb_gb($server->memory_mb) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('common.disk') }}</span>
                    <span>{{ mb_gb($server->disk_mb) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('common.port') }}</span>
                    <span class="font-mono">{{ $server->game_port ?? '—' }}</span>
                </div>
            </div>
        </div>

        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">Доступные ноды</h2>
                <span class="text-xs text-ink-500">{{ count($candidates) }}</span>
            </div>

            @if (empty($candidates))
                <div class="px-5 py-12 text-center text-sm text-ink-400">
                    Нет нод, которые смогут принять этот сервер.
                </div>
            @else
                <form method="POST" action="{{ route('panel.server.transfer.store', $server) }}">
                    @csrf
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th class="w-10"></th>
                                    <th>{{ __('servers.node') }}</th>
                                    <th>{{ __('common.status') }}</th>
                                    <th class="w-40">Свободно</th>
                                    <th class="w-28">Серверы</th>
                                    <th class="w-20">Вес</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($candidates as $node)
                                    <tr>
                                        <td>
                                            <input type="radio" name="node_id" value="{{ $node->id }}" required
                                                   class="border-ink-500 bg-ink-800 text-brand-600 focus:ring-brand-500/50"
                                                   @checked($loop->first)>
                                        </td>
                                        <td>
                                            <div class="font-medium">{{ $node->displayName() }}</div>
                                            <div class="text-xs text-ink-500">
                                                {{ $node->runtime_options['runtimes'] ?? $node->runtime }}
                                            </div>
                                        </td>
                                        <td><span class="badge-{{ $node->statusColor() }}">{{ $node->statusLabel() }}</span></td>
                                        <td class="text-xs text-ink-400">
                                            {{ mb_gb($node->freeMemoryMb()) }} / {{ mb_gb($node->allocatableMemoryMb()) }}
                                            <div class="mt-1 h-1 rounded-full bg-ink-800 overflow-hidden">
                                                <div class="h-full bg-emerald-500"
                                                     style="width: {{ max(0, 100 - $node->memoryUsagePercent()) }}%"></div>
                                            </div>
                                        </td>
                                        <td class="tabular-nums">{{ $node->servers_count ?? $node->serverCount() }}</td>
                                        <td class="tabular-nums text-ink-400">{{ $node->weight }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="card-body border-t border-ink-800 space-y-3">
                        <div class="px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-200 text-sm">
                            Перенос — это полная переустановка сервера на новой ноде:
                            файлы игры скачиваются заново, порты назначаются новые,
                            бэкапы и плагины остаются в панели, но не переносятся физически.
                        </div>

                        <div>
                            <label class="label">Причина переноса</label>
                            <input type="text" name="reason" class="input" maxlength="255"
                                   placeholder="Плановое обслуживание">
                        </div>

                        <button class="btn btn-primary">
                            @include('partials.icon', ['name' => 'share', 'class' => 'w-4 h-4'])
                            {{ __('servers.transfer') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection
