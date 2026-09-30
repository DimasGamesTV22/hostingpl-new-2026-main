@extends('layouts.dashboard')

@section('title', __('billing.transactions') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('billing.transactions') }}</h1>
            <p class="mt-1 text-sm text-ink-400">{{ $transactions->total() }} записей</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('panel.billing.transactions.export') }}" class="btn btn-secondary btn-sm">
                @include('partials.icon', ['name' => 'download', 'class' => 'w-4 h-4'])
                {{ __('billing.export') }}
            </a>
            <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-primary btn-sm">
                {{ __('billing.top_up') }}
            </a>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('panel.billing.transactions') }}" class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="label">{{ __('common.type') }}</label>
                    <select name="type" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>
                                {{ __('billing.types.' . $type) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label">{{ __('common.from') }}</label>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input !py-1.5 !text-xs">
                </div>

                <div>
                    <label class="label">{{ __('common.to') }}</label>
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input !py-1.5 !text-xs">
                </div>

                <button class="btn btn-primary btn-sm">{{ __('common.apply_filter') }}</button>
                <a href="{{ route('panel.billing.transactions') }}" class="btn btn-ghost btn-sm">{{ __('common.reset_filter') }}</a>
            </form>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">{{ __('billing.transactions') }}</h2>
            </div>

            @if ($transactions->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
            @else
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
                            @foreach ($transactions as $tx)
                                <tr>
                                    <td class="whitespace-nowrap text-ink-400">
                                        {{ $tx->created_at->format('d.m.Y H:i') }}
                                    </td>
                                    <td>
                                        <span class="badge-gray">{{ $tx->typeLabel() }}</span>
                                        @if ($tx->status !== 'completed')
                                            <span class="badge-yellow ml-1">{{ $tx->status }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="text-sm">{{ $tx->title }}</div>
                                        @if ($tx->description)
                                            <div class="text-xs text-ink-500">{{ Str::limit($tx->description, 80) }}</div>
                                        @endif
                                    </td>
                                    <td @class(['tabular-nums font-medium', 'text-emerald-400' => $tx->isCredit(), 'text-ink-300' => ! $tx->isCredit()])>
                                        {{ $tx->signedAmount() }}
                                    </td>
                                    <td class="tabular-nums text-ink-400">{{ money($tx->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-4 border-t border-ink-800">{{ $transactions->links() }}</div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('billing.deposits') }}</h2>
                </div>
                @if ($deposits->isEmpty())
                    <div class="px-5 py-8 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                @else
                    <div class="divide-y divide-ink-800">
                        @foreach ($deposits as $deposit)
                            <a href="{{ route('panel.billing.deposits.show', $deposit->uuid) }}"
                               class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-ink-800/30 transition">
                                <div class="min-w-0">
                                    <div class="text-sm tabular-nums">{{ money($deposit->amount) }}</div>
                                    <div class="text-xs text-ink-500">
                                        {{ $deposit->methodLabel() }} · {{ $deposit->created_at->format('d.m.Y') }}
                                    </div>
                                </div>
                                <span class="badge-{{ $deposit->statusColor() }}">{{ $deposit->status }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('billing.withdraw') }}</h2>
                </div>
                <div class="card-body">
                    <p class="text-xs text-ink-400 mb-4">
                        Заявка создаёт тикет в отделе поддержки. Вывод доступен от
                        {{ money(setting('hosting.billing.min_refund', 1)) }}.
                    </p>
                    <form method="POST" action="{{ route('panel.billing.withdraw') }}" class="space-y-3"
                          onsubmit="return confirm('{{ __('common.confirm') }}')">
                        @csrf
                        <div>
                            <label class="label">{{ __('billing.amount') }}</label>
                            <input type="number" name="amount" required step="0.01" min="{{ setting('hosting.billing.min_refund', 1) }}"
                                   max="{{ auth()->user()->balance }}" class="input">
                            @error('amount') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">{{ __('billing.method') }}</label>
                            <input type="text" name="method" required class="input" maxlength="64"
                                   placeholder="Карта / СБП / крипта">
                            @error('method') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">{{ __('common.reason') }}</label>
                            <textarea name="details" required rows="3" class="input" maxlength="500"
                                      placeholder="Реквизиты для перевода"></textarea>
                            @error('details') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <button class="btn btn-secondary btn-sm w-full">{{ __('common.send') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
