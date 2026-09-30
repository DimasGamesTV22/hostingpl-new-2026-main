<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'GameDock')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-950 flex items-center justify-center p-4 relative">
    <div class="absolute inset-0 bg-gradient-to-br from-brand-600/10 via-transparent to-ink-950"></div>

    <div class="relative w-full max-w-md">
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="inline-flex items-center gap-2 text-xl font-semibold">
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-brand-600 text-white">GD</span>
                {{ setting('hosting.branding.name', 'GameDock') }}
            </a>
        </div>

        <div class="card p-8">
            @yield('content')
        </div>

        <p class="mt-6 text-center text-xs text-ink-500">
            <a href="{{ route('home') }}" class="hover:text-ink-300">← {{ __('nav.home') }}</a>
        </p>
    </div>

    @include('partials.flash')
</body>
</html>
