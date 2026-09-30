@extends('layouts.dashboard')

@section('title', __('servers.backups') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ $server->name }}
        </a>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">{{ __('servers.backups') }}</h1>
                <p class="mt-1 text-sm text-ink-400">
                    {{ __('backups.quota', ['used' => $snapshots->total(), 'quota' => $quota]) }}
                    · {{ __('backups.schedules.' . $schedule) }}
                </p>
            </div>

            <form method="POST" action="{{ route('panel.server.backups.store', $server) }}"
                  onsubmit="return confirm('{{ __('common.confirm') }}')">
                @csrf
                <button class="btn btn-primary">
                    @include('partials.icon', ['name' => 'archive', 'class' => 'w-4 h-4'])
                    {{ __('backups.create') }}
                </button>
            </form>
        </div>
    </div>

    @include('panel.servers.partials.backups', [
        'server' => $server,
        'snapshots' => $snapshots,
        'quota' => $quota,
        'canRestore' => $canRestore,
    ])

    @if ($canRestore)
        <div class="mt-4 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-200 text-sm">
            {{ __('backups.restore_warning') }}
        </div>
    @endif
@endsection
