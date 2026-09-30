@php
    $localeOptions = setting_array('hosting.locale.available', ['ru' => 'Русский', 'en' => 'English']);
    $brand = setting('hosting.branding.name', 'GameDock');
    $user = auth()->user();
@endphp

<header class="sticky top-0 z-50 border-b border-ink-800 bg-ink-950/90 backdrop-blur">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between gap-4">
            <div class="flex items-center gap-6">
                <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold text-lg">
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-white">GD</span>
                    <span class="hidden sm:inline">{{ $brand }}</span>
                </a>

                <nav class="hidden items-center gap-1 md:flex">
                    <a href="{{ route('games') }}" class="btn btn-ghost btn-sm">{{ __('nav.games') }}</a>
                    <a href="{{ route('tariffs') }}" class="btn btn-ghost btn-sm">{{ __('nav.tariffs') }}</a>
                    <a href="{{ route('status') }}" class="btn btn-ghost btn-sm">{{ __('nav.status') }}</a>
                    <a href="{{ route('faq') }}" class="btn btn-ghost btn-sm">{{ __('nav.faq') }}</a>
                </nav>
            </div>

            <div class="flex items-center gap-2">
                @if (setting('hosting.locale.switcher_in_header', true) && count($localeOptions) > 1)
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="btn btn-ghost btn-sm" :class="open && 'bg-ink-800'">
                            {{ strtoupper(app()->getLocale()) }}
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div x-show="open" @click.outside="open = false" x-cloak
                             class="absolute right-0 mt-1 w-36 rounded-lg border border-ink-700 bg-ink-850 shadow-card py-1">
                            @foreach ($localeOptions as $code => $label)
                                <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                                   class="block px-3 py-1.5 text-sm hover:bg-ink-700 {{ app()->getLocale() === $code ? 'text-brand-300' : 'text-ink-200' }}">
                                    {{ $label }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @auth
                    <a href="{{ route('panel.dashboard') }}" class="btn btn-ghost btn-sm hidden sm:inline-flex">
                        {{ __('nav.cabinet') }}
                    </a>
                    @if (auth()->user()->isStaff())
                        <a href="{{ route('admin.dashboard') }}" class="btn btn-ghost btn-sm">{{ __('nav.admin') }}</a>
                    @endif
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-ink-800">
                            <span class="grid h-7 w-7 place-items-center rounded-full bg-ink-700 text-xs font-medium">
                                {{ $user->initials }}
                            </span>
                        </button>
                        <div x-show="open" @click.outside="open = false" x-cloak
                             class="absolute right-0 mt-1 w-56 rounded-lg border border-ink-700 bg-ink-850 shadow-card py-1">
                            <div class="px-3 py-2 border-b border-ink-700">
                                <div class="text-sm font-medium truncate">{{ $user->name }}</div>
                                <div class="text-xs text-ink-400 truncate">{{ $user->email }}</div>
                            </div>
                            <a href="{{ route('panel.dashboard') }}" class="block px-3 py-2 text-sm hover:bg-ink-700">{{ __('nav.dashboard') }}</a>
                            <a href="{{ route('panel.servers.index') }}" class="block px-3 py-2 text-sm hover:bg-ink-700">{{ __('nav.my_servers') }}</a>
                            <a href="{{ route('panel.billing') }}" class="block px-3 py-2 text-sm hover:bg-ink-700">{{ __('nav.billing') }}</a>
                            <a href="{{ route('panel.profile') }}" class="block px-3 py-2 text-sm hover:bg-ink-700">{{ __('nav.profile') }}</a>
                            <form method="POST" action="{{ route('logout') }}" class="border-t border-ink-700 mt-1 pt-1">
                                @csrf
                                <button class="block w-full text-left px-3 py-2 text-sm text-red-400 hover:bg-ink-700">
                                    {{ __('nav.logout') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">{{ __('nav.login') }}</a>
                    @if (setting('hosting.auth.registration_enabled', true))
                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm">{{ __('nav.register') }}</a>
                    @endif
                @endauth
            </div>
        </div>
    </div>
</header>
