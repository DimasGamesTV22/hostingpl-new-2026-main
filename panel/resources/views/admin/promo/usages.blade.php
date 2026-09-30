@extends('layouts.dashboard')

@section('title', $promo->code . ' — ' . __('promo.my_uses'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.promo.edit', $promo) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('nav.promo_codes') }}
        </a>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold font-mono">{{ $promo->code }}</h1>
            <span class="badge-{{ $promo->statusColor() }}">{{ $promo->valueLabel() }}</span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">{{ __('promo.my_uses') }}</h2>
            <span class="text-xs text-ink-500">{{ $uses->total() }}</span>
        </div>

        @if ($uses->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th>{{ __('admin.users') }}</th>
                            <th>{{ __('servers.server') }}</th>
                            <th class="w-28">Скидка</th>
                            <th class="w-24">Дни</th>
                            <th class="w-28">Бонус</th>
                            <th class="w-28">Слоты</th>
                            <th class="w-32">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($uses as $use)
                            <tr>
                                <td class="whitespace-nowrap text-ink-400">{{ $use->created_at->format('d.m.Y H:i') }}</td>
                                <td>
                                    @if ($use->user)
                                        <a href="{{ route('admin.users.show', $use->user_id) }}" class="hover:text-brand-300">
                                            {{ $use->user->name }}
                                        </a>
                                        <div class="text-xs text-ink-500">{{ $use->user->email }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-ink-400">{{ $use->server?->name ?? '—' }}</td>
                                <td class="tabular-nums">{{ (float) $use->discount > 0 ? money($use->discount) : '—' }}</td>
                                <td class="tabular-nums">{{ $use->days_added ?: '—' }}</td>
                                <td class="tabular-nums">{{ (float) $use->bonus_applied > 0 ? money($use->bonus_applied) : '—' }}</td>
                                <td class="tabular-nums">{{ $use->slots_added ?: '—' }}</td>
                                <td class="text-xs font-mono text-ink-500">{{ $use->ip ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-ink-800">{{ $uses->links() }}</div>
        @endif
    </div>
@endsection
