@extends('layouts.auth')

@section('title', __('auth.forgot_password'))

@section('content')
    <h1 class="text-xl font-semibold text-center mb-1">{{ __('auth.forgot_password') }}</h1>
    <p class="text-sm text-ink-400 text-center mb-6">{{ __('auth.reset_link_sent') }}</p>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="label">{{ __('auth.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                   autocomplete="email" class="input">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-full py-2.5">{{ __('common.send') }}</button>
    </form>

    <p class="mt-6 text-center text-sm text-ink-400">
        <a href="{{ route('login') }}" class="text-brand-400 hover:text-brand-300">
            ← {{ __('auth.sign_in') }}
        </a>
    </p>
@endsection
