<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#08090c">

    <title>@yield('title', setting('hosting.branding.name', 'GameDock'))</title>

    <meta name="description" content="@yield('description', setting('hosting.branding.name').' — панель управления игровыми серверами')">

    @if (setting('hosting.locale.switcher_in_header', true))
        @foreach (setting_array('hosting.locale.available', ['ru', 'en']) as $code => $label)
            <link rel="alternate" hreflang="{{ $code }}" href="{{ url()->current() }}?lang={{ $code }}">
        @endforeach
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')

    <style>
        :root {
            --gd-primary: {{ setting('hosting.branding.accent_colors.primary', '#6366f1') }};
            --gd-secondary: {{ setting('hosting.branding.accent_colors.secondary', '#0ea5e9') }};
        }
    </style>
</head>
<body class="min-h-screen bg-ink-950 text-ink-100">

@include('partials.flash')

@yield('body')

@stack('scripts')

</body>
</html>
