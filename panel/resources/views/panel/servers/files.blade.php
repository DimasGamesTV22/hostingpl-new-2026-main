@extends('layouts.dashboard')

@section('title', __('servers.files') . ' — ' . $server->name)

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm mb-3">
                @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
                {{ $server->name }}
            </a>
            <h1 class="text-2xl font-bold">{{ __('servers.files') }}</h1>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('panel.server.files.search', $server) }}" class="btn btn-ghost btn-sm">
                @include('partials.icon', ['name' => 'search', 'class' => 'w-4 h-4'])
                {{ __('files.search_files') }}
            </a>
            <a href="{{ route('panel.servers.show', $server) }}?tab=files" class="btn btn-secondary btn-sm">
                {{ __('common.back') }}
            </a>
        </div>
    </div>

    @include('panel.servers.partials.files', [
        'server' => $server,
        'path' => $path,
        'entries' => $entries,
        'breadcrumbs' => $breadcrumbs,
        'error' => $error,
        'canWrite' => $canWrite,
        'usage' => $usage,
        'base' => route('panel.server.files', $server),
    ])
@endsection
