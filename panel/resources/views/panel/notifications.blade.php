@extends('layouts.dashboard')

@section('title', __('notifications.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('notifications.title') }}</h1>
            <p class="mt-1 text-sm text-ink-400">Непрочитанных: {{ $unread }}</p>
        </div>

        @if ($unread > 0)
            <form method="POST" action="{{ route('panel.notifications.read_all') }}">
                @csrf
                <button class="btn btn-secondary btn-sm">{{ __('notifications.read_all') }}</button>
            </form>
        @endif
    </div>

    <div class="card">
        @if ($notifications->isEmpty())
            <div class="px-5 py-16 text-center">
                <div class="mx-auto w-14 h-14 grid place-items-center rounded-xl bg-ink-800 text-ink-500 mb-4">
                    @include('partials.icon', ['name' => 'bell', 'class' => 'w-7 h-7'])
                </div>
                <p class="text-sm text-ink-400">{{ __('notifications.empty') }}</p>
            </div>
        @else
            <div class="divide-y divide-ink-800">
                @foreach ($notifications as $notification)
                    <div @class([
                        'flex items-start gap-3 px-5 py-4 transition',
                        'bg-brand-500/5' => $notification->read_at === null,
                    ])>
                        <span @class([
                            'mt-0.5 w-8 h-8 shrink-0 grid place-items-center rounded-lg',
                            'bg-emerald-500/15 text-emerald-300' => $notification->color() === 'green',
                            'bg-amber-500/15 text-amber-300' => $notification->color() === 'yellow',
                            'bg-red-500/15 text-red-300' => $notification->color() === 'red',
                            'bg-sky-500/15 text-sky-300' => $notification->color() === 'blue',
                        ])>
                            @include('partials.icon', ['name' => 'bell', 'class' => 'w-4 h-4'])
                        </span>

                        <div class="min-w-0 flex-1">
                            <div @class(['text-sm font-medium', 'text-ink-300' => $notification->read_at])>
                                {{ $notification->title }}
                            </div>
                            @if ($notification->body)
                                <div class="text-xs text-ink-400 mt-0.5">{{ $notification->body }}</div>
                            @endif
                            <div class="text-xs text-ink-600 mt-1">{{ $notification->created_at->diffForHumans() }}</div>
                        </div>

                        @if ($notification->read_at === null)
                            <form method="POST" action="{{ route('panel.notifications.read', $notification) }}">
                                @csrf
                                <button class="btn btn-ghost btn-sm" title="{{ __('notifications.mark_read') }}">
                                    @include('partials.icon', ['name' => 'check', 'class' => 'w-4 h-4'])
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="px-5 py-4 border-t border-ink-800">{{ $notifications->links() }}</div>
        @endif
    </div>
@endsection
