@extends('layouts.dashboard')

@section('title', __('admin.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('admin.overview') }}</h1>
            <p class="mt-1 text-sm text-ink-400">{{ setting('hosting.branding.name') }} · {{ now()->format('d.m.Y H:i') }}</p>
        </div>

        <div class="flex items-center gap-1">
            @foreach ([7, 30, 90, 365] as $range)
                <a href="?days={{ $range }}"
                   @class(['px-2.5 py-1 rounded-md text-xs', 'bg-ink-800 text-ink-100' => $days === $range, 'text-ink-400 hover:text-ink-200' => $days !== $range])>
                    {{ $range }} дн.
                </a>
            @endforeach
        </div>
    </div>

    <!-- Ключевые цифры -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <div class="stat">
            <div class="stat-label">{{ __('admin.revenue') }} · {{ $days }} дн.</div>
            <div class="stat-value text-emerald-400">{{ $revenue['income_formatted'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('admin.pending') }}</div>
            <div class="stat-value text-amber-400">{{ $revenue['pending_formatted'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('admin.arpu') }}</div>
            <div class="stat-value">{{ money($revenue['arpu']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('admin.servers') }}</div>
            <div class="stat-value">
                {{ $revenue['active_servers'] }} <span class="text-sm text-ink-500 font-normal">/ {{ $revenue['servers'] }}</span>
            </div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('admin.users') }}</div>
            <div class="stat-value">{{ $revenue['users'] }}</div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Ноды -->
        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.nodes') }}</h2>
                <a href="{{ route('admin.nodes') }}" class="text-sm text-brand-400 hover:text-brand-300">
                    {{ __('common.show_all') }}
                </a>
            </div>

            @if ($nodes->isEmpty())
                <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('common.name') }}</th>
                                <th class="w-24">{{ __('common.status') }}</th>
                                <th class="w-40">{{ __('common.memory') }}</th>
                                <th class="w-40">{{ __('common.disk') }}</th>
                                <th class="w-24">{{ __('admin.servers') }}</th>
                                <th class="w-24">{{ __('common.queue') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($nodes as $node)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.nodes.show', $node) }}"
                                           class="font-medium hover:text-brand-300">{{ $node->name }}</a>
                                        <div class="text-xs text-ink-500">
                                            {{ $node->runtime }}
                                            @if ($node->region) · {{ $node->region }} @endif
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-{{ $node->statusColor() }}">{{ $node->statusLabel() }}</span>
                                    </td>
                                    <td>
                                        <div class="text-xs tabular-nums">
                                            {{ mb_gb($node->used_memory_mb) }} / {{ mb_gb($node->allocatableMemoryMb()) }}
                                        </div>
                                        <div class="mt-1 h-1 rounded-full bg-ink-800 overflow-hidden">
                                            <div @class(['h-full', 'bg-emerald-500' => $node->memoryUsagePercent() < 70, 'bg-amber-500' => $node->memoryUsagePercent() >= 70 && $node->memoryUsagePercent() < 90, 'bg-red-500' => $node->memoryUsagePercent() >= 90])
                                                 style="width: {{ min(100, $node->memoryUsagePercent()) }}%"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-xs tabular-nums">
                                            {{ mb_gb($node->used_disk_mb) }} / {{ mb_gb($node->allocatableDiskMb()) }}
                                        </div>
                                        <div class="mt-1 h-1 rounded-full bg-ink-800 overflow-hidden">
                                            <div @class(['h-full', 'bg-emerald-500' => $node->diskUsagePercent() < 70, 'bg-amber-500' => $node->diskUsagePercent() >= 70 && $node->diskUsagePercent() < 90, 'bg-red-500' => $node->diskUsagePercent() >= 90])
                                                 style="width: {{ min(100, $node->diskUsagePercent()) }}%"></div>
                                        </div>
                                    </td>
                                    <td class="tabular-nums">{{ $node->servers_count }}</td>
                                    <td class="tabular-nums text-ink-400">{{ $queueSizes[$node->id] ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <!-- Статусы серверов -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('admin.servers') }}</h2></div>
            <div class="card-body space-y-2">
                @php $totalStatuses = max(1, array_sum($statuses)); @endphp
                @foreach ($statuses as $status => $count)
                    <div>
                        <div class="flex justify-between text-xs">
                            <span class="text-ink-400">{{ __('servers.status.' . $status) }}</span>
                            <span class="tabular-nums">{{ $count }}</span>
                        </div>
                        <div class="mt-1 h-1.5 rounded-full bg-ink-800 overflow-hidden">
                            <div class="h-full bg-brand-500" style="width: {{ round($count / $totalStatuses * 100) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3 mt-4">
        <!-- Игры -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.by_game') }}</h2>
                <a href="{{ route('admin.games') }}" class="text-sm text-brand-400">{{ __('common.more') }}</a>
            </div>
            <div class="divide-y divide-ink-800">
                @forelse ($games as $game)
                    <div class="flex items-center gap-3 px-5 py-3">
                        <img src="{{ game_image_url($game->icon, $game->family) }}" alt=""
                             class="h-7 w-7 rounded" onerror="this.style.display='none'">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.games.edit', $game) }}" class="text-sm hover:text-brand-300">{{ $game->name }}</a>
                        </div>
                        <span class="text-sm tabular-nums text-ink-400">{{ $game->servers_count }}</span>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                @endforelse
            </div>
        </div>

        <!-- Новые пользователи -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.new_users') }}</h2>
                <a href="{{ route('admin.users') }}" class="text-sm text-brand-400">{{ __('common.more') }}</a>
            </div>
            <div class="divide-y divide-ink-800">
                @forelse ($recentUsers as $user)
                    <a href="{{ route('admin.users.show', $user) }}"
                       class="flex items-center gap-3 px-5 py-3 hover:bg-ink-800/30 transition">
                        <span class="w-7 h-7 grid place-items-center rounded-full bg-ink-700 text-xs shrink-0">
                            {{ $user->initials }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm truncate">{{ $user->name }}</div>
                            <div class="text-xs text-ink-500">{{ $user->created_at->diffForHumans() }}</div>
                        </div>
                        <span class="text-xs text-ink-400">{{ $user->servers_count }}</span>
                    </a>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                @endforelse
            </div>
        </div>

        <!-- Топ по расходам -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('admin.top_users') }}</h2></div>
            <div class="divide-y divide-ink-800">
                @forelse ($topUsers as $user)
                    <a href="{{ route('admin.users.show', $user) }}"
                       class="flex items-center gap-3 px-5 py-3 hover:bg-ink-800/30 transition">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm truncate">{{ $user->name }}</div>
                            <div class="text-xs text-ink-500 truncate">{{ $user->email }}</div>
                        </div>
                        <span class="text-sm tabular-nums text-emerald-400">{{ money($user->spent ?? 0) }}</span>
                    </a>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3 mt-4">
        <!-- Последние операции -->
        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">{{ __('billing.transactions') }}</h2>
                <a href="{{ route('admin.reports') }}" class="text-sm text-brand-400">{{ __('admin.reports') }}</a>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th>{{ __('admin.users') }}</th>
                            <th>{{ __('common.type') }}</th>
                            <th class="w-32">{{ __('billing.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentTransactions as $tx)
                            <tr>
                                <td class="whitespace-nowrap text-ink-400">{{ $tx->created_at->format('d.m.Y H:i') }}</td>
                                <td>
                                    @if ($tx->user)
                                        <a href="{{ route('admin.users.show', $tx->user_id) }}" class="hover:text-brand-300">
                                            {{ $tx->user->name }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td><span class="badge-gray">{{ $tx->type }}</span></td>
                                <td @class(['tabular-nums', 'text-emerald-400' => $tx->isCredit(), 'text-ink-300' => ! $tx->isCredit()])>
                                    {{ $tx->signedAmount() }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-ink-400 py-6">{{ __('common.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Система -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('admin.system') }}</h2></div>
            <div class="card-body space-y-1.5 text-sm">
                @foreach ([
                    __('admin.php_version') => $system['php'],
                    __('admin.laravel_version') => $system['laravel'],
                    __('admin.db_driver') => $system['db'],
                    __('admin.cache_driver') => $system['cache'],
                    __('admin.queue_driver') => $system['queue'],
                    __('admin.redis') => $system['redis'] ? 'OK' : 'Нет связи',
                    'Node mode' => $system['node_mode'],
                    'Runtime' => $system['runtime'],
                    __('admin.disk_free') => $system['disk_free'] . ' / ' . $system['disk_total'] . ' ГБ',
                    __('admin.memory_limit') => $system['memory_limit'],
                    __('admin.upload_max') => $system['upload_max'],
                ] as $label => $value)
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ $label }}</span>
                        <span @class([
                            'font-medium',
                            'text-emerald-400' => $label === __('admin.redis') && $value === 'OK',
                            'text-red-400' => $label === __('admin.redis') && $value !== 'OK',
                        ])>{{ $value }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
