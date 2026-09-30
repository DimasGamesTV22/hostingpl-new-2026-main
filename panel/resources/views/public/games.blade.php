@extends('layouts.public')

@section('title', __('landing.games_title') . ' — ' . setting('hosting.branding.name', 'GameDock'))
@section('description', __('landing.games_subtitle'))

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
        <h1 class="text-3xl font-bold">{{ __('landing.games_title') }}</h1>
        <p class="mt-2 text-ink-400">{{ __('landing.games_subtitle') }}</p>

        <!-- Фильтры -->
        <form method="GET" class="mt-8 flex flex-wrap gap-3">
            <div class="relative flex-1 min-w-[240px]">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-ink-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                </svg>
                <input type="search" name="q" value="{{ $search }}" class="input pl-9"
                       placeholder="{{ __('common.search') }}…">
            </div>

            <select name="family" class="select w-auto" onchange="this.form.submit()">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($families as $f)
                    <option value="{{ $f }}" @selected($family === $f)>{{ $f }}</option>
                @endforeach
            </select>

            <button type="submit" class="btn btn-secondary">{{ __('common.filter') }}</button>

            @if ($search || $family)
                <a href="{{ route('games') }}" class="btn btn-ghost">{{ __('common.reset_filter') }}</a>
            @endif
        </form>

        <!-- Список -->
        @if ($games->isEmpty())
            <div class="card p-10 mt-8 text-center text-ink-400">{{ __('common.no_results') }}</div>
        @else
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($games as $game)
                    <a href="{{ route('games.show', $game) }}"
                       class="card p-5 transition hover:border-brand-500/40 hover:shadow-glow group">
                        <div class="flex items-start gap-3">
                            <img src="{{ game_image_url($game->icon, $game->family) }}" alt=""
                                 class="h-12 w-12 rounded-lg object-contain bg-ink-800 shrink-0"
                                 onerror="this.style.display='none'">
                            <div class="min-w-0">
                                <h2 class="font-semibold text-ink-100 group-hover:text-brand-300">{{ $game->name }}</h2>
                                <p class="mt-1 text-sm text-ink-400 line-clamp-2">{{ $game->short_description }}</p>
                            </div>
                        </div>

                        @if ($game->tags)
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach (array_slice($game->tags, 0, 4) as $tag)
                                    <span class="badge-gray">{{ $tag }}</span>
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-4 pt-3 border-t border-ink-800 flex items-center justify-between text-sm">
                            <span class="text-ink-400">{{ $game->slots_range }} {{ __('common.slots') }}</span>
                            @if ((float) $game->price_per_slot_month > 0)
                                <span class="text-brand-400 font-medium">
                                    {{ __('landing.from_price', ['price' => money($game->price_per_slot_month)]) }}
                                </span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection
