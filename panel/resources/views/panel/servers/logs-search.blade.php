@extends('layouts.dashboard')

@section('title', __('common.search') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.server.logs', $server) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('servers.logs') }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('common.search') }}</h1>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('panel.server.logs.search', $server) }}" class="flex flex-wrap gap-2">
                <input type="search" name="q" value="{{ $query }}" class="input flex-1 min-w-[240px]" autofocus
                       placeholder="текст для поиска">
                <input type="number" name="max" value="{{ request('max', 200) }}" class="input w-28" min="10" max="500">
                <button class="btn btn-primary">{{ __('common.search') }}</button>
                <a href="{{ route('panel.server.logs', $server) }}" class="btn btn-ghost">{{ __('common.reset') }}</a>
            </form>
        </div>
    </div>

    @if ($error)
        <div class="card p-4 border-red-500/40 text-red-300 text-sm">{{ $error }}</div>
    @elseif ($query === '')
        <div class="card">
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_results') }}</div>
        </div>
    @else
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('files.found', ['count' => $total]) }}</h2>
            </div>

            <div class="card-body">
                @if (empty($lines))
                    <p class="text-sm text-ink-400 text-center py-8">{{ __('files.no_results') }}</p>
                @else
                    <div class="console !h-[60vh]">
                        @foreach ($lines as $line)
                            <div class="{{ ($line['type'] ?? 'stdout') === 'stderr' ? 'console-line-error' : '' }}">
                                @if (isset($line['ts']))
                                    <span class="text-ink-600">[{{ date('H:i:s', (int) $line['ts']) }}]</span>
                                @endif
                                {{ $line['text'] ?? '' }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection
