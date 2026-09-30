@extends('layouts.public')

@section('title', setting('hosting.branding.name', 'GameDock') . ' — ' . __('landing.hero.title'))

@section('content')
    @php $trial = setting_array('hosting.marketing.trial'); @endphp

    <!-- ── Hero ──────────────────────────────────────────────────── -->
    <section class="relative overflow-hidden border-b border-ink-800">
        <div class="absolute inset-0 bg-gradient-to-b from-brand-600/10 via-transparent to-transparent"></div>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20 lg:py-28 relative">
            <div class="max-w-3xl">
                @if (! empty($trial['enabled']) && ! empty($trial['days']))
                    <span class="badge-indigo mb-6">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ __('landing.hero.free_test', ['days' => $trial['days']]) }}
                    </span>
                @endif

                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight text-balance">
                    {{ __('landing.hero.title') }}
                </h1>

                <p class="mt-6 text-lg text-ink-300 leading-relaxed max-w-2xl">
                    {{ __('landing.hero.subtitle') }}
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('register') }}" class="btn btn-primary px-6 py-3 text-base">
                        {{ __('landing.hero.cta') }}
                    </a>
                    <a href="{{ route('tariffs') }}" class="btn btn-secondary px-6 py-3 text-base">
                        {{ __('landing.hero.cta_secondary') }}
                    </a>
                </div>
            </div>

            <!-- Статистика -->
            <dl class="mt-16 grid grid-cols-2 lg:grid-cols-4 gap-4 max-w-3xl">
                @foreach ([
                    ['label' => __('landing.stats.servers'), 'value' => $stats['servers'], 'suffix' => ''],
                    ['label' => __('landing.stats.players'), 'value' => $stats['players'], 'suffix' => ''],
                    ['label' => __('landing.stats.games'), 'value' => $stats['games'], 'suffix' => ''],
                    ['label' => __('landing.stats.uptime'), 'value' => $stats['uptime'], 'suffix' => '%'],
                ] as $stat)
                    <div class="card p-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">{{ $stat['label'] }}</dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ number_format($stat['value'], 0, ',', ' ') }}{{ $stat['suffix'] }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    <!-- ── Игры ──────────────────────────────────────────────────── -->
    @if ($games->isNotEmpty())
        <section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
            <div class="flex items-end justify-between gap-4 mb-8">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold">{{ __('landing.games_title') }}</h2>
                    <p class="mt-2 text-ink-400">{{ __('landing.games_subtitle') }}</p>
                </div>
                <a href="{{ route('games') }}" class="btn btn-ghost shrink-0">
                    {{ __('landing.all_games') }}
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($games as $game)
                    <a href="{{ route('games.show', $game) }}"
                       class="card p-5 transition hover:border-brand-500/40 hover:shadow-glow group">
                        <div class="flex items-start gap-3">
                            <img src="{{ game_image_url($game->icon, $game->family) }}" alt=""
                                 class="h-11 w-11 rounded-lg object-contain bg-ink-800 shrink-0"
                                 onerror="this.style.display='none'">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-ink-100 truncate group-hover:text-brand-300">{{ $game->name }}</h3>
                                <p class="mt-1 text-xs text-ink-400">{{ $game->short_description }}</p>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-1.5">
                            @if ($game->supports_plugins)
                                <span class="badge-gray">{{ __('landing.install_in_one_click') }}</span>
                            @endif
                            @if ($game->supports_rcon)
                                <span class="badge-gray">RCON</span>
                            @endif
                            @if ($game->uses_steamcmd)
                                <span class="badge-gray">SteamCMD</span>
                            @endif
                        </div>

                        <div class="mt-4 pt-3 border-t border-ink-800 text-xs text-ink-400 flex justify-between">
                            <span>{{ $game->slots_range }} {{ __('common.slots') }}</span>
                            @if ((float) $game->price_per_slot_month > 0)
                                <span class="text-ink-300">
                                    {{ __('landing.from_price', ['price' => money($game->price_per_slot_month)]) }}
                                </span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <!-- ── Тарифы ────────────────────────────────────────────────── -->
    @if ($tariffs->isNotEmpty())
        <section class="border-t border-ink-800 bg-ink-900/40">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
                <div class="text-center mb-10">
                    <h2 class="text-2xl sm:text-3xl font-bold">{{ __('landing.tariffs_title') }}</h2>
                    <p class="mt-2 text-ink-400">{{ __('landing.tariffs_subtitle') }}</p>
                </div>

                @include('partials.tariff-cards', ['tariffs' => $tariffs])
            </div>
        </section>
    @endif

    <!-- ── Возможности ────────────────────────────────────────────── -->
    <section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
        <h2 class="text-2xl sm:text-3xl font-bold text-center mb-10">{{ __('landing.features_title') }}</h2>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (['install', 'console', 'files', 'backup', 'monitoring', 'ports', 'secret_codes', 'subaccounts'] as $key)
                <div class="card p-5">
                    <div class="h-10 w-10 grid place-items-center rounded-lg bg-brand-500/10 text-brand-400 mb-3">
                        @include('partials.icon', ['name' => match ($key) {
                            'install' => 'download',
                            'console' => 'terminal',
                            'files' => 'folder',
                            'backup' => 'archive',
                            'monitoring' => 'chart',
                            'ports' => 'server',
                            'secret_codes' => 'gift',
                            default => 'users',
                        }, 'class' => 'w-5 h-5'])
                    </div>
                    <h3 class="font-semibold">{{ __('landing.features.' . $key . '.title') }}</h3>
                    <p class="mt-2 text-sm text-ink-400 leading-relaxed">{{ __('landing.features.' . $key . '.text') }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <!-- ── Как начать ─────────────────────────────────────────────── -->
    <section class="border-t border-ink-800 bg-ink-900/40">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
            <h2 class="text-2xl sm:text-3xl font-bold text-center mb-10">{{ __('landing.steps_title') }}</h2>

            <ol class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4 max-w-5xl mx-auto">
                @foreach (['landing.steps'] as $dummy)
                    @foreach ([0, 1, 2, 3] as $i)
                        <li class="card p-5 relative">
                            <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-600 text-white font-semibold mb-3">
                                {{ $i + 1 }}
                            </span>
                            <h3 class="font-semibold">{{ __("landing.steps.{$i}.title") }}</h3>
                            <p class="mt-2 text-sm text-ink-400">{{ __("landing.steps.{$i}.text") }}</p>
                        </li>
                    @endforeach
                @endforeach
            </ol>
        </div>
    </section>

    <!-- ── Публичный статус ───────────────────────────────────────── -->
    @if (! empty($servers))
        <section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
            <div class="flex items-end justify-between gap-4 mb-8">
                <h2 class="text-2xl sm:text-3xl font-bold">{{ __('landing.status_title') }}</h2>
                <a href="{{ route('status') }}" class="btn btn-ghost shrink-0">{{ __('landing.status_page') }}</a>
            </div>

            @include('partials.server-list', ['servers' => $servers, 'compact' => true])
        </section>
    @endif

    <!-- ── Новости ────────────────────────────────────────────────── -->
    @if ($announcements->isNotEmpty())
        <section class="border-t border-ink-800 bg-ink-900/40">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
                <h2 class="text-2xl sm:text-3xl font-bold mb-8">{{ __('nav.news') }}</h2>

                <div class="grid gap-5 md:grid-cols-3">
                    @foreach ($announcements as $news)
                        <a href="{{ route('news.show', $news) }}" class="card p-5 transition hover:border-brand-500/40">
                            @if ($news->is_pinned)
                                <span class="badge-yellow mb-2">{{ __('common.beta') }}</span>
                            @endif
                            <h3 class="font-semibold leading-snug">{{ $news->title }}</h3>
                            <p class="mt-2 text-sm text-ink-400 line-clamp-2">{{ $news->summary ?? \Illuminate\Support\Str::limit(strip_tags($news->body), 120) }}</p>
                            <div class="mt-3 text-xs text-ink-500">
                                {{ $news->published_at?->format('d.m.Y') }}
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <!-- ── CTA ───────────────────────────────────────────────────── -->
    <section class="mx-auto max-w-4xl px-4 py-16 text-center">
        <div class="card p-10 bg-gradient-to-br from-brand-600/10 to-transparent">
            <h2 class="text-2xl font-bold mb-3">{{ __('landing.callout.title') }}</h2>
            <p class="text-ink-400 mb-6">{{ __('landing.callout.text') }}</p>
            <a href="{{ route('register') }}" class="btn btn-primary px-6 py-3">
                {{ __('landing.hero.cta') }}
            </a>
        </div>
    </section>
@endsection
