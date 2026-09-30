@extends('layouts.dashboard')

@section('title', __('nav.dashboard') . ' — ' . setting('hosting.branding.name'))

@section('content')
    @php $user = auth()->user(); @endphp

    <!-- Приветствие -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold">{{ __('nav.dashboard') }}</h1>
            <p class="mt-1 text-sm text-ink-400">
                {{ $greeting ??= \Illuminate\Support\Carbon::now()->hour < 12
                    ? __('Доброе утро')
                    : (\Illuminate\Support\Carbon::now()->hour < 18 ? 'Добрый день' : 'Добрый вечер') }},
                {{ $user->name }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-secondary">
                {{ __('nav.top_up') }} · {{ money($user->balance) }}
            </a>
            <a href="{{ route('panel.servers.create') }}" class="btn btn-primary">
                @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
                {{ __('servers.create') }}
            </a>
        </div>
    </div>

    <!-- Требует действий -->
    @if ($trialEndsAt || $crashedCount > 0 || $expiring->isNotEmpty())
        <div class="space-y-3 mb-6">
            @if ($trialEndsAt && $trialEndsAt->isFuture())
                <div class="flex items-center gap-3 px-4 py-3 rounded-lg bg-sky-500/10 border border-sky-500/30 text-sky-200 text-sm">
                    @include('partials.icon', ['name' => 'clock', 'class' => 'w-5 h-5 shrink-0'])
                    <span>
                        Тестовый период заканчивается
                        <strong>{{ $trialEndsAt->format('d.m.Y H:i') }}</strong>
                        (через {{ $trialEndsAt->diffForHumans() }}).
                        Пополните баланс, чтобы серверы продолжили работать.
                    </span>
                    <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-sm btn-primary ml-auto shrink-0">
                        {{ __('nav.top_up') }}
                    </a>
                </div>
            @endif

            @if ($crashedCount > 0)
                <div class="flex items-center gap-3 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-200 text-sm">
                    @include('partials.icon', ['name' => 'alert', 'class' => 'w-5 h-5 shrink-0'])
                    <span>{{ $crashedCount }} {{ trans_choice('сервер упал|сервера упали|серверов упали', $crashedCount, [], 'ru') }}. Проверьте логи.</span>
                </div>
            @endif

            @if ($expiring->isNotEmpty())
                <div class="flex items-center gap-3 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-200 text-sm">
                    @include('partials.icon', ['name' => 'clock', 'class' => 'w-5 h-5 shrink-0'])
                    <span>
                        {{ $expiring->count() }}
                        {{ trans_choice('сервер заканчивается|сервера заканчиваются|серверов заканчиваются', $expiring->count(), [], 'ru') }}.
                    </span>
                    <a href="{{ route('panel.billing') }}" class="btn btn-sm btn-secondary ml-auto shrink-0">
                        {{ __('servers.expires') }}
                    </a>
                </div>
            @endif
        </div>
    @endif

    <!-- Статистика -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="stat">
            <div class="stat-label">{{ __('servers.title') }}</div>
            <div class="stat-value">{{ $serversCount }} <span class="text-sm text-ink-500 font-normal">/ {{ $quota['total'] }}</span></div>
        </div>

        <div class="stat">
            <div class="stat-label">{{ __('common.status') }}</div>
            <div class="stat-value text-emerald-400">{{ $runningCount }}</div>
        </div>

        <div class="stat">
            <div class="stat-label">{{ __('common.players') }}</div>
            <div class="stat-value">{{ $playersTotal }}</div>
        </div>

        <div class="stat">
            <div class="stat-label">{{ __('nav.balance') }}</div>
            <div class="stat-value">{{ money($summary['balance']) }}</div>
        </div>
    </div>

    <!-- Серверы -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                @include('partials.icon', ['name' => 'server', 'class' => 'w-5 h-5'])
                {{ __('nav.my_servers') }}
            </h2>
            @if ($servers->isNotEmpty())
                <a href="{{ route('panel.servers.index') }}" class="text-sm text-brand-400 hover:text-brand-300">
                    {{ __('common.show_all') }}
                </a>
            @endif
        </div>

        @if ($servers->isEmpty())
            <div class="card-body text-center py-12">
                <div class="mx-auto w-14 h-14 grid place-items-center rounded-xl bg-ink-800 text-ink-500 mb-4">
                    @include('partials.icon', ['name' => 'server', 'class' => 'w-7 h-7'])
                </div>
                <p class="font-medium">{{ __('servers.no_servers') }}</p>
                <p class="mt-1 text-sm text-ink-400">{{ __('servers.no_servers_hint') }}</p>
                <a href="{{ route('panel.servers.create') }}" class="btn btn-primary mt-6">
                    {{ __('servers.create_first') }}
                </a>
            </div>
        @else
            <div class="divide-y divide-ink-800">
                @foreach ($servers as $server)
                    <div class="flex flex-wrap items-center gap-4 px-5 py-4 hover:bg-ink-800/30 transition">
                        <span class="status-dot status-{{ $server->isRunning() ? 'online' : ($server->isTransitional() ? 'starting' : 'offline') }}"></span>

                        <div class="min-w-0 flex-1">
                            <a href="{{ route('panel.servers.show', $server) }}"
                               class="font-medium hover:text-brand-300 truncate block">{{ $server->name }}</a>
                            <div class="text-xs text-ink-400 flex items-center gap-2 mt-0.5 flex-wrap">
                                <span>{{ $server->game->name }}</span>
                                @if ($server->address)
                                    <code class="text-ink-300">{{ $server->address }}</code>
                                @endif
                                @if ($server->node)
                                    <span class="text-ink-500">· {{ $server->node->name }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="text-right shrink-0">
                            <span class="badge-{{ $server->statusColor() }}">{{ $server->statusLabel() }}</span>
                            @if ($server->isRunning() && $server->slots > 0)
                                <div class="text-xs text-ink-400 mt-1 tabular-nums">
                                    {{ $server->players_online }} / {{ $server->slots }}
                                </div>
                            @endif
                        </div>

                        <div class="text-right shrink-0 min-w-[110px]">
                            <div @class([
                                'text-sm tabular-nums',
                                'text-red-400' => $server->isExpired(),
                                'text-amber-400' => $server->expires_at && $server->expires_at->lt(now()->addDays(3)),
                            ])>
                                {{ $server->expiresInHuman() }}
                            </div>
                            <div class="text-xs text-ink-500">{{ __('servers.expires') }}</div>
                        </div>

                        <div class="flex items-center gap-1 shrink-0">
                            @if ($server->canStart())
                                <form method="POST" action="{{ route('panel.server.start', $server) }}">
                                    @csrf
                                    <button class="btn btn-ghost btn-sm text-emerald-400" title="{{ __('servers.start') }}">
                                        @include('partials.icon', ['name' => 'play', 'class' => 'w-4 h-4'])
                                    </button>
                                </form>
                            @endif

                            @if ($server->canStop())
                                <form method="POST" action="{{ route('panel.server.stop', $server) }}">
                                    @csrf
                                    <button class="btn btn-ghost btn-sm text-red-400" title="{{ __('servers.stop') }}">
                                        @include('partials.icon', ['name' => 'stop', 'class' => 'w-4 h-4'])
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
        @endif
    </div>

    <!-- Операции -->
    <div class="grid lg:grid-cols-2 gap-4 mt-6">
        <!-- Последние операции -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('billing.transactions') }}</h2>
                <a href="{{ route('panel.billing.transactions') }}" class="text-sm text-brand-400 hover:text-brand-300">
                    {{ __('common.show_all') }}
                </a>
            </div>

            @if ($recentTransactions->isEmpty())
                <div class="card-body text-sm text-ink-400">{{ __('common.no_data') }}</div>
            @else
                <div class="divide-y divide-ink-800">
                    @foreach ($recentTransactions as $tx)
                        <div class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                            <div class="min-w-0">
                                <div class="truncate">{{ $tx->title }}</div>
                                <div class="text-xs text-ink-500">{{ $tx->created_at->diffForHumans() }}</div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div @class(['font-medium tabular-nums', 'text-emerald-400' => $tx->isCredit(), 'text-ink-300' => ! $tx->isCredit()])>
                                    {{ $tx->signedAmount() }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Сводка по деньгам -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('billing.summary') }} · 30 дней</h2>
                <a href="{{ route('panel.billing') }}" class="text-sm text-brand-400 hover:text-brand-300">
                    {{ __('nav.billing') }}
                </a>
            </div>

            <div class="card-body space-y-3">
                <div class="flex justify-between text-sm">
                    <span class="text-ink-400">{{ __('billing.income') }}</span>
                    <span class="font-medium text-emerald-400">{{ money($summary['income']) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-ink-400">{{ __('billing.expense') }}</span>
                    <span class="font-medium">{{ money($summary['expense']) }}</span>
                </div>
                <div class="divider"></div>
                <div class="flex justify-between text-sm">
                    <span class="text-ink-400">{{ __('billing.total_deposited') }}</span>
                    <span class="font-medium">{{ money($summary['total_deposited']) }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-ink-400">{{ __('billing.total_spent') }}</span>
                    <span class="font-medium">{{ money($summary['total_spent']) }}</span>
                </div>

                @if ($openTickets > 0)
                    <div class="divider"></div>
                    <a href="{{ route('panel.tickets.index') }}" class="flex justify-between text-sm text-brand-400 hover:text-brand-300">
                        <span>{{ __('nav.tickets') }}</span>
                        <span class="badge-blue">{{ $openTickets }}</span>
                    </a>
                @endif
            </div>
        </div>
    </div>
@endsection
