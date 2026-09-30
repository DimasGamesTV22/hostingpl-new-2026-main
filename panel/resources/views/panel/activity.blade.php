@extends('layouts.dashboard')

@section('title', __('nav.activity') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('nav.activity') }}</h1>
        <p class="mt-1 text-sm text-ink-400">{{ __('servers.events') }} — все ваши серверы</p>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">{{ __('servers.events') }}</h2>
            <span class="text-xs text-ink-500">{{ $events->total() }}</span>
        </div>

        @if ($events->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        @else
            <div class="divide-y divide-ink-800">
                @foreach ($events as $event)
                    <div class="flex items-start gap-3 px-5 py-3">
                        <span class="badge-{{ $event->levelColor() }} shrink-0">{{ $event->type }}</span>

                        <div class="min-w-0 flex-1">
                            @if ($event->server)
                                <a href="{{ route('panel.servers.show', $event->server_id) }}?tab=events"
                                   class="text-sm font-medium hover:text-brand-300">{{ $event->server->name }}</a>
                                <span class="text-ink-600">·</span>
                            @endif
                            <span class="text-sm">{{ $event->title }}</span>
                            @if ($event->message)
                                <div class="text-xs text-ink-400 mt-0.5">{{ Str::limit($event->message, 180) }}</div>
                            @endif
                        </div>

                        <span class="text-xs text-ink-500 shrink-0">{{ $event->created_at->diffForHumans() }}</span>
                    </div>
                @endforeach
            </div>

            <div class="px-5 py-4 border-t border-ink-800">{{ $events->links() }}</div>
        @endif
    </div>
@endsection
