@extends('layouts.dashboard')

@section('title', __('billing.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    @php $user = $user ?? auth()->user(); @endphp

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('billing.title') }}</h1>
            <p class="mt-1 text-sm text-ink-400">{{ __('billing.grace_period', ['days' => $graceDays]) }}</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('panel.billing.transactions') }}" class="btn btn-ghost btn-sm">
                {{ __('billing.transactions') }}
            </a>
            <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-primary">
                @include('partials.icon', ['name' => 'wallet', 'class' => 'w-4 h-4'])
                {{ __('billing.top_up') }}
            </a>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="stat">
            <div class="stat-label">{{ __('billing.balance') }}</div>
            <div class="stat-value">{{ money($summary['balance']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('billing.total_deposited') }}</div>
            <div class="stat-value">{{ money($summary['total_deposited']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('billing.total_spent') }}</div>
            <div class="stat-value">{{ money($summary['total_spent']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('billing.net') }} · 90 дн.</div>
            <div @class(['stat-value', 'text-emerald-400' => $summary['net'] >= 0, 'text-red-400' => $summary['net'] < 0])>
                {{ money($summary['net']) }}
            </div>
        </div>
    </div>

    @if (! $canAfford && $pendingCharges->isNotEmpty())
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-200 text-sm flex items-center gap-3">
            @include('partials.icon', ['name' => 'alert', 'class' => 'w-5 h-5 shrink-0'])
            Недостаточно средств для оплаты начислений. {{ __('billing.auto_stop') }}.
            <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-sm btn-primary ml-auto">
                {{ __('billing.top_up') }}
            </a>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Начисления -->
        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">{{ __('billing.pending_charges') }}</h2>
                @if ($pendingTotal > 0)
                    <span class="text-sm font-semibold tabular-nums">{{ money($pendingTotal) }}</span>
                @endif
            </div>

            @if ($pendingCharges->isEmpty())
                <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('billing.no_charges') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('billing.charge') }}</th>
                                <th class="w-32">{{ __('billing.next_charge') }}</th>
                                <th class="w-32">{{ __('billing.amount') }}</th>
                                <th class="w-24">{{ __('common.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pendingCharges as $charge)
                                <tr>
                                    <td>
                                        <div class="text-sm">
                                            {{ $charge->server?->name ?? '—' }}
                                        </div>
                                        <div class="text-xs text-ink-500">
                                            {{ $charge->tariff?->name ?? $charge->type }}
                                        </div>
                                    </td>
                                    <td class="text-xs text-ink-400">{{ $charge->period_end?->format('d.m.Y') ?? '—' }}</td>
                                    <td class="tabular-nums">{{ money($charge->amount) }}</td>
                                    <td>
                                        <span @class(['badge-yellow' => ! $charge->isOverdue(), 'badge-red' => $charge->isOverdue()])>
                                            {{ $charge->isOverdue() ? 'Просрочено' : 'Ожидается' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <!-- Сводка -->
        <div class="space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('billing.summary') }} · 90 дн.</h2></div>
                <div class="card-body space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('billing.income') }}</span>
                        <span class="text-emerald-400">{{ money($summary['income']) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('billing.expense') }}</span>
                        <span>{{ money($summary['expense']) }}</span>
                    </div>
                    <div class="divider"></div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('billing.total_deposited') }}</span>
                        <span>{{ money($summary['total_deposited']) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('billing.total_spent') }}</span>
                        <span>{{ money($summary['total_spent']) }}</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('nav.store') }}</h2></div>
                <div class="card-body space-y-2">
                    <a href="{{ route('panel.store') }}" class="btn btn-secondary btn-sm w-full">
                        {{ __('billing.services') }}
                    </a>
                    <a href="{{ route('panel.store.orders') }}" class="btn btn-ghost btn-sm w-full">
                        {{ __('billing.orders') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Серверы и сроки -->
    <div class="card mt-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('servers.title') }} и сроки оплаты</h2>
        </div>

        @if ($servers->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('servers.no_servers') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.name') }}</th>
                            <th>{{ __('servers.tariff') }}</th>
                            <th class="w-40">{{ __('servers.expires') }}</th>
                            <th class="w-40">{{ __('billing.charge') }}/мес</th>
                            <th class="w-24"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            <tr>
                                <td>
                                    <a href="{{ route('panel.servers.show', $server) }}"
                                       class="font-medium hover:text-brand-300">{{ $server->name }}</a>
                                    <div class="text-xs text-ink-500">{{ $server->game->name }}</div>
                                </td>
                                <td class="text-ink-400">{{ $server->tariff?->name ?? '—' }}</td>
                                <td @class(['tabular-nums', 'text-red-400' => $server->isExpired()])>
                                    {{ $server->expires_at?->format('d.m.Y H:i') ?? '—' }}
                                    <span class="block text-xs text-ink-500">{{ $server->expiresInHuman() }}</span>
                                </td>
                                <td class="tabular-nums">
                                    {{ $server->tariff ? money($server->tariff->calculatePrice($server)) : '—' }}
                                </td>
                                <td>
                                    <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm">
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

    <!-- Тарифы -->
    <div class="mt-4">
        <h2 class="text-lg font-semibold mb-3">{{ __('nav.tariffs') }}</h2>
        @include('partials.tariff-cards', ['tariffs' => $tariffs, 'game' => null])
    </div>
@endsection
