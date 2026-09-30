@extends('layouts.dashboard')

@section('title', $user->name . ' — ' . __('admin.users'))

@section('content')
    @php
        $tab = request()->query('tab', 'info');
    @endphp

    <div class="mb-6">
        <a href="{{ route('admin.users') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('admin.users') }}
        </a>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-4">
                @if ($user->avatar_url)
                    <img src="{{ $user->avatar_url }}" alt="" class="w-14 h-14 rounded-full object-cover">
                @else
                    <div class="w-14 h-14 grid place-items-center rounded-full bg-ink-700 text-lg font-semibold">
                        {{ $user->initials }}
                    </div>
                @endif

                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-2xl font-bold">{{ $user->name }}</h1>
                        <span class="badge-{{ $user->statusColor() }}">{{ $user->statusLabel() }}</span>
                        @if ($user->isStaff())
                            <span class="badge-indigo">{{ $user->roleLabel() }}</span>
                        @endif
                        @if (! $user->isEmailVerified())
                            <span class="badge-yellow">email не подтверждён</span>
                        @endif
                    </div>
                    <div class="mt-1 text-sm text-ink-400 flex items-center gap-3 flex-wrap">
                        <span>{{ $user->email }}</span>
                        <span>ID: {{ $user->id }}</span>
                        <span>· {{ __('profile.registered') }} {{ $user->created_at->format('d.m.Y') }}</span>
                        @if ($user->last_seen_at)
                            <span>· {{ __('servers.last_seen') }} {{ $user->last_seen_at->diffForHumans() }}</span>
                        @endif
                    </div>
                    @if ($user->block_reason)
                        <p class="mt-2 text-xs text-red-400">{{ $user->block_reason }}</p>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @can('impersonate', $user)
                    <form method="POST" action="{{ route('admin.users.impersonate', $user) }}">
                        @csrf
                        <button class="btn btn-secondary btn-sm">{{ __('admin.impersonate') }}</button>
                    </form>
                @endcan

                @can('block', $user)
                    @if ($user->status === 'blocked')
                        <form method="POST" action="{{ route('admin.users.unblock', $user) }}">
                            @csrf
                            <button class="btn btn-success btn-sm">Разблокировать</button>
                        </form>
                    @else
                        <button class="btn btn-danger btn-sm" x-data="{ show: false }"
                                @click="show = ! show; $nextTick(() => show && $refs.pwd.focus())"
                                x-ref="btn">
                            Заблокировать
                        </button>
                    @endif
                @endcan
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="stat">
            <div class="stat-label">{{ __('nav.balance') }}</div>
            <div class="stat-value">{{ money($user->balance) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('admin.servers') }}</div>
            <div class="stat-value">{{ $user->servers_count }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('billing.total_deposited') }}</div>
            <div class="stat-value">{{ money($user->total_deposited) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('nav.referrals') }}</div>
            <div class="stat-value">{{ $user->referrals_sent_count ?? $user->referralsSent()->count() }}</div>
        </div>
    </div>

    <div class="border-b border-ink-800 mb-6 overflow-x-auto scrollbar-none">
        <nav class="flex gap-1 min-w-max">
            @foreach ([
                'info' => 'Данные',
                'balance' => __('nav.balance'),
                'servers' => __('admin.servers'),
                'transactions' => __('billing.transactions'),
                'tickets' => __('nav.tickets'),
                'security' => __('nav.security'),
                'referrals' => __('nav.referrals'),
                'logs' => __('nav.audit'),
            ] as $key => $label)
                <a href="?tab={{ $key }}"
                   @class([
                       'flex items-center gap-1.5 px-4 py-2.5 text-sm border-b-2 -mb-px transition whitespace-nowrap',
                       'border-brand-500 text-ink-100' => $tab === $key,
                       'border-transparent text-ink-400 hover:text-ink-200' => $tab !== $key,
                   ])>
                    {{ $label }}
                </a>
            @endforeach
        </nav>
    </div>

    @if ($tab === 'info')
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 card">
                <div class="card-header"><h2 class="card-title">Данные пользователя</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="label">{{ __('common.name') }}</label>
                                <input type="text" name="name" value="{{ old('name', $user->name) }}"
                                       class="input" maxlength="60">
                                @error('name') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="label">Email</label>
                                <input type="email" name="email" value="{{ old('email', $user->email) }}"
                                       class="input" maxlength="190">
                                @error('email') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="label">{{ __('profile.telegram') }}</label>
                                <input type="text" name="contact_telegram"
                                       value="{{ old('contact_telegram', $user->contact_telegram) }}"
                                       class="input" maxlength="64">
                            </div>
                        </div>

                        <div>
                            <label class="label">{{ __('common.note') }} (видна только в админке)</label>
                            <textarea name="note" rows="3" class="input" maxlength="1000">{{ old('note', $user->note) }}</textarea>
                        </div>

                        <button class="btn btn-primary btn-sm">{{ __('common.save') }}</button>
                    </form>
                </div>
            </div>

            <div class="space-y-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Роль</h2></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.users.role', $user) }}" class="space-y-3">
                            @csrf
                            <select name="role" class="select">
                                @foreach (\App\Models\User::ROLES as $role)
                                    <option value="{{ $role }}" @selected($user->role === $role)>{{ $role }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-secondary btn-sm w-full">{{ __('common.apply') }}</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2 class="card-title">Сессии</h2></div>
                    <div class="divide-y divide-ink-800">
                        @forelse ($sessions as $session)
                            <div class="px-5 py-3">
                                <div class="text-sm">{{ $session->deviceLabel() }}</div>
                                <div class="text-xs text-ink-500 font-mono">
                                    {{ $session->ip }} · {{ $session->last_activity_at?->diffForHumans() }}
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-6 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        @if ($user->status !== 'blocked' && auth()->user()->can('block', $user))
            <div class="card mt-4 border-red-500/30" x-data="{ open: false }">
                <div class="card-header">
                    <h2 class="card-title text-red-400">Блокировка</h2>
                    <button class="btn btn-danger btn-sm" @click="open = ! open">
                        <span x-text="open ? 'Скрыть' : 'Заблокировать'"></span>
                    </button>
                </div>
                <div class="card-body" x-show="open" x-cloak>
                    <form method="POST" action="{{ route('admin.users.block', $user) }}" class="space-y-3">
                        @csrf
                        <div>
                            <label class="label">Причина</label>
                            <input type="text" name="reason" required class="input" maxlength="255"
                                   placeholder="Нарушение правил, мошенничество…" x-ref="pwd">
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <p class="text-xs text-ink-400">
                            Все серверы пользователя будут остановлены, активные сессии завершены.
                        </p>
                        <button class="btn btn-danger btn-sm">{{ __('common.confirm') }}</button>
                    </form>
                </div>
            </div>
        @endif
    @elseif ($tab === 'balance')
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Изменить баланс</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.users.balance', $user) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="label">{{ __('billing.amount') }}</label>
                            <input type="number" name="amount" required step="0.01" class="input"
                                   placeholder="500 / -500">
                            <p class="hint">Положительное значение — начислить, отрицательное — списать.</p>
                            @error('amount') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">{{ __('common.reason') }}</label>
                            <input type="text" name="reason" required class="input" maxlength="255"
                                   placeholder="Компенсация / ручная оплата">
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <button class="btn btn-primary btn-sm w-full">{{ __('common.apply') }}</button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-2 card">
                <div class="card-header"><h2 class="card-title">{{ __('billing.transactions') }}</h2></div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('common.date') }}</th>
                                <th>{{ __('common.type') }}</th>
                                <th>{{ __('common.name') }}</th>
                                <th class="w-32">{{ __('billing.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($transactions as $tx)
                                <tr>
                                    <td class="whitespace-nowrap text-ink-400">{{ $tx->created_at->format('d.m.Y H:i') }}</td>
                                    <td><span class="badge-gray">{{ $tx->typeLabel() }}</span></td>
                                    <td class="text-sm">{{ $tx->title }}</td>
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
        </div>

    @elseif ($tab === 'servers')
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('admin.servers') }}</h2></div>
            @if ($servers->isEmpty())
                <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('common.name') }}</th>
                                <th>{{ __('admin.games') }}</th>
                                <th>{{ __('admin.nodes') }}</th>
                                <th class="w-28">{{ __('common.status') }}</th>
                                <th class="w-32">{{ __('servers.expires') }}</th>
                                <th class="w-32">{{ __('common.memory') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($servers as $server)
                                <tr>
                                    <td>
                                        <a href="{{ route('panel.servers.show', $server) }}"
                                           class="font-medium hover:text-brand-300">{{ $server->name }}</a>
                                        <div class="text-xs text-ink-500 font-mono">{{ $server->address ?? '—' }}</div>
                                    </td>
                                    <td class="text-ink-400">{{ $server->game?->name }}</td>
                                    <td class="text-ink-400">{{ $server->node?->name ?? '—' }}</td>
                                    <td><span class="badge-{{ $server->statusColor() }}">{{ $server->statusLabel() }}</span></td>
                                    <td @class(['text-xs', 'text-red-400' => $server->isExpired()])>
                                        {{ $server->expires_at?->format('d.m.Y') ?? '—' }}
                                    </td>
                                    <td class="text-xs tabular-nums">{{ mb_gb($server->memory_mb) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    @elseif ($tab === 'transactions')
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('billing.transactions') }}</h2>
                <a href="{{ route('panel.billing.transactions') }}" class="text-sm text-brand-400">Открыть полностью</a>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th>{{ __('common.type') }}</th>
                            <th>{{ __('common.name') }}</th>
                            <th class="w-32">{{ __('billing.amount') }}</th>
                            <th class="w-32">{{ __('billing.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transactions as $tx)
                            <tr>
                                <td class="whitespace-nowrap text-ink-400">{{ $tx->created_at->format('d.m.Y H:i') }}</td>
                                <td><span class="badge-gray">{{ $tx->typeLabel() }}</span></td>
                                <td class="text-sm">{{ $tx->title }}</td>
                                <td @class(['tabular-nums', 'text-emerald-400' => $tx->isCredit(), 'text-ink-300' => ! $tx->isCredit()])>
                                    {{ $tx->signedAmount() }}
                                </td>
                                <td class="tabular-nums text-ink-400">{{ money($tx->balance_after) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-ink-400 py-6">{{ __('common.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    @elseif ($tab === 'tickets')
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('nav.tickets') }}</h2></div>
            <div class="divide-y divide-ink-800">
                @forelse ($tickets as $ticket)
                    <a href="{{ route('admin.tickets.show', $ticket) }}"
                       class="flex items-center gap-3 px-5 py-3 hover:bg-ink-800/30 transition">
                        <span class="text-xs text-ink-500">#{{ $ticket->id }}</span>
                        <span class="flex-1 text-sm truncate">{{ $ticket->subject }}</span>
                        <span class="badge-{{ $ticket->statusColor() }}">{{ $ticket->statusLabel() }}</span>
                        <span class="text-xs text-ink-500">{{ $ticket->created_at->format('d.m.Y') }}</span>
                    </a>
                @empty
                    <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                @endforelse
            </div>
        </div>

    @elseif ($tab === 'security')
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('security.title') }}</h2></div>
                <div class="card-body space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-ink-400">Email подтверждён</span>
                        <span>{{ $user->isEmailVerified() ? 'да' : 'нет' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">2FA</span>
                        <span>{{ $user->hasTwoFactor() ? 'включена' : 'выключена' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">Пароль изменён</span>
                        <span>{{ $user->password_changed_at?->diffForHumans() ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">Регистрация</span>
                        <span>{{ $user->created_at->format('d.m.Y H:i') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">Вход по OAuth</span>
                        <span>{{ $user->oauthAccounts->count() }}</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('security.login_history') }}</h2></div>
                <div class="divide-y divide-ink-800">
                    @forelse ($authLogs as $log)
                        <div class="px-5 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm">{{ $log->action }}</span>
                                <span class="text-xs text-ink-500">{{ $log->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="text-xs text-ink-500 font-mono">{{ $log->ip ?? '—' }}</div>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                    @endforelse
                </div>
            </div>
        </div>

    @elseif ($tab === 'referrals')
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('nav.referrals') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th>{{ __('common.name') }}</th>
                            <th class="w-40">{{ __('common.status') }}</th>
                            <th class="w-32">{{ __('referral.earned') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($referrals as $referral)
                            <tr>
                                <td class="whitespace-nowrap text-ink-400">{{ $referral->created_at->format('d.m.Y') }}</td>
                                <td>
                                    <a href="{{ route('admin.users.show', $referral->referred_id) }}" class="hover:text-brand-300">
                                        {{ $referral->referred?->name ?? '—' }}
                                    </a>
                                    <div class="text-xs text-ink-500">{{ $referral->referred?->email }}</div>
                                </td>
                                <td><span class="badge-{{ $referral->statusColor() }}">{{ $referral->statusLabel() }}</span></td>
                                <td class="tabular-nums">{{ money($referral->reward_referrer) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-ink-400 py-6">{{ __('common.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    @elseif ($tab === 'logs')
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.audit') }}</h2>
                <a href="{{ route('admin.audit', ['user' => $user->id]) }}" class="text-sm text-brand-400">
                    {{ __('common.show_all') }}
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th>{{ __('common.actions') }}</th>
                            <th>{{ __('common.name') }}</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($authLogs as $log)
                            <tr>
                                <td class="whitespace-nowrap text-ink-400">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
                                <td><span class="badge-{{ $log->color() }}">{{ $log->action }}</span></td>
                                <td class="text-sm">{{ Str::limit($log->description, 80) }}</td>
                                <td class="text-xs font-mono text-ink-500">{{ $log->ip ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-ink-400 py-6">{{ __('common.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
