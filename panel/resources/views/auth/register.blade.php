@extends('layouts.auth')

@section('title', __('auth.sign_up') . ' — ' . setting('hosting.branding.name', 'GameDock'))

@section('content')
    <h1 class="text-xl font-semibold text-center mb-1">{{ __('auth.create_account') }}</h1>

    @if (($trial['enabled'] ?? false) && ! empty($trial['days']))
        <p class="text-sm text-center text-emerald-400 mb-6">
            {{ __('auth.trial_badge', ['days' => $trial['days']]) }}
        </p>
    @else
        <p class="text-sm text-ink-400 text-center mb-6">{{ setting('hosting.branding.name') }}</p>
    @endif

    <form method="POST" action="{{ route('register.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="name" class="label">{{ __('auth.name') }}</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus
                   autocomplete="name" class="input" placeholder="Иван">
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="label">{{ __('auth.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required
                   autocomplete="email" class="input" placeholder="you@example.com">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="username" class="label">
                {{ __('auth.username') }}
                <span class="text-ink-500 font-normal">({{ __('common.optional') }})</span>
            </label>
            <input id="username" type="text" name="username" value="{{ old('username') }}"
                   autocomplete="username" class="input" placeholder="ivan">
            <p class="hint">{{ __('auth.username_hint') }}</p>
            @error('username') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="label">{{ __('auth.password') }}</label>
            <input id="password" type="password" name="password" required autocomplete="new-password" class="input">
            <p class="hint">{{ __('auth.password_requirements', ['min' => config('hosting.auth.password.min', 8)]) }}</p>
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="label">{{ __('auth.password_confirmation') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required
                   autocomplete="new-password" class="input">
        </div>

        @if (request('ref') || old('referral_code'))
            <div>
                <label for="referral_code" class="label">{{ __('auth.referral_code') }}</label>
                <input id="referral_code" type="text" name="referral_code"
                       value="{{ old('referral_code', request('ref')) }}" class="input font-mono">
                <p class="hint">{{ __('auth.referral_code_hint') }}</p>
            </div>
        @endif

        @if (setting_bool('hosting.auth.terms_acceptance', true))
            <label class="flex items-start gap-2 text-sm text-ink-300 cursor-pointer">
                <input type="checkbox" name="terms" value="1" class="checkbox mt-0.5" @checked(old('terms'))>
                <span>
                    {{ __('auth.terms') }}
                    <a href="{{ route('legal.terms') }}" target="_blank" class="link">{{ __('footer.terms') }}</a>
                    {{ __('auth.or') }}
                    <a href="{{ route('legal.privacy') }}" target="_blank" class="link">{{ __('footer.privacy') }}</a>
                </span>
            </label>
            @error('terms') <p class="error">{{ $message }}</p> @enderror
        @endif

        @if (($recaptcha['enabled'] ?? false) && filled($recaptcha['secret_key'] ?? null))
            <div class="cf-turnstile" data-sitekey="{{ $recaptcha['site_key'] }}"></div>
            @error('captcha') <p class="error">{{ $message }}</p> @enderror
        @endif

        <button type="submit" class="btn btn-primary w-full py-2.5">
            {{ __('auth.sign_up') }}
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-ink-400">
        {{ __('auth.have_account') }}
        <a href="{{ route('login') }}" class="text-brand-400 hover:text-brand-300 font-medium">
            {{ __('auth.sign_in') }}
        </a>
    </p>
@endsection
