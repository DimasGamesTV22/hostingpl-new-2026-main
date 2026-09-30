@extends('layouts.public')

@section('title', __('landing.status_title') . ' — ' . setting('hosting.branding.name', 'GameDock'))

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12"
         x-data="{ auto: true }"
         @gd:refresh.window="if (auto) $refs.list.reload()">

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold">{{ __('landing.status_title') }}</h1>
                <p class="mt-2 text-ink-400">{{ __('landing.status_subtitle') }}</p>
            </div>

            <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-ink-400 cursor-pointer">
                    <input type="checkbox" x-model="auto" class="checkbox">
                    <span>{{ __('common.refresh') }}</span>
                </label>
                <button @click="$refs.list.reload()" class="btn btn-secondary btn-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Сводка -->
        <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach ([
                ['label' => __('landing.stats.servers'), 'value' => $stats['servers']],
                ['label' => __('landing.stats.players'), 'value' => $stats['players']],
                ['label' => __('landing.stats.nodes'), 'value' => $stats['nodes']],
                ['label' => __('landing.stats.uptime'), 'value' => $stats['uptime'] . '%'],
            ] as $item)
                <div class="stat">
                    <div class="stat-label">{{ $item['label'] }}</div>
                    <div class="stat-value">{{ number_format($item['value'], 0, ',', ' ') }}</div>
                </div>
            @endforeach
        </div>

        <!-- Фильтры -->
        <form method="GET" class="mt-6 flex flex-wrap gap-3">
            <input type="search" name="q" value="{{ $search }}" class="input flex-1 min-w-[200px]"
                   placeholder="{{ __('common.search') }}…">
            <select name="game" class="select w-auto">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($games as $g)
                    <option value="{{ $g->slug }}" @selected($game === $g->slug)>{{ $g->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-secondary">{{ __('common.filter') }}</button>
        </form>

        <div class="mt-6" x-ref="list">
            @include('partials.server-list', ['servers' => $servers])
        </div>
    </div>
@endsection
