@extends('layouts.dashboard')

@section('title', __('security.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    @php $user = $user ?? auth()->user(); @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('security.title') }}</h1>
    </div>

    @if ($twoFactorRequired && ! $user->hasTwoFactor())
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-200 text-sm">
            {{ __('security.two_factor_required') }}
        </div>
    @elseif ($graceExpired && ! $user->hasTwoFactor())
        <div class="mb-4 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-200 text-sm">
            {{ __('security.two_factor_grace', ['days' => setting('hosting.auth.two_factor.grace_days', 7)]) }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <!-- 2FA -->
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">
                    @include('partials.icon', ['name' => 'shield', 'class' => 'w-5 h-5'])
                    {{ __('security.two_factor') }}
                </h2>
                @if ($user->hasTwoFactor())
                    <span class="badge-green">{{ __('common.enabled') }}</span>
                @else
                    <span class="badge-gray">{{ __('common.disabled') }}</span>
                @endif
            </div>

            <div class="card-body">
                @if ($user->hasTwoFactor())
                    <p class="text-sm text-ink-400 mb-4">{{ __('security.two_factor_hint') }}</p>

                    <div class="flex flex-wrap items-center gap-2 text-sm mb-5">
                        <span class="text-ink-400">{{ __('security.enabled_at') }}:</span>
                        <span>{{ $user->two_factor_enabled_at?->format('d.m.Y H:i') ?? '—' }}</span>
                    </div>

                    <div class="space-y-3">
                        <form method="POST" action="{{ route('panel.security.2fa.disable') }}"
                              onsubmit="return confirm('{{ __('common.confirm') }}')" class="flex flex-wrap gap-2">
                            @csrf
                            <input type="password" name="password" required class="input max-w-xs" placeholder="Пароль">
                            <button class="btn btn-danger btn-sm">{{ __('security.disable') }}</button>
                        </form>

                        <form method="POST" action="{{ route('panel.security.2fa.regenerate') }}" class="flex flex-wrap gap-2">
                            @csrf
                            <input type="password" name="password" required class="input max-w-xs" placeholder="Пароль">
                            <button class="btn btn-secondary btn-sm">{{ __('security.regenerate') }}</button>
                        </form>
                    </div>
                @else
                    <p class="text-sm text-ink-400 mb-5">{{ __('security.two_factor_hint') }}</p>

                    @if ($setup)
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div>
                                <p class="label">{{ __('security.scan_qr') }}</p>
                                <img src="{{ $setup['qr'] }}" alt="QR" class="w-44 h-44 rounded-lg bg-white p-2">
                            </div>

                            <div>
                                <p class="label">{{ __('security.secret') }}</p>
                                <code class="block font-mono text-sm bg-ink-900 border border-ink-700 rounded-lg px-3 py-2 break-all">
                                    {{ $setup['manual'] }}
                                </code>
                                <p class="hint">{{ __('security.manual_entry') }}</p>

                                <form method="POST" action="{{ route('panel.security.2fa.confirm') }}" class="mt-4">
                                    @csrf
                                    <label class="label">{{ __('security.confirm_code') }}</label>
                                    <div class="flex gap-2">
                                        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                                               maxlength="6" required class="input font-mono tracking-widest w-32"
                                               placeholder="000000">
                                        <button class="btn btn-primary">{{ __('security.enable') }}</button>
                                    </div>
                                    @error('code') <p class="error">{{ $message }}</p> @enderror
                                </form>
                            </div>
                        </div>
                    @else
                        <form method="POST" action="{{ route('panel.security.2fa.enable') }}">
                            @csrf
                            <button class="btn btn-primary btn-sm">{{ __('security.enable') }}</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>

        <!-- Резервные коды -->
        @if ($recoveryCodes)
            <div class="card lg:col-span-2">
                <div class="card-header">
                    <h2 class="card-title">{{ __('security.recovery_codes') }}</h2>
                </div>
                <div class="card-body">
                    <p class="text-sm text-ink-400 mb-4">{{ __('security.recovery_codes_hint') }}</p>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2" x-data="{ copied: false }">
                        @foreach ($recoveryCodes as $recovery)
                            <code class="px-2 py-1.5 rounded-md bg-ink-900 border border-ink-700 text-center font-mono text-xs">
                                {{ $recovery }}
                            </code>
                        @endforeach
                    </div>

                    <button class="btn btn-secondary btn-sm mt-4"
                            @click="navigator.clipboard.writeText(@js(implode("\n", $recoveryCodes))); copied = true; setTimeout(() => copied = false, 1500)"
                            type="button"
                            x-text="copied ? '{{ __('common.copied') }}' : '{{ __('common.copy') }}'">
                    </button>
                </div>
            </div>
        @endif

        <!-- Сессии -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('security.sessions') }}</h2>
                <span class="text-xs text-ink-500">{{ $sessionCount }}</span>
            </div>
            <div class="card-body space-y-3">
                <p class="text-sm text-ink-400">{{ __('security.sessions_hint') }}</p>
                <a href="{{ route('panel.security.sessions') }}" class="btn btn-secondary btn-sm w-full">
                    {{ __('security.sessions') }}
                </a>
                <a href="{{ route('panel.security.login_history') }}" class="btn btn-ghost btn-sm w-full">
                    {{ __('security.login_history') }}
                </a>
                @if ($passwordAge)
                    <p class="text-xs text-ink-500">
                        {{ __('security.change_password') }}: {{ $passwordAge }}
                    </p>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('profile.change_password') }}</h2>
            </div>
            <div class="card-body">
                <p class="text-sm text-ink-400 mb-4">
                    После смены пароля все остальные сессии будут завершены.
                </p>
                <a href="{{ route('panel.profile') }}" class="btn btn-secondary btn-sm w-full">
                    {{ __('common.edit') }}
                </a>
            </div>
        </div>
    </div>
@endsection
