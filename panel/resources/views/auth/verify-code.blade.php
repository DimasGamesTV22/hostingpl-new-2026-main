@extends('layouts.auth')

@section('title', __('auth.verify_email'))

@section('content')
    <div class="text-center mb-6">
        <div class="mx-auto w-12 h-12 grid place-items-center rounded-xl bg-amber-500/10 text-amber-400 mb-4">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
            </svg>
        </div>
        <h1 class="text-xl font-semibold">{{ __('auth.verify_email') }}</h1>
        <p class="mt-2 text-sm text-ink-400">{{ __('auth.verify_email_warning') }}</p>
    </div>

    @if ($user?->email)
        <p class="text-center text-sm text-ink-300 mb-6">
            {{ $user->email }}
        </p>
    @endif

    <form method="POST" action="{{ route('verification.code.check') }}" class="space-y-4">
        @csrf

        <div>
            <label for="code" class="label">{{ __('auth.verify_code') }}</label>
            <input id="code" type="text" name="code" inputmode="numeric" maxlength="6" required autofocus
                   class="input text-center text-2xl tracking-[0.5em] font-mono py-3" placeholder="000000">
            @error('code') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-full py-2.5">{{ __('auth.verify') }}</button>
    </form>

    <form method="POST" action="{{ route('verification.send') }}" class="mt-4 text-center">
        @csrf
        <button class="text-sm text-brand-400 hover:text-brand-300">
            {{ __('auth.send_verification') }}
        </button>
    </form>
@endsection
