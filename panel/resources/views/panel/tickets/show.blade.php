@extends('layouts.dashboard')

@section('title', '#' . $ticket->id . ' ' . $ticket->subject)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.tickets.index') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('support.tickets') }}
        </a>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-sm text-ink-500">#{{ $ticket->id }}</span>
                    <h1 class="text-xl font-bold">{{ $ticket->subject }}</h1>
                </div>
                <div class="mt-2 flex items-center gap-2 flex-wrap text-xs">
                    <span class="badge-{{ $ticket->statusColor() }}">{{ $ticket->statusLabel() }}</span>
                    <span class="badge-{{ $ticket->priorityColor() }}">{{ $ticket->priorityLabel() }}</span>
                    @if ($ticket->department)
                        <span class="badge-gray">{{ $ticket->department->name }}</span>
                    @endif
                    @if ($ticket->assignee)
                        <span class="text-ink-400">Ответственный: {{ $ticket->assignee->name }}</span>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-2">
                @if ($ticket->isClosed())
                    <form method="POST" action="{{ route('panel.tickets.reopen', $ticket) }}">
                        @csrf
                        <button class="btn btn-secondary btn-sm">{{ __('support.reopen') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('panel.tickets.close', $ticket) }}"
                          onsubmit="return confirm('{{ __('common.confirm') }}')">
                        @csrf
                        <button class="btn btn-secondary btn-sm">{{ __('support.close') }}</button>
                    </form>
                @endif

                @if ($ticket->server)
                    <a href="{{ route('panel.servers.show', $ticket->server_id) }}" class="btn btn-ghost btn-sm">
                        {{ $ticket->server->name }}
                    </a>
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <!-- Переписка -->
            <div class="space-y-3">
                @foreach ($ticket->messages as $message)
                    <div @class([
                        'card p-4',
                        'border-brand-500/30 bg-brand-500/5' => $message->user_id === auth()->id(),
                    ])>
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-sm font-medium">
                                {{ $message->user?->name ?? __('support.title') }}
                            </span>
                            @if ($message->user_id === auth()->id())
                                <span class="badge-indigo">{{ __('common.me') }}</span>
                            @endif
                            @if ($message->is_internal_note)
                                <span class="badge-yellow">{{ __('support.internal_note') }}</span>
                            @endif
                            <span class="text-xs text-ink-500 ml-auto">{{ $message->created_at->format('d.m.Y H:i') }}</span>
                        </div>

                        <div class="text-sm text-ink-200 whitespace-pre-wrap break-words">{{ $message->body }}</div>
                    </div>
                @endforeach
            </div>

            <!-- Ответ -->
            @if ($canReply)
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="{{ route('panel.tickets.reply', $ticket) }}" class="space-y-3">
                            @csrf
                            <textarea name="body" rows="5" required class="input" maxlength="10000"
                                      placeholder="{{ __('support.reply') }}…">{{ old('body') }}</textarea>
                            @error('body') <p class="error">{{ $message }}</p> @enderror

                            <div class="flex items-center gap-2">
                                <button class="btn btn-primary btn-sm">{{ __('common.send') }}</button>
                                <span class="text-xs text-ink-500">{{ __('support.reply') }} — Ctrl+Enter</span>
                            </div>
                        </form>
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="px-5 py-4 text-sm text-ink-400">
                        Тикет закрыт.
                        <a href="{{ route('panel.tickets.reopen', $ticket) }}" class="link ml-1">
                            {{ __('support.reopen') }}
                        </a>
                    </div>
                </div>
            @endif
        </div>

        <!-- Информация -->
        <div class="space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.info') }}</h2></div>
                <div class="card-body space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('common.created') }}</span>
                        <span>{{ $ticket->created_at->format('d.m.Y H:i') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('common.updated') }}</span>
                        <span>{{ $ticket->last_reply_at?->format('d.m.Y H:i') ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('support.first_response') }}</span>
                        <span>{{ $ticket->responseTimeHuman() ?? '—' }}</span>
                    </div>
                    @if ($ticket->server)
                        <div class="flex justify-between">
                            <span class="text-ink-400">{{ __('support.server') }}</span>
                            <a href="{{ route('panel.servers.show', $ticket->server_id) }}" class="text-brand-400 hover:text-brand-300">
                                {{ $ticket->server->name }}
                            </a>
                        </div>
                    @endif
                    @if ($ticket->closed_at)
                        <div class="flex justify-between">
                            <span class="text-ink-400">{{ __('support.close') }}</span>
                            <span>{{ $ticket->closed_at->format('d.m.Y H:i') }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
