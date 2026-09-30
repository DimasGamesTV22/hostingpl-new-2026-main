@extends('layouts.dashboard')

@section('title', __('servers.settings') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.servers.show', $server) }}?tab=settings" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ $server->name }}
        </a>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">{{ __('servers.settings') }}</h1>
            <span class="badge-{{ $server->statusColor() }}">{{ $server->statusLabel() }}</span>
        </div>
    </div>

    <div class="mb-6 flex flex-wrap gap-2">
        <a href="{{ route('panel.servers.show', $server) }}?tab=overview" class="btn btn-ghost btn-sm">{{ __('servers.info') }}</a>
        <a href="{{ route('panel.server.console', $server) }}" class="btn btn-ghost btn-sm">{{ __('servers.console') }}</a>
        <a href="{{ route('panel.server.files', $server) }}" class="btn btn-ghost btn-sm">{{ __('servers.files') }}</a>
        <a href="{{ route('panel.server.backups', $server) }}" class="btn btn-ghost btn-sm">{{ __('servers.backups') }}</a>
        <a href="{{ route('panel.server.plugins', $server) }}" class="btn btn-ghost btn-sm">{{ __('servers.plugins') }}</a>
        <a href="{{ route('panel.server.schedules', $server) }}" class="btn btn-ghost btn-sm">{{ __('servers.schedules') }}</a>
    </div>

    @include('panel.servers.tabs.settings', [
        'server' => $server,
        'configFiles' => $configFiles,
        'builds' => $builds,
        'ports' => $ports,
        'canReinstall' => $canReinstall,
        'permissions' => ['settings' => true],
    ])
@endsection
