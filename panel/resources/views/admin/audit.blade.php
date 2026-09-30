@extends('layouts.dashboard')

@section('title', __('admin.audit') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('admin.audit') }}</h1>
        <p class="mt-1 text-sm text-ink-400">{{ $logs->total() }} записей</p>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.audit') }}" class="grid sm:grid-cols-3 lg:grid-cols-6 gap-3 items-end">
                <div>
                    <label class="label">Действие</label>
                    <select name="action" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label">Объект</label>
                    <select name="subject_type" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($subjects as $subject)
                            <option value="{{ $subject }}" @selected(($filters['subject_type'] ?? '') === $subject)>{{ $subject }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label">User ID</label>
                    <input type="number" name="user" value="{{ $filters['user'] ?? '' }}" class="input !py-1.5 !text-xs">
                </div>

                <div>
                    <label class="label">IP</label>
                    <input type="text" name="ip" value="{{ $filters['ip'] ?? '' }}" class="input !py-1.5 !text-xs" placeholder="1.2.3.">
                </div>

                <div>
                    <label class="label">{{ __('common.from') }}</label>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input !py-1.5 !text-xs">
                </div>

                <div>
                    <label class="label">{{ __('common.to') }}</label>
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input !py-1.5 !text-xs">
                </div>

                <div class="sm:col-span-3 lg:col-span-6 flex gap-2">
                    <button class="btn btn-primary btn-sm">{{ __('common.apply_filter') }}</button>
                    <a href="{{ route('admin.audit') }}" class="btn btn-ghost btn-sm">{{ __('common.reset_filter') }}</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        @if ($logs->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_results') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th class="w-40">{{ __('common.actions') }}</th>
                            <th>{{ __('common.name') }}</th>
                            <th class="w-40">Объект</th>
                            <th class="w-32">IP</th>
                            <th class="w-16"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="whitespace-nowrap text-ink-400">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
                                <td><span class="badge-{{ $log->color() }}">{{ $log->action }}</span></td>
                                <td>
                                    @if ($log->user)
                                        <a href="{{ route('admin.users.show', $log->user_id) }}" class="hover:text-brand-300">
                                            {{ $log->actor_name ?? $log->user->name }}
                                        </a>
                                    @else
                                        <span class="text-ink-400">{{ $log->actor_name ?? 'система' }}</span>
                                    @endif
                                </td>
                                <td class="text-xs text-ink-400">
                                    @if ($log->subject_type)
                                        {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-xs font-mono text-ink-500">{{ $log->ip ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('admin.audit.show', $log) }}" class="btn btn-ghost btn-sm">
                                        @include('partials.icon', ['name' => 'chevron', 'class' => 'w-4 h-4'])
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-ink-800">{{ $logs->links() }}</div>
        @endif
    </div>
@endsection
