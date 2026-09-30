@extends('layouts.dashboard')

@section('title', '#' . $ticket->id . ' ' . $ticket->subject)

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.tickets') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('nav.tickets') }}
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
                    @if ($ticket->unread_by_user > 0)
                        <span class="badge-blue">{{ $ticket->unread_by_user }} непрочитанных у клиента</span>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('admin.tickets.assign', $ticket) }}" class="flex gap-1">
                    @csrf
                    <select name="assigned_to" class="select !py-1 !text-xs w-40">
                        <option value="">— {{ __('common.none') }} —</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected($ticket->assigned_to === $member->id)>
                                {{ $member->name }} ({{ $member->role }})
                            </option>
                        @endforeach
                    </select>
                    <button class="btn btn-secondary btn-sm">{{ __('common.apply') }}</button>
                </form>

                <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}" class="flex gap-1">
                    @csrf
                    <select name="status" class="select !py-1 !text-xs w-32">
                        @foreach (['open', 'pending', 'answered', 'closed'] as $status)
                            <option value="{{ $status }}" @selected($ticket->status === $status)>
                                {{ __('support.status.' . $status) }}
                            </option>
                        @endforeach
                    </select>
                    <button class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-3">
            @foreach ($ticket->messages as $message)
                <div @class([
                    'card p-4',
                    'border-brand-500/30 bg-brand-500/5' => ! $message->is_staff && ! $message->is_internal_note,
                    'border-amber-500/30 bg-amber-500/5' => $message->is_internal_note,
                ])>
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span class="text-sm font-medium">{{ $message->author_name }}</span>
                        @if ($message->is_staff)
                            <span class="badge-indigo">{{ __('admin.title') }}</span>
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

        <div class="space-y-4">
            <!-- Клиент -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('admin.users') }}</h2></div>
                <div class="card-body space-y-2 text-sm">
                    @if ($ticket->user)
                        <div class="flex items-center gap-3">
                            <span class="w-9 h-9 grid place-items-center rounded-full bg-ink-700 text-xs shrink-0">
                                {{ $ticket->user->initials }}
                            </span>
                            <div class="min-w-0">
                                <a href="{{ route('admin.users.show', $ticket->user_id) }}"
                                   class="font-medium hover:text-brand-300 block truncate">{{ $ticket->user->name }}</a>
                                <div class="text-xs text-ink-500 truncate">{{ $ticket->user->email }}</div>
                            </div>
                        </div>
                        <div class="divider"></div>
                        <div class="flex justify-between">
                            <span class="text-ink-400">ID</span>
                            <span class="font-mono">{{ $ticket->user->id }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-ink-400">{{ __('nav.balance') }}</span>
                            <span>{{ money($ticket->user->balance) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-ink-400">{{ __('admin.servers') }}</span>
                            <span>{{ $ticket->user->servers()->count() }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-ink-400">Регистрация</span>
                            <span>{{ $ticket->user->created_at->format('d.m.Y') }}</span>
                        </div>
                    @else
                        <p class="text-ink-400">Пользователь удалён</p>
                    @endif

                    @if ($ticket->server)
                        <div class="divider"></div>
                        <a href="{{ route('panel.servers.show', $ticket->server_id) }}" class="btn btn-secondary btn-sm w-full">
                            {{ $ticket->server->name }}
                        </a>
                        <div class="text-xs text-ink-500 mt-2">
                            {{ $ticket->server->address ?? '—' }} ·
                            {{ $ticket->server->statusLabel() }}
                        </div>
                    @endif
                </div>
            </div>

            <!-- Ответ -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('support.reply') }}</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" class="space-y-3">
                        @csrf
                        <textarea name="body" rows="6" required class="input" maxlength="10000"
                                  placeholder="Ответ клиенту…">{{ old('body') }}</textarea>
                        @error('body') <p class="error">{{ $message }}</p> @enderror

                        <label class="flex items-start gap-2 text-sm text-ink-300">
                            <input type="checkbox" name="is_internal_note" value="1" class="checkbox mt-0.5">
                            <span>{{ __('support.internal_note') }}</span>
                        </label>

                        <button class="btn btn-primary btn-sm w-full">{{ __('common.send') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
