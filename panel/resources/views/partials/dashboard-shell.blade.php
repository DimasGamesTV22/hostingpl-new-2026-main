@php
    $user = auth()->user();

    $userNav = [
        ['route' => 'panel.dashboard', 'icon' => 'home', 'label' => __('nav.dashboard')],
        ['route' => 'panel.servers.index', 'icon' => 'server', 'label' => __('nav.my_servers')],
        ['route' => 'panel.billing', 'icon' => 'wallet', 'label' => __('nav.billing')],
        ['route' => 'panel.store', 'icon' => 'cart', 'label' => __('nav.store')],
        ['route' => 'panel.promo', 'icon' => 'gift', 'label' => __('nav.promo')],
        ['route' => 'panel.referral', 'icon' => 'users', 'label' => __('nav.referral')],
        ['route' => 'panel.tickets.index', 'icon' => 'message-square', 'label' => __('nav.support')],
        ['route' => 'panel.profile', 'icon' => 'user', 'label' => __('nav.profile')],
    ];

    $adminNav = [
        ['route' => 'admin.dashboard', 'icon' => 'gauge', 'label' => __('nav.overview')],
        ['route' => 'admin.users', 'icon' => 'users', 'label' => __('nav.users')],
        ['route' => 'admin.nodes', 'icon' => 'server-cog', 'label' => __('nav.nodes')],
        ['route' => 'admin.games', 'icon' => 'gamepad', 'label' => __('nav.games_admin')],
        ['route' => 'admin.tariffs', 'icon' => 'tag', 'label' => __('nav.tariffs')],
        ['route' => 'admin.promo', 'icon' => 'gift', 'label' => __('nav.promo_codes')],
        ['route' => 'admin.referrals', 'icon' => 'share', 'label' => __('nav.referrals')],
        ['route' => 'admin.tickets', 'icon' => 'inbox', 'label' => __('nav.tickets')],
        ['route' => 'admin.reports', 'icon' => 'chart', 'label' => __('nav.reports')],
        ['route' => 'admin.settings', 'icon' => 'cog', 'label' => __('nav.settings')],
        ['route' => 'admin.audit', 'icon' => 'shield', 'label' => __('nav.audit')],
        ['route' => 'admin.ip_bans', 'icon' => 'ban', 'label' => __('nav.ip_bans')],
    ];
@endphp

<div x-data="{ sidebar: false }" class="min-h-screen">

    {{-- Мобильная шапка --}}
    <div class="lg:hidden sticky top-0 z-40 flex h-14 items-center justify-between border-b border-ink-800 bg-ink-900 px-4">
        <button @click="sidebar = !sidebar" class="btn btn-ghost btn-sm">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
            </svg>
        </button>
        <span class="font-semibold">{{ setting('hosting.branding.name', 'GameDock') }}</span>
        <a href="{{ route('panel.dashboard') }}" class="text-sm">{{ $user->initials }}</a>
    </div>

    {{-- Боковое меню --}}
    <aside :class="sidebar ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 left-0 z-50 w-64 border-r border-ink-800 bg-ink-900 transition-transform duration-200 lg:translate-x-0 flex flex-col">

        <div class="h-14 flex items-center gap-2 px-5 border-b border-ink-800 shrink-0">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-white text-sm">GD</span>
                <span>{{ setting('hosting.branding.name', 'GameDock') }}</span>
            </a>
            <button @click="sidebar = false" class="lg:hidden ml-auto btn btn-ghost btn-sm">✕</button>
        </div>

        @if (session('impersonator_id'))
            <div class="m-3 px-3 py-2 rounded-lg bg-amber-500/10 border border-amber-500/30 text-xs text-amber-200">
                {{ __('admin.impersonating_as', ['name' => session('impersonator_name')]) }}
                <a href="{{ route('admin.users') }}" class="block mt-1 underline">← {{ __('admin.stop_impersonating') }}</a>
            </div>
        @endif

        <nav class="flex-1 overflow-y-auto p-3 space-y-6 scrollbar-none">
            <div>
                <div class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-ink-500">
                    {{ __('nav.cabinet') }}
                </div>
                <div class="space-y-0.5">
                    @foreach ($userNav as $item)
                        <a href="{{ route($item['route']) }}"
                           class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition
                                  {{ request()->routeIs($item['route']) ? 'bg-ink-800 text-ink-100' : 'text-ink-300 hover:bg-ink-850 hover:text-ink-100' }}">
                            @include('partials.icon', ['name' => $item['icon'], 'class' => 'w-4 h-4'])
                            <span class="flex-1">{{ $item['label'] }}</span>
                            @if ($item['route'] === 'panel.tickets.index' && ($openTickets ?? 0) > 0)
                                <span class="badge-red">{{ $openTickets }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>

            @if ($user->isStaff())
                <div>
                    <div class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-ink-500">
                        {{ __('nav.administration') }}
                    </div>
                    <div class="space-y-0.5">
                        @foreach ($adminNav as $item)
                            <a href="{{ route($item['route']) }}"
                               class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition
                                      {{ request()->routeIs($item['route']) ? 'bg-ink-800 text-ink-100' : 'text-ink-300 hover:bg-ink-850 hover:text-ink-100' }}">
                                @include('partials.icon', ['name' => $item['icon'], 'class' => 'w-4 h-4'])
                                <span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </nav>

        {{-- Баланс и срок серверов --}}
        <div class="border-t border-ink-800 p-4 space-y-3 shrink-0">
            <div class="flex items-center justify-between text-sm">
                <span class="text-ink-400">{{ __('nav.balance') }}</span>
                <span class="font-semibold tabular-nums">{{ money($user->balance) }}</span>
            </div>
            <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-primary btn-sm w-full">
                {{ __('nav.top_up') }}
            </a>
        </div>
    </aside>

    <div x-show="sidebar" @click="sidebar = false" x-cloak class="fixed inset-0 bg-black/60 z-40 lg:hidden"></div>

    {{-- Контент --}}
    <div class="lg:pl-64">
        {{-- Верхняя панель --}}
        <header class="hidden lg:flex h-14 items-center justify-between gap-4 border-b border-ink-800 px-6 sticky top-0 z-30 bg-ink-950/90 backdrop-blur">
            <div class="flex items-center gap-2 text-sm text-ink-400">
                @yield('breadcrumb')
                <span>{{ $user->name }}</span>
            </div>

            <div class="flex items-center gap-2">
                @if (($unreadNotifications ?? 0) > 0)
                    <a href="{{ route('panel.notifications') }}" class="btn btn-ghost btn-sm relative">
                        @include('partials.icon', ['name' => 'bell', 'class' => 'w-4 h-4'])
                        <span class="absolute -top-0.5 -right-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                            {{ min(99, $unreadNotifications) }}
                        </span>
                    </a>
                @endif

                @if (setting('hosting.locale.switcher_in_header', true) && count($locales ?? []) > 1)
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="btn btn-ghost btn-sm">
                            {{ strtoupper(app()->getLocale()) }}
                        </button>
                        <div x-show="open" @click.outside="open = false" x-cloak
                             class="absolute right-0 mt-1 w-32 rounded-lg border border-ink-700 bg-ink-850 shadow-card py-1">
                            @foreach ($locales as $code)
                                <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                                   class="block px-3 py-1.5 text-sm hover:bg-ink-700">{{ $locales[$code] }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn btn-ghost btn-sm">{{ __('nav.logout') }}</button>
                </form>
            </div>
        </header>

        <main class="p-4 sm:p-6">
            @yield('content')
        </main>
    </div>
</div>
