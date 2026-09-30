@extends('layouts.dashboard')

@section('title', $server->name . ' — ' . setting('hosting.branding.name'))

@section('content')
    @php $active = request()->query('tab', 'overview'); @endphp

    <!-- Шапка -->
    <div class="mb-6">
        <a href="{{ route('panel.servers.index') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('servers.title') }}
        </a>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="status-dot {{ $server->isRunning() ? 'status-online' : ($server->isTransitional() ? 'status-starting' : 'status-offline') }}"></span>
                    <h1 class="text-2xl font-bold truncate">{{ $server->name }}</h1>
                    <span class="badge-{{ $server->statusColor() }}">{{ $server->statusLabel() }}</span>
                    @if ($server->is_frozen)
                        <span class="badge-red">{{ __('servers.status.suspended') }}</span>
                    @endif
                </div>

                <div class="mt-2 flex items-center gap-3 text-sm text-ink-400 flex-wrap">
                    <span class="flex items-center gap-1.5">
                        <img src="{{ game_image_url($server->game->icon, $server->game->family) }}" alt=""
                             class="h-4 w-4 rounded" onerror="this.style.display='none'">
                        {{ $server->game->name }}
                        @if ($server->build_version) <span class="text-ink-600">{{ $server->build_version }}</span> @endif
                    </span>
                    @if ($server->node)
                        <span>· {{ $server->node->name }}</span>
                    @endif
                    @if ($server->tariff)
                        <span>· {{ $server->tariff->name }}</span>
                    @endif
                </div>
            </div>

            <!-- Управление -->
            <div class="flex flex-wrap items-center gap-2">
                @if ($server->address)
                    <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-ink-850 border border-ink-700"
                         x-data="{ copied: false }">
                        <span class="text-xs text-ink-400">{{ __('servers.connect_address') }}</span>
                        <code class="text-sm">{{ $server->address }}</code>
                        <button type="button" class="text-ink-400 hover:text-ink-100"
                                @click="navigator.clipboard.writeText(@js($server->address)); copied = true; setTimeout(() => copied = false, 1500)">
                            @include('partials.icon', ['name' => $server->isRunning() ? 'check' : 'file', 'class' => 'w-4 h-4'])
                        </button>
                    </div>
                @endif

                @if ($permissions['power'])
                    @if ($server->canStart())
                        <form method="POST" action="{{ route('panel.server.start', $server) }}">
                            @csrf
                            <button class="btn btn-success btn-sm">
                                @include('partials.icon', ['name' => 'play', 'class' => 'w-4 h-4'])
                                {{ __('servers.start') }}
                            </button>
                        </form>
                    @endif

                    @if ($server->canStop())
                        <form method="POST" action="{{ route('panel.server.stop', $server) }}">
                            @csrf
                            <button class="btn btn-secondary btn-sm">
                                @include('partials.icon', ['name' => 'stop', 'class' => 'w-4 h-4'])
                                {{ __('servers.stop') }}
                            </button>
                        </form>
                    @endif

                    @if ($server->isRunning())
                        <form method="POST" action="{{ route('panel.server.restart', $server) }}">
                            @csrf
                            <button class="btn btn-secondary btn-sm">
                                @include('partials.icon', ['name' => 'refresh', 'class' => 'w-4 h-4'])
                                {{ __('servers.restart_server') }}
                            </button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    </div>

    <!-- Предупреждения -->
    @if ($server->status_reason)
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-200 text-sm flex items-start gap-3">
            @include('partials.icon', ['name' => 'alert', 'class' => 'w-5 h-5 shrink-0 mt-0.5'])
            <div>
                <strong>{{ $server->statusLabel() }}:</strong> {{ $server->status_reason }}
                @if (in_array($server->status, ['error', 'crashed'], true))
                    <form method="POST" action="{{ route('panel.server.reinstall', $server) }}" class="mt-2">
                        @csrf
                        <button class="btn btn-sm btn-secondary">{{ __('servers.reinstall') }}</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @if ($server->isExpired())
        <div class="mb-4 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-200 text-sm flex items-center gap-3">
            @include('partials.icon', ['name' => 'clock', 'class' => 'w-5 h-5 shrink-0'])
            Срок оплаты истёк {{ $server->expires_at?->format('d.m.Y H:i') }}.
            @if ($server->is_frozen)
                Данные хранятся до {{ $server->purge_at?->format('d.m.Y') }}.
            @endif
            <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-sm btn-primary ml-auto">
                {{ __('nav.top_up') }}
            </a>
        </div>
    @endif

    <!-- Установка -->
    @if ($server->isInstalling())
        <div class="card mb-4">
            <div class="card-body">
                <div class="flex justify-between text-sm mb-2">
                    <span class="font-medium">{{ __('servers.installing') }}…</span>
                    <span class="tabular-nums text-amber-300">{{ $server->install_progress }}%</span>
                </div>
                <div class="h-2 rounded-full bg-ink-800 overflow-hidden mb-4">
                    <div class="h-full bg-amber-500 transition-all duration-500"
                         style="width: {{ $server->install_progress }}%"></div>
                </div>

                @if ($installLogs->isNotEmpty())
                    <div class="space-y-1.5 max-h-40 overflow-y-auto">
                        @foreach ($installLogs as $step)
                            <div class="flex items-center gap-2 text-xs">
                                <span class="badge-{{ $step->statusColor() }}">{{ $step->status }}</span>
                                <span class="text-ink-300">{{ $step->name }}</span>
                                @if ($step->duration_ms)
                                    <span class="text-ink-600 ml-auto">{{ round($step->duration_ms / 1000, 1) }}с</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Вкладки -->
    <div class="border-b border-ink-800 mb-6 overflow-x-auto scrollbar-none">
        <nav class="flex gap-1 min-w-max">
            @php
                $tabs = [
                    'overview' => ['label' => __('servers.info'), 'icon' => 'home'],
                    'console' => ['label' => __('servers.console'), 'icon' => 'terminal'],
                    'files' => ['label' => __('servers.files'), 'icon' => 'folder'],
                    'backups' => ['label' => __('servers.backups'), 'icon' => 'archive'],
                    'plugins' => ['label' => __('servers.plugins'), 'icon' => 'gamepad'],
                    'schedules' => ['label' => __('servers.schedules'), 'icon' => 'clock'],
                    'settings' => ['label' => __('servers.settings'), 'icon' => 'cog'],
                    'events' => ['label' => __('servers.events'), 'icon' => 'chart'],
                ];
            @endphp

            @foreach ($tabs as $key => $tab)
                <a href="?tab={{ $key }}"
                   @class([
                       'flex items-center gap-1.5 px-4 py-2.5 text-sm border-b-2 -mb-px transition whitespace-nowrap',
                       'border-brand-500 text-ink-100' => $active === $key,
                       'border-transparent text-ink-400 hover:text-ink-200' => $active !== $key,
                   ])>
                    @include('partials.icon', ['name' => $tab['icon'], 'class' => 'w-4 h-4'])
                    {{ $tab['label'] }}
                </a>
            @endforeach

            @can('manageSubAccounts', $server)
                <a href="{{ route('panel.server.sub_accounts', $server) }}?tab=overview"
                   @class([
                       'flex items-center gap-1.5 px-4 py-2.5 text-sm border-b-2 -mb-px transition whitespace-nowrap',
                       'border-brand-500 text-ink-100' => $active === 'sub',
                       'border-transparent text-ink-400 hover:text-ink-200' => $active !== 'sub',
                   ])>
                    @include('partials.icon', ['name' => 'users', 'class' => 'w-4 h-4'])
                    {{ __('servers.sub_accounts') }}
                </a>
            @endcan
        </nav>
    </div>

    @include('panel.servers.tabs.' . $active)
@endsection
