@extends('layouts.dashboard')

@section('title', __('admin.games') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('admin.games') }}</h1>
            <p class="mt-1 text-sm text-ink-400">{{ $games->total() }}</p>
        </div>

        <a href="{{ route('admin.games.create') }}" class="btn btn-primary">
            @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
            {{ __('common.create') }}
        </a>
    </div>

    <div class="mb-4 px-4 py-3 rounded-lg bg-ink-850 border border-ink-700 text-xs text-ink-400">
        {{ __('admin.games.add_hint') }}
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.games') }}" class="flex flex-wrap gap-3 items-end">
                <div class="relative flex-1 min-w-[220px]">
                    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9"
                           placeholder="{{ __('common.search') }}">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-ink-500" fill="none"
                         stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                </div>

                <div>
                    <label class="label">{{ __('games.family') }}</label>
                    <select name="family" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($families as $family)
                            <option value="{{ $family }}" @selected(($filters['family'] ?? '') === $family)>{{ $family }}</option>
                        @endforeach
                    </select>
                </div>

                <button class="btn btn-primary btn-sm">{{ __('common.apply_filter') }}</button>
                <a href="{{ route('admin.games') }}" class="btn btn-ghost btn-sm">{{ __('common.reset_filter') }}</a>
            </form>
        </div>
    </div>

    <div class="card">
        @if ($games->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_results') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.name') }}</th>
                            <th class="w-32">{{ __('games.family') }}</th>
                            <th class="w-28">{{ __('games.slots_range') }}</th>
                            <th class="w-32">{{ __('common.memory') }}</th>
                            <th class="w-20">{{ __('admin.servers') }}</th>
                            <th class="w-20">{{ __('games.plugins') }}</th>
                            <th class="w-24">{{ __('common.status') }}</th>
                            <th class="w-32"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($games as $game)
                            <tr @class(['opacity-60' => ! $game->is_active])>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <img src="{{ game_image_url($game->icon, $game->family) }}" alt=""
                                             class="h-6 w-6 rounded" onerror="this.style.display='none'">
                                        <div class="min-w-0">
                                            <a href="{{ route('admin.games.edit', $game) }}"
                                               class="font-medium hover:text-brand-300">{{ $game->name }}</a>
                                            <div class="text-xs text-ink-500">{{ $game->slug }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge-gray">{{ $game->family }}</span></td>
                                <td class="text-xs tabular-nums">{{ $game->slotsRange() }}</td>
                                <td class="text-xs tabular-nums">
                                    {{ mb_gb($game->default_memory_mb) }} / {{ mb_gb($game->default_disk_mb) }}
                                </td>
                                <td class="tabular-nums">{{ $game->servers_count }}</td>
                                <td class="tabular-nums">{{ $game->templates_count }}</td>
                                <td>
                                    <span class="badge-{{ $game->is_active ? 'green' : 'gray' }}">
                                        {{ $game->is_active ? __('common.active') : __('common.inactive') }}
                                    </span>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1 justify-end">
                                        <a href="{{ route('admin.games.templates', $game) }}" class="btn btn-ghost btn-sm"
                                           title="{{ __('games.install_templates') }}">
                                            @include('partials.icon', ['name' => 'gamepad', 'class' => 'w-4 h-4'])
                                        </a>

                                        <form method="POST" action="{{ route('admin.games.toggle', $game) }}">
                                            @csrf
                                            <button class="btn btn-ghost btn-sm" title="{{ __('common.update') }}">
                                                @include('partials.icon', ['name' => $game->is_active ? 'stop' : 'play', 'class' => 'w-4 h-4'])
                                            </button>
                                        </form>

                                        <a href="{{ route('admin.games.edit', $game) }}" class="btn btn-ghost btn-sm"
                                           title="{{ __('common.edit') }}">
                                            @include('partials.icon', ['name' => 'cog', 'class' => 'w-4 h-4'])
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-ink-800">{{ $games->links() }}</div>
        @endif
    </div>
@endsection
