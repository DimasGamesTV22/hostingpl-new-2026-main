@extends('layouts.auth')

@section('title', __('auth.login') . ' — ' . setting('hosting.branding.name', 'GameDock'))

@section('content')
    <h1 class="text-xl font-semibold text-center mb-1">{{ __('auth.login') }}</h1>
    <p class="text-sm text-ink-400 text-center mb-6">{{ __('auth.login_subtitle') }}</p>

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="label">{{ __('auth.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                   autocomplete="email" class="input" placeholder="you@example.com">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="label">{{ __('auth.password') }}</label>
                <a href="{{ route('password.request') }}" class="text-xs text-brand-400 hover:text-brand-300">
                    {{ __('auth.forgot_password') }}
                </a>
            </div>
            <input id="password" type="password" name="password" required autocomplete="current-password" class="input">
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-ink-300 cursor-pointer">
            <input type="checkbox" name="remember" value="1" class="checkbox" @checked(old('remember'))>
            {{ __('auth.remember_me') }}
        </label>

        <button type="submit" class="btn btn-primary w-full py-2.5">
            {{ __('auth.sign_in') }}
        </button>
    </form>

    @php
        $oauth = array_filter([
            'google' => config('services.google.client_id'),
            'vk' => config('services.vk.client_id'),
        ]);
    @endphp

    @if ($oauth)
        <div class="mt-6">
            <div class="flex items-center gap-3 text-xs text-ink-500">
                <span class="h-px flex-1 bg-ink-700"></span>
                <span>{{ __('auth.or_continue_with') }}</span>
                <span class="h-px flex-1 bg-ink-700"></span>
            </div>

            <div class="mt-4 space-y-2">
                @if ($oauth['google'] ?? false)
                    <a href="{{ route('oauth.google') }}" class="btn btn-secondary w-full">
                        <svg class="w-4 h-4" viewBox="0 0 24 24"><path fill="#EA4335" d="M12 10.2v3.9h5.5c-.24 1.4-1.7 4.1-5.5 4.1a6.2 6.2 0 010-12.4c1.8 0 3 .76 3.7 1.4l2.5-2.4A9.5 9.5 0 0012 2a10 10 0 100 20c5.8 0 9.6-4.2 9.6-10.2 0-.7-.1-1.2-.1-1.6H12z"/></svg>
                        Google
                    </a>
                @endif

                @if ($oauth['vk'] ?? false)
                    <a href="{{ route('oauth.vk') }}" class="btn btn-secondary w-full">
                        <svg class="w-4 h-4 text-[#0077FF]" viewBox="0 0 24 24" fill="currentColor"><path d="M13.2 17.5c-5.3 0-8.5-3.7-8.6-9.8h2.8c.1 4.5 2 6.4 4.1 6.7V7.7h2.6v3.9c2-.2 4.1-2 4.8-3.9h2.6c-.5 2.4-2.2 4.4-3.5 5.2 1.3.6 3.4 2.4 4.2 5.5h-2.9c-.6-2-2.2-3.6-4.2-3.8v3.8h-.5z"/></svg>
                        VK
                    </a>
                @endif
            </div>
        </div>
    @endif

    @if ($registrationEnabled ?? false)
        <p class="mt-6 text-center text-sm text-ink-400">
            {{ __('auth.no_account') }}
            <a href="{{ route('register') }}" class="text-brand-400 hover:text-brand-300 font-medium">
                {{ __('auth.sign_up') }}
            </a>
        </p>
    @endif
@endsection
