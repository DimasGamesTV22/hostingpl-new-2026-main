@extends('layouts.dashboard')

@section('title', __('nodes.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('nodes.title') }}</h1>
            <p class="mt-1 text-sm text-ink-400">
                Режим распределения:
                <span class="text-ink-200 font-medium">{{ $modes[$currentMode] ?? $currentMode }}</span>
                · рантайм по умолчанию: <span class="text-ink-200">{{ $runtimes[0] ?? 'docker' }}</span>
            </p>
        </div>

        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('admin.nodes.sync') }}">
                @csrf
                <button class="btn btn-ghost btn-sm">{{ __('common.refresh') }}</button>
            </form>
            <a href="{{ route('admin.nodes.create') }}" class="btn btn-primary">
                @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
                {{ __('nodes.create') }}
            </a>
        </div>
    </div>

    <div class="mb-4 px-4 py-3 rounded-lg bg-ink-850 border border-ink-700 text-xs text-ink-400">
        {{ __('nodes.mode_hint') }}
        Режим меняется в <code>config/hosting.php</code> → <code>node_mode</code>
        (значения: <code>single</code>, <code>manual</code>, <code>auto</code>).
    </div>

    @if ($nodes->isEmpty())
        <div class="card">
            <div class="px-5 py-16 text-center">
                <div class="mx-auto w-14 h-14 grid place-items-center rounded-xl bg-ink-800 text-ink-500 mb-4">
                    @include('partials.icon', ['name' => 'server-cog', 'class' => 'w-7 h-7'])
                </div>
                <p class="text-sm font-medium">{{ __('nodes.errors.no_nodes') }}</p>
                <p class="mt-1 text-sm text-ink-400">Добавьте ноду, скопируйте токен и запустите агент на сервере.</p>
                <a href="{{ route('admin.nodes.create') }}" class="btn btn-primary mt-6">{{ __('nodes.create') }}</a>
            </div>
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($nodes as $node)
                <div @class([
                    'card p-5',
                    'border-red-500/40' => $node->status === 'offline' && $node->is_active,
                    'ring-1 ring-brand-500/50' => $node->is_default,
                ])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <a href="{{ route('admin.nodes.show', $node) }}" class="font-semibold hover:text-brand-300">
                                    {{ $node->displayName() }}
                                </a>
                                <span class="badge-{{ $node->statusColor() }}">{{ $node->statusLabel() }}</span>
                                @if ($node->is_default)
                                    <span class="badge-indigo">по умолчанию</span>
                                @endif
                            </div>
                            <div class="text-xs text-ink-500 mt-1 flex items-center gap-2 flex-wrap">
                                <span>{{ __('nodes.runtimes.' . $node->runtime, $node->runtime) }}</span>
                                @if ($node->region)
                                    <span>· {{ $node->region }}</span>
                                @endif
                                @if ($node->agent_version)
                                    <span>· agent {{ $node->agent_version }}</span>
                                @endif
                            </div>
                        </div>

                        <form method="POST" action="{{ route('admin.nodes.ping', $node) }}" class="shrink-0">
                            @csrf
                            <button class="btn btn-ghost btn-sm" title="{{ __('common.test_connection') }}">
                                @include('partials.icon', ['name' => 'refresh', 'class' => 'w-4 h-4'])
                            </button>
                        </form>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-lg bg-ink-900 p-3">
                            <dt class="text-[10px] uppercase tracking-wider text-ink-500">{{ __('nodes.free_memory') }}</dt>
                            <dd class="mt-0.5 font-medium tabular-nums">
                                {{ mb_gb($node->freeMemoryMb()) }} / {{ mb_gb($node->allocatableMemoryMb()) }}
                            </dd>
                            <div class="mt-2 h-1 rounded-full bg-ink-800 overflow-hidden">
                                <div @class(['h-full', 'bg-emerald-500' => $node->memoryUsagePercent() < 70, 'bg-amber-500' => $node->memoryUsagePercent() >= 70 && $node->memoryUsagePercent() < 90, 'bg-red-500' => $node->memoryUsagePercent() >= 90])
                                     style="width: {{ min(100, $node->memoryUsagePercent()) }}%"></div>
                            </div>
                        </div>

                        <div class="rounded-lg bg-ink-900 p-3">
                            <dt class="text-[10px] uppercase tracking-wider text-ink-500">{{ __('nodes.free_disk') }}</dt>
                            <dd class="mt-0.5 font-medium tabular-nums">
                                {{ mb_gb($node->freeDiskMb()) }} / {{ mb_gb($node->allocatableDiskMb()) }}
                            </dd>
                            <div class="mt-2 h-1 rounded-full bg-ink-800 overflow-hidden">
                                <div @class(['h-full', 'bg-emerald-500' => $node->diskUsagePercent() < 70, 'bg-amber-500' => $node->diskUsagePercent() >= 70 && $node->diskUsagePercent() < 90, 'bg-red-500' => $node->diskUsagePercent() >= 90])
                                     style="width: {{ min(100, $node->diskUsagePercent()) }}%"></div>
                            </div>
                        </div>
                    </dl>

                    <div class="mt-3 flex items-center justify-between text-xs text-ink-400">
                        <span>{{ __('admin.servers') }}: {{ $node->servers_count }} / {{ $node->max_servers }}</span>
                        <span>{{ __('nodes.heartbeat') }}:
                            {{ $node->last_heartbeat_at?->diffForHumans() ?? '—' }}
                        </span>
                    </div>

                    <div class="mt-4 pt-3 border-t border-ink-800 flex items-center gap-2">
                        <a href="{{ route('admin.nodes.show', $node) }}" class="btn btn-secondary btn-sm">
                            {{ __('common.more') }}
                        </a>
                        <a href="{{ route('admin.nodes.edit', $node) }}" class="btn btn-ghost btn-sm">
                            {{ __('common.edit') }}
                        </a>
                        <span class="ml-auto text-xs text-ink-600">вес: {{ $node->weight }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
