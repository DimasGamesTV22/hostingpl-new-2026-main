@extends('layouts.dashboard')

@section('title', __('support.tickets') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('support.your_tickets') }}</h1>
            <p class="mt-1 text-sm text-ink-400">{{ $tickets->total() }} · непрочитанных: {{ $unread }}</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('panel.tickets.create') }}" class="btn btn-primary">
                @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
                {{ __('support.new_ticket') }}
            </a>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['' => __('common.all'), 'open' => __('support.status.open'), 'pending' => __('support.status.pending'), 'closed' => __('support.status.closed')] as $value => $label)
            <a href="{{ $value ? route('panel.tickets.index', ['status' => $value]) : route('panel.tickets.index') }}"
               @class(['px-3 py-1.5 rounded-lg text-xs border', 'bg-ink-800 border-ink-600 text-ink-100' => request('status') === $value || ($value === '' && ! request('status')), 'border-ink-700 text-ink-400 hover:text-ink-200' => request('status') !== $value && ! ($value === '' && ! request('status'))])>
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="card">
        @if ($tickets->isEmpty())
            <div class="px-5 py-16 text-center">
                <div class="mx-auto w-14 h-14 grid place-items-center rounded-xl bg-ink-800 text-ink-500 mb-4">
                    @include('partials.icon', ['name' => 'inbox', 'class' => 'w-7 h-7'])
                </div>
                <p class="text-sm font-medium">{{ __('support.no_tickets') }}</p>
                <a href="{{ route('panel.tickets.create') }}" class="btn btn-primary mt-6">
                    {{ __('support.new_ticket') }}
                </a>
            </div>
        @else
            <div class="divide-y divide-ink-800">
                @foreach ($tickets as $ticket)
                    <a href="{{ route('panel.tickets.show', $ticket) }}"
                       class="flex flex-wrap items-center gap-3 px-5 py-4 hover:bg-ink-800/30 transition">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs text-ink-500">#{{ $ticket->id }}</span>
                                <span class="font-medium">{{ $ticket->subject }}</span>
                                @if ($ticket->unread_by_user > 0)
                                    <span class="w-2 h-2 rounded-full bg-brand-500"></span>
                                @endif
                            </div>
                            <div class="text-xs text-ink-500 mt-0.5 flex items-center gap-2 flex-wrap">
                                @if ($ticket->server)
                                    <span>{{ $ticket->server->name }}</span>
                                @endif
                                @if ($ticket->department)
                                    <span>· {{ $ticket->department->name }}</span>
                                @endif
                                <span>· {{ $ticket->last_reply_at?->diffForHumans() ?? $ticket->created_at->diffForHumans() }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <span class="badge-{{ $ticket->priorityColor() }}">{{ $ticket->priorityLabel() }}</span>
                            <span class="badge-{{ $ticket->statusColor() }}">{{ $ticket->statusLabel() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="px-5 py-4 border-t border-ink-800">{{ $tickets->links() }}</div>
        @endif
    </div>
@endsection
