@extends('layouts.dashboard')

@section('title', __('nav.referrals') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('nav.referrals') }}</h1>
        <p class="mt-1 text-sm text-ink-400">{{ __('referral.title') }} — {{ $enabled ? 'включена' : 'выключена' }}</p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="stat">
            <div class="stat-label">{{ __('referral.invited') }}</div>
            <div class="stat-value">{{ $stats['total'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('referral.status.completed') }}</div>
            <div class="stat-value text-emerald-400">{{ $stats['completed'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('referral.status.pending') }}</div>
            <div class="stat-value text-amber-400">{{ $stats['pending'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('referral.earned') }}</div>
            <div class="stat-value">{{ money($stats['paid']) }}</div>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['' => __('common.all'), 'pending' => __('referral.status.pending'), 'completed' => __('referral.status.completed'), 'rejected' => __('referral.status.rejected')] as $value => $label)
            <a href="{{ $value ? route('admin.referrals', ['status' => $value]) : route('admin.referrals') }}"
               @class(['px-3 py-1.5 rounded-lg text-xs border', 'bg-ink-800 border-ink-600 text-ink-100' => request('status') === $value || ($value === '' && ! request('status')), 'border-ink-700 text-ink-400 hover:text-ink-200' => request('status') !== $value && ! ($value === '' && ! request('status'))])>
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="card">
        @if ($referrals->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th>Пригласил</th>
                            <th>Приглашён</th>
                            <th class="w-40">{{ __('common.status') }}</th>
                            <th class="w-32">Заказ</th>
                            <th class="w-32">{{ __('referral.earned') }}</th>
                            <th class="w-40"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($referrals as $referral)
                            <tr>
                                <td class="whitespace-nowrap text-ink-400">{{ $referral->created_at->format('d.m.Y') }}</td>
                                <td>
                                    <a href="{{ route('admin.users.show', $referral->referrer_id) }}" class="hover:text-brand-300">
                                        {{ $referral->referrer?->name ?? '—' }}
                                    </a>
                                    <div class="text-xs text-ink-500 font-mono">{{ $referral->referrer?->referral_code }}</div>
                                </td>
                                <td>
                                    @if ($referral->referred)
                                        <a href="{{ route('admin.users.show', $referral->referred_id) }}" class="hover:text-brand-300">
                                            {{ $referral->referred->name }}
                                        </a>
                                        <div class="text-xs text-ink-500">{{ $referral->referred->email }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <span class="badge-{{ $referral->statusColor() }}">{{ $referral->statusLabel() }}</span>
                                    @if ($referral->rejected_reason)
                                        <div class="text-xs text-red-400 mt-0.5">{{ $referral->rejected_reason }}</div>
                                    @endif
                                </td>
                                <td class="tabular-nums text-ink-400">{{ money($referral->order_amount) }}</td>
                                <td class="tabular-nums">
                                    {{ money((float) $referral->reward_referrer + (float) $referral->reward_referred) }}
                                </td>
                                <td>
                                    @if ($referral->status === \App\Models\Referral::STATUS_PENDING)
                                        <div class="flex items-center gap-1 justify-end">
                                            <form method="POST" action="{{ route('admin.referrals.complete', $referral) }}">
                                                @csrf
                                                <button class="btn btn-success btn-sm">{{ __('common.apply') }}</button>
                                            </form>

                                            <form method="POST" action="{{ route('admin.referrals.reject', $referral) }}"
                                                  class="flex gap-1" x-data="{ open: false }">
                                                @csrf
                                                <input type="text" name="reason" x-show="open" x-cloak required
                                                       class="input !py-1 !text-xs w-32" placeholder="причина">
                                                <button class="btn btn-ghost btn-sm text-red-400"
                                                        @click="if (open) $el.closest('form').submit(); else open = true"
                                                        x-text="open ? 'OK' : '✕'"></button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-ink-800">{{ $referrals->links() }}</div>
        @endif
    </div>
@endsection
