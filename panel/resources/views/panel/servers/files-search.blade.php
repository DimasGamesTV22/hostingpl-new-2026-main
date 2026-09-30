@extends('layouts.dashboard')

@section('title', __('files.search_files') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.server.files', $server) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('files.title') }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('files.search_files') }}</h1>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('panel.server.files.search', $server) }}" class="flex flex-wrap gap-2">
                <div class="relative flex-1 min-w-[240px]">
                    <input type="search" name="q" value="{{ $query }}" class="input pl-9" autofocus
                           placeholder="{{ __('files.search_placeholder') }}">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-ink-500" fill="none"
                         stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                </div>

                <input type="text" name="path" value="{{ request('path', '.') }}" class="input w-40" placeholder=".">

                <button class="btn btn-primary">{{ __('common.search') }}</button>
                <a href="{{ route('panel.server.files', $server) }}" class="btn btn-ghost">{{ __('common.reset') }}</a>
            </form>
        </div>
    </div>

    @if ($error)
        <div class="card p-4 border-red-500/40 text-red-300 text-sm">{{ $error }}</div>
    @elseif ($query === '')
        <div class="card">
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('files.select_file') }}</div>
        </div>
    @elseif (empty($results))
        <div class="card">
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('files.no_results') }}</div>
        </div>
    @else
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('files.found', ['count' => count($results)]) }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('files.name') }}</th>
                            <th class="w-32">{{ __('common.type') }}</th>
                            <th class="w-28">{{ __('common.size') }}</th>
                            <th class="w-20"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($results as $item)
                            <tr>
                                <td class="font-mono text-sm">{{ $item['path'] ?? $item['name'] }}</td>
                                <td>
                                    <span class="badge-gray">{{ ($item['type'] ?? 'file') === 'dir' ? 'dir' : 'file' }}</span>
                                </td>
                                <td class="text-ink-400 tabular-nums">{{ bytes_human($item['size'] ?? 0) }}</td>
                                <td>
                                    <a class="btn btn-ghost btn-sm"
                                       href="{{ route('panel.server.files.read', [$server, 'path' => $item['path'] ?? $item['name']]) }}">
                                        @include('partials.icon', ['name' => 'chevron', 'class' => 'w-4 h-4'])
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
