@extends('layouts.dashboard')

@section('title', __('nav.tickets') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('nav.tickets') }}</h1>
        <div class="mt-2 flex flex-wrap gap-2 text-xs">
            <span class="badge-gray">открытых: {{ $counts['open'] }}</span>
            <span class="badge-blue">непрочитанных: {{ $counts['unread'] }}</span>
            <span class="badge-indigo">моих: {{ $counts['mine'] }}</span>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.tickets') }}" class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="label">{{ __('common.status') }}</label>
                    <select name="status" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach (['open', 'pending', 'answered', 'closed'] as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                                {{ __('support.status.' . $status) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label">{{ __('support.priority') }}</label>
                    <select name="priority" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach (['urgent', 'high', 'normal', 'low'] as $priority)
                            <option value="{{ $priority }}" @selected(($filters['priority'] ?? '') === $priority)>
                                {{ __('support.priority_labels.' . $priority) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label">{{ __('support.department') }}</label>
                    <select name="department" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((string) ($filters['department'] ?? '') === (string) $department->id)>
                                {{ $department->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <label class="flex items-center gap-2 text-sm pb-1.5">
                    <input type="checkbox" name="unread" value="1" class="checkbox" @checked($filters['unread'] ?? false)>
                    Только непрочитанные
                </label>

                <button class="btn btn-primary btn-sm">{{ __('common.apply_filter') }}</button>
                <a href="{{ route('admin.tickets') }}" class="btn btn-ghost btn-sm">{{ __('common.reset_filter') }}</a>
            </form>
        </div>
    </div>

    <div class="card">
        @if ($tickets->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_results') }}</div>
        @else
            <div class="divide-y divide-ink-800">
                @foreach ($tickets as $ticket)
                    <a href="{{ route('admin.tickets.show', $ticket) }}"
                       class="flex flex-wrap items-center gap-3 px-5 py-4 hover:bg-ink-800/30 transition">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs text-ink-500">#{{ $ticket->id }}</span>
                                <span class="font-medium">{{ $ticket->subject }}</span>
                                @if ($ticket->unread_by_staff > 0)
                                    <span class="w-2 h-2 rounded-full bg-brand-500"></span>
                                @endif
                            </div>
                            <div class="text-xs text-ink-500 mt-0.5 flex items-center gap-2 flex-wrap">
                                <span>{{ $ticket->user?->name ?? '—' }}</span>
                                @if ($ticket->server)
                                    <span>· {{ $ticket->server->name }}</span>
                                @endif
                                @if ($ticket->department)
                                    <span>· {{ $ticket->department->name }}</span>
                                @endif
                                <span>· {{ $ticket->last_reply_at?->diffForHumans() ?? $ticket->created_at->diffForHumans() }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0 text-xs">
                            @if ($ticket->assignee)
                                <span class="text-ink-400">{{ $ticket->assignee->name }}</span>
                            @endif
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
