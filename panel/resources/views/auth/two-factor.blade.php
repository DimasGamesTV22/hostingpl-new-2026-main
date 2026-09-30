@extends('layouts.auth')

@section('title', __('auth.two_factor_title'))

@section('content')
    <div class="text-center mb-6">
        <div class="mx-auto w-12 h-12 grid place-items-center rounded-xl bg-brand-500/10 text-brand-400 mb-4">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
            </svg>
        </div>
        <h1 class="text-xl font-semibold">{{ __('auth.two_factor_title') }}</h1>
        <p class="mt-1 text-sm text-ink-400">{{ __('auth.two_factor_subtitle') }}</p>
    </div>

    <form method="POST" action="{{ route('2fa.verify') }}" class="space-y-4">
        @csrf

        <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus
               autocomplete="one-time-code" required
               class="input text-center text-2xl tracking-[0.5em] font-mono py-3"
               placeholder="000000">

        <button type="submit" class="btn btn-primary w-full py-2.5">
            {{ __('auth.two_factor_verify') }}
        </button>
    </form>

    <details class="mt-6 group">
        <summary class="text-sm text-ink-400 hover:text-ink-200 cursor-pointer text-center">
            {{ __('auth.two_factor_recovery') }}
        </summary>

        <form method="POST" action="{{ route('2fa.recover') }}" class="mt-4 space-y-3">
            @csrf
            <input type="text" name="code" required class="input font-mono"
                   placeholder="xxxxxxxx">
            <button class="btn btn-secondary w-full">{{ __('common.send') }}</button>
        </form>
    </details>

    @if ($recovery ?? false)
        <form method="POST" action="{{ route('2fa.resend') }}" class="mt-3 text-center">
            @csrf
            <button class="text-sm text-brand-400 hover:text-brand-300">
                {{ __('auth.two_factor_resend') }}
            </button>
        </form>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button class="text-sm text-ink-500 hover:text-ink-300">
            {{ __('nav.cancel') }}
        </button>
    </form>
@endsection
