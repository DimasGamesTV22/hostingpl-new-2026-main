@extends('layouts.dashboard')

@section('title', __('referral.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('referral.title') }}</h1>
        <p class="mt-1 text-sm text-ink-400">
            {{ __('referral.reward_referrer', ['amount' => $rewardReferrer]) }},
            {{ __('referral.reward_referred', ['amount' => $rewardReferred]) }}.
            {{ __('referral.min_payment', ['amount' => $minPayment]) }}.
        </p>
    </div>

    @unless ($enabled)
        <div class="mb-4 px-4 py-3 rounded-lg bg-ink-800 border border-ink-700 text-sm text-ink-300">
            Реферальная программа отключена в настройках панели.
        </div>
    @endunless

    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Ссылка -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    @include('partials.icon', ['name' => 'share', 'class' => 'w-5 h-5'])
                    {{ __('referral.your_link') }}
                </h2>
            </div>
            <div class="card-body space-y-3" x-data="{ copied: false }">
                <div class="flex gap-2">
                    <input type="text" readonly value="{{ $link }}" class="input font-mono !text-xs" x-ref="link">
                    <button type="button" class="btn btn-secondary btn-sm shrink-0"
                            @click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 1500)"
                            x-text="copied ? '{{ __('common.copied') }}' : '{{ __('common.copy') }}'">
                    </button>
                </div>

                <div class="flex gap-2">
                    <a class="btn btn-primary btn-sm flex-1" target="_blank" rel="noopener"
                       href="https://t.me/share/url?url={{ urlencode($link) }}&text={{ urlencode(__('referral.invite_friend')) }}">
                        Telegram
                    </a>
                    <a class="btn btn-secondary btn-sm flex-1" target="_blank" rel="noopener"
                       href="https://vk.com/share.php?url={{ urlencode($link) }}">
                        ВКонтакте
                    </a>
                </div>

                <div class="divider"></div>

                <p class="text-xs text-ink-400">{{ __('referral.apply_code') }}</p>
                @unless ($user->referred_by)
                    <form method="POST" action="{{ route('panel.referral.apply') }}" class="flex gap-2">
                        @csrf
                        <input type="text" name="code" required class="input font-mono uppercase" maxlength="16"
                               placeholder="КОД">
                        <button class="btn btn-secondary btn-sm">{{ __('common.apply') }}</button>
                    </form>
                @else
                    <div class="text-sm text-ink-300">
                        Пригласитель:
                        <code class="font-mono">{{ $user->referrer?->referral_code ?? $user->referred_by }}</code>
                    </div>
                @endunless
            </div>
        </div>

        <!-- Статистика -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('profile.stats') }}</h2></div>
            <div class="card-body space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('referral.invited') }}</span>
                    <span class="font-medium">{{ $stats['total'] }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('referral.status.pending') }}</span>
                    <span class="text-amber-400">{{ $stats['pending'] }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('referral.status.completed') }}</span>
                    <span class="text-emerald-400">{{ $stats['completed'] }}</span>
                </div>
                <div class="divider"></div>
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('referral.earned') }}</span>
                    <span class="font-semibold text-emerald-400">{{ $stats['earnedFormatted'] }}</span>
                </div>
            </div>
        </div>

        <!-- Список -->
        <div class="lg:col-span-3 card">
            <div class="card-header">
                <h2 class="card-title">{{ __('nav.referrals') }}</h2>
                <span class="text-xs text-ink-500">{{ $referrals->count() }}</span>
            </div>

            @if ($referrals->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-ink-400">
                    {{ __('referral.your_link') }} — поделитесь ей, чтобы пригласить друзей.
                </div>
            @else
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
                            @foreach ($referrals as $referral)
                                <tr>
                                    <td class="whitespace-nowrap text-ink-400">
                                        {{ $referral->created_at->format('d.m.Y') }}
                                    </td>
                                    <td>
                                        <div class="text-sm">{{ $referral->referred?->name ?? '—' }}</div>
                                        <div class="text-xs text-ink-500">{{ $referral->referred?->email }}</div>
                                    </td>
                                    <td><span class="badge-{{ $referral->statusColor() }}">{{ $referral->statusLabel() }}</span></td>
                                    <td class="tabular-nums text-emerald-400">
                                        {{ $referral->status === \App\Models\Referral::STATUS_COMPLETED
                                            ? money($referral->reward_referrer)
                                            : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
