@extends('layouts.dashboard')

@section('title', __('servers.schedules') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ $server->name }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('servers.schedules') }}</h1>
    </div>

    @include('panel.servers.partials.schedules', [
        'server' => $server,
        'schedules' => $schedules,
        'jobTypes' => $jobTypes,
        'presets' => $presets,
    ])
@endsection
