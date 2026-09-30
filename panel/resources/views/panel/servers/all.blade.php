@extends('layouts.dashboard')

@section('title', __('servers.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold">{{ __('servers.title') }}</h1>
            <p class="mt-1 text-sm text-ink-400">{{ __('common.total') }}: {{ $servers->total() }}</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('panel.servers.index') }}" class="btn btn-ghost btn-sm">
                @include('partials.icon', ['name' => 'chevron', 'class' => 'w-4 h-4 rotate-180'])
                {{ __('servers.title') }}
            </a>
            <a href="{{ route('panel.servers.create') }}" class="btn btn-primary">
                @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
                {{ __('servers.create') }}
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">{{ __('nav.my_servers') }}</h2>
        </div>

        @if ($servers->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.name') }}</th>
                            <th>{{ __('servers.game') }}</th>
                            <th>{{ __('servers.node') }}</th>
                            <th>{{ __('common.status') }}</th>
                            <th>{{ __('common.players') }}</th>
                            <th>{{ __('servers.expires') }}</th>
                            <th>{{ __('common.created') }}</th>
                            <th class="w-24"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            <tr>
                                <td>
                                    <a href="{{ route('panel.servers.show', $server) }}"
                                       class="font-medium hover:text-brand-300">{{ $server->name }}</a>
                                    @if ($server->address)
                                        <div class="text-xs text-ink-500 font-mono">{{ $server->address }}</div>
                                    @endif
                                </td>
                                <td class="text-ink-400">{{ $server->game->name }}</td>
                                <td class="text-ink-400">{{ $server->node?->name ?? '—' }}</td>
                                <td><span class="badge-{{ $server->statusColor() }}">{{ $server->statusLabel() }}</span></td>
                                <td class="tabular-nums text-ink-400">
                                    {{ $server->isRunning() ? $server->players_online . ' / ' . $server->slots : '—' }}
                                </td>
                                <td @class(['tabular-nums', 'text-red-400' => $server->isExpired()])>
                                    {{ $server->expires_at?->format('d.m.Y') ?? '—' }}
                                </td>
                                <td class="text-xs text-ink-500">{{ $server->created_at->format('d.m.Y') }}</td>
                                <td>
                                    <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm">
                                        @include('partials.icon', ['name' => 'chevron', 'class' => 'w-4 h-4'])
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($servers->hasPages())
            <div class="px-5 py-4 border-t border-ink-800">{{ $servers->links() }}</div>
        @endif
    </div>
@endsection
