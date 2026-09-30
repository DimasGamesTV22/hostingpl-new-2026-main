@extends('layouts.public')

@section('title', $game->name . ' — ' . setting('hosting.branding.name', 'GameDock'))

@section('description', $game->short_description ?: Str::limit(strip_tags((string) $game->description), 180))

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
        <!-- Шапка -->
        <div class="flex flex-wrap items-start gap-6">
            @if ($game->banner)
                <img src="{{ $game->banner }}" alt="{{ $game->name }}"
                     class="w-full sm:w-80 rounded-xl border border-ink-700 object-cover">
            @else
                <div class="w-24 h-24 grid place-items-center rounded-2xl bg-ink-850 border border-ink-700 shrink-0">
                    <img src="{{ game_image_url($game->icon, $game->family) }}" alt="{{ $game->name }}"
                         class="h-14 w-14 object-contain" onerror="this.style.display='none'">
                </div>
            @endif

            <div class="min-w-0 flex-1">
                <h1 class="text-3xl font-bold">{{ $game->name }}</h1>
                <p class="mt-1 text-ink-400">
                    {{ $game->short_description ?: Str::limit(strip_tags((string) $game->description), 200) }}
                </p>

                <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                    <span class="badge-gray">{{ $game->family }}</span>
                    <span class="badge-gray">{{ __('games.slots_range') }}: {{ $game->slotsRange() }}</span>
                    <span class="badge-gray">RAM от {{ mb_gb($game->min_memory_mb) }}</span>
                    <span class="badge-gray">Диск {{ mb_gb($game->default_disk_mb) }}</span>
                    @if ($game->supports_rcon)
                        <span class="badge-indigo">{{ __('games.supports.rcon') }}</span>
                    @endif
                    @if ($game->supports_query)
                        <span class="badge-indigo">{{ __('games.supports.query') }}</span>
                    @endif
                    @if ($game->supports_plugins)
                        <span class="badge-indigo">{{ __('games.supports.plugins') }}</span>
                    @endif
                    @if ($game->uses_steamcmd)
                        <span class="badge-indigo">{{ __('games.supports.steamcmd') }}</span>
                    @endif
                </div>

                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="{{ route('panel.servers.create', ['game' => $game->id]) }}" class="btn btn-primary">
                        {{ __('servers.create') }}
                    </a>
                    <a href="{{ route('tariffs') }}" class="btn btn-secondary">{{ __('nav.tariffs') }}</a>
                </div>
            </div>
        </div>

        <!-- Описание -->
        @if ($game->description)
            <div class="mt-10 card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('common.info') }}</h2>
                </div>
                <div class="card-body prose-invert max-w-none text-sm text-ink-300 whitespace-pre-line">
                    {!! nl2br(e($game->description)) !!}
                </div>
            </div>
        @endif

        <!-- Серверы этой игры -->
        <div class="mt-10">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold">{{ __('nav.status') }}</h2>
                <a href="{{ route('status', ['game' => $game->slug]) }}" class="text-sm text-brand-400 hover:text-brand-300">
                    {{ __('common.show_all') }}
                </a>
            </div>
            @include('partials.server-list', ['servers' => $servers, 'compact' => true])
        </div>

        <!-- Шаблоны установки -->
        @if ($game->relationLoaded('templates') && $game->templates->isNotEmpty())
            <div class="mt-10">
                <h2 class="text-xl font-semibold mb-4">{{ __('games.install_templates') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($game->templates as $template)
                        <div class="card p-4">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium">{{ $template->name }}</span>
                                <span class="badge-gray">{{ __('games.template_types.' . $template->type) }}</span>
                            </div>
                            @if ($template->description)
                                <p class="mt-2 text-xs text-ink-400 line-clamp-3">{{ $template->description }}</p>
                            @endif
                            <div class="mt-2 text-[11px] text-ink-500">
                                {{ $template->version ? 'v'.$template->version : '' }}
                                @if ($template->game_version) · {{ $template->game_version }} @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Тарифы -->
        <div class="mt-10">
            <h2 class="text-xl font-semibold mb-4">{{ __('nav.tariffs') }}</h2>
            @include('partials.tariff-cards', ['tariffs' => $tariffs])
        </div>
    </div>
@endsection
