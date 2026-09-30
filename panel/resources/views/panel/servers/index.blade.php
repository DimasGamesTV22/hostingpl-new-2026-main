@extends('layouts.dashboard')

@section('title', __('servers.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold">{{ __('servers.title') }}</h1>
            <p class="mt-1 text-sm text-ink-400">
                {{ __('servers.servers_count', ['used' => $quota['used'], 'total' => $quota['total']]) }}
            </p>
        </div>

        <a href="{{ route('panel.servers.create') }}"
           @class(['btn', 'btn-primary' => $quota['used'] < $quota['total'], 'btn-secondary opacity-50 cursor-not-allowed pointer-events-none' => $quota['used'] >= $quota['total']])>
            @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
            {{ __('servers.create') }}
        </a>
    </div>

    @if ($trialEndsAt && $trialEndsAt->isFuture())
        <div class="mb-4 px-4 py-3 rounded-lg bg-sky-500/10 border border-sky-500/30 text-sky-200 text-sm">
            Тестовый период до {{ $trialEndsAt->format('d.m.Y H:i') }}
            ({{ $trialEndsAt->diffForHumans() }}). После окончания серверы будут остановлены,
            если не пополнить баланс.
            <a href="{{ route('panel.billing.deposit.create') }}" class="underline">{{ __('nav.top_up') }}</a>
        </div>
    @endif

    @if ($servers->isEmpty())
        <div class="card">
            <div class="card-body text-center py-16">
                <div class="mx-auto w-16 h-16 grid place-items-center rounded-2xl bg-ink-800 text-ink-500 mb-5">
                    @include('partials.icon', ['name' => 'server', 'class' => 'w-8 h-8'])
                </div>
                <h2 class="text-lg font-semibold">{{ __('servers.no_servers') }}</h2>
                <p class="mt-2 text-ink-400 max-w-md mx-auto">{{ __('servers.no_servers_hint') }}</p>
                <a href="{{ route('panel.servers.create') }}" class="btn btn-primary mt-6">
                    {{ __('servers.create_first') }}
                </a>
            </div>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($servers as $server)
                <div @class([
                    'card p-5 transition',
                    'border-red-500/40' => in_array($server->status, ['crashed', 'error'], true),
                    'ring-1 ring-amber-500/40' => $server->isExpired(),
                ])>
                    <!-- Шапка -->
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('panel.servers.show', $server) }}"
                               class="font-semibold text-ink-100 hover:text-brand-300 block truncate">
                                {{ $server->name }}
                            </a>
                            <div class="text-xs text-ink-400 mt-0.5 flex items-center gap-1.5">
                                <img src="{{ game_image_url($server->game->icon, $server->game->family) }}" alt=""
                                     class="h-3.5 w-3.5 rounded" onerror="this.style.display='none'">
                                {{ $server->game->name }}
                                @if ($server->build_version)
                                    <span class="text-ink-600">· {{ $server->build_version }}</span>
                                @endif
                            </div>
                        </div>

                        <span class="status-dot {{ $server->isRunning() ? 'status-online' : ($server->isTransitional() ? 'status-starting' : 'status-offline') }} mt-1.5 shrink-0"
                              title="{{ $server->statusLabel() }}"></span>
                    </div>

                    <!-- Адрес -->
                    @if ($server->address)
                        <div class="mt-4 flex items-center gap-2 px-3 py-2 rounded-lg bg-ink-900 group">
                            <code class="text-sm text-ink-200 flex-1 truncate">{{ $server->address }}</code>
                            <button type="button" class="opacity-0 group-hover:opacity-100 transition"
                                    x-data x-on:click="navigator.clipboard.writeText(@js($server->address))"
                                    title="{{ __('common.copy') }}">
                                @include('partials.icon', ['name' => 'file', 'class' => 'w-3.5 h-3.5'])
                            </button>
                        </div>
                    @endif

                    <!-- Установка -->
                    @if ($server->isInstalling())
                        <div class="mt-4">
                            <div class="flex justify-between text-xs text-amber-300 mb-1.5">
                                <span>{{ __('servers.installing') }}</span>
                                <span>{{ $server->install_progress }}%</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-ink-800 overflow-hidden">
                                <div class="h-full bg-amber-500 transition-all"
                                     style="width: {{ $server->install_progress }}%"></div>
                            </div>
                        </div>
                    @endif

                    <!-- Метрики -->
                    <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                        <div class="py-2 rounded-lg bg-ink-900">
                            <dt class="text-[10px] uppercase tracking-wider text-ink-500">{{ __('common.cpu') }}</dt>
                            <dd class="text-sm font-medium tabular-nums">{{ round($server->cpu_usage) }}%</dd>
                        </div>
                        <div class="py-2 rounded-lg bg-ink-900">
                            <dt class="text-[10px] uppercase tracking-wider text-ink-500">{{ __('common.memory') }}</dt>
                            <dd class="text-sm font-medium tabular-nums">{{ round($server->memory_usage_mb) }}МБ</dd>
                        </div>
                        <div class="py-2 rounded-lg bg-ink-900">
                            <dt class="text-[10px] uppercase tracking-wider text-ink-500">{{ __('common.players') }}</dt>
                            <dd class="text-sm font-medium tabular-nums">
                                @if ($server->isRunning() && $server->slots > 0)
                                    {{ $server->players_online }}/{{ $server->slots }}
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <!-- Ошибка -->
                    @if ($server->status_reason)
                        <p class="mt-3 text-xs text-red-400 line-clamp-2">{{ $server->status_reason }}</p>
                    @endif

                    <!-- Оплата -->
                    <div class="mt-4 pt-3 border-t border-ink-800 flex items-center justify-between text-sm">
                        <span class="text-ink-500">{{ __('servers.expires') }}</span>
                        <span @class([
                            'font-medium tabular-nums',
                            'text-red-400' => $server->isExpired(),
                            'text-amber-400' => $server->expires_at && $server->expires_at->lt(now()->addDays(3)),
                        ])>
                            {{ $server->expires_at?->format('d.m.Y H:i') ?? '—' }}
                        </span>
                    </div>

                    <!-- Управление -->
                    <div class="mt-4 flex items-center gap-1.5">
                        @if ($server->canStart())
                            <form method="POST" action="{{ route('panel.server.start', $server) }}">
                                @csrf
                                <button class="btn btn-success btn-sm flex-1">
                                    @include('partials.icon', ['name' => 'play', 'class' => 'w-3.5 h-3.5'])
                                    {{ __('servers.start') }}
                                </button>
                            </form>
                        @endif

                        @if ($server->canStop())
                            <form method="POST" action="{{ route('panel.server.stop', $server) }}">
                                @csrf
                                <button class="btn btn-secondary btn-sm flex-1">
                                    @include('partials.icon', ['name' => 'stop', 'class' => 'w-3.5 h-3.5'])
                                    {{ __('servers.stop') }}
                                </button>
                            </form>
                        @endif

                        <a href="{{ route('panel.server.console', $server) }}" class="btn btn-ghost btn-sm"
                           title="{{ __('servers.console') }}">
                            @include('partials.icon', ['name' => 'terminal', 'class' => 'w-4 h-4'])
                        </a>

                        <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm"
                           title="{{ __('common.more') }}">
                            @include('partials.icon', ['name' => 'chevron', 'class' => 'w-4 h-4'])
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">{{ $servers->links() }}</div>
    @endif
@endsection
