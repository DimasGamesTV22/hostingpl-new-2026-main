@extends('layouts.auth')

@section('title', __('auth.password'))

@section('content')
    <h1 class="text-xl font-semibold text-center mb-6">{{ __('auth.password') }}</h1>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="label">{{ __('auth.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required class="input">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="label">{{ __('auth.password') }}</label>
            <input id="password" type="password" name="password" required autofocus
                   autocomplete="new-password" class="input">
            <p class="hint">{{ __('auth.password_requirements', ['min' => config('hosting.auth.password.min', 8)]) }}</p>
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="label">{{ __('auth.password_confirmation') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required
                   autocomplete="new-password" class="input">
        </div>

        <button type="submit" class="btn btn-primary w-full py-2.5">{{ __('common.save') }}</button>
    </form>
@endsection
