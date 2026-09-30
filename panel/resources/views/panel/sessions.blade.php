@extends('layouts.dashboard')

@section('title', __('security.sessions') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.security') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('security.title') }}
        </a>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">{{ __('security.sessions') }}</h1>
                <p class="mt-1 text-sm text-ink-400">{{ __('security.sessions_hint') }}</p>
            </div>

            @if ($sessions->count() > 1)
                <form method="POST" action="{{ route('panel.security.sessions.revoke_all') }}"
                      onsubmit="return confirm('{{ __('common.confirm') }}')">
                    @csrf
                    <button class="btn btn-danger btn-sm">{{ __('security.revoke_all') }}</button>
                </form>
            @endif
        </div>
    </div>

    <div class="card">
        @if ($sessions->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        @else
            <div class="divide-y divide-ink-800">
                @foreach ($sessions as $session)
                    <div class="flex flex-wrap items-center gap-4 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium">{{ $session->deviceLabel() }}</span>
                                @if ($session->isCurrent())
                                    <span class="badge-green">{{ __('security.current_device') }}</span>
                                @endif
                                @if ($session->remember)
                                    <span class="badge-gray">remember</span>
                                @endif
                            </div>
                            <div class="text-xs text-ink-400 mt-1 flex items-center gap-3 flex-wrap">
                                <span class="font-mono">{{ $session->ip }}</span>
                                @if ($session->location)
                                    <span>· {{ $session->location }}</span>
                                @endif
                                <span>· {{ __('security.last_activity') }}: {{ $session->lastSeenHuman() }}</span>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('panel.security.sessions.destroy', $session) }}"
                              onsubmit="return confirm('{{ __('common.confirm') }}')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-ghost btn-sm text-red-400">{{ __('security.revoke') }}</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
