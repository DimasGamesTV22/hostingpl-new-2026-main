@extends('layouts.dashboard')

@section('title', __('nav.audit') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.audit') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('admin.audit') }}
        </a>
        <h1 class="text-2xl font-bold">{{ $auditLog->action }}</h1>
        <p class="mt-1 text-sm text-ink-400">{{ $auditLog->created_at->format('d.m.Y H:i:s') }}</p>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.info') }}</h2></div>
                <div class="card-body space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-ink-400">Действие</span>
                        <span class="badge-{{ $auditLog->color() }}">{{ $auditLog->action }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">Описание</span>
                        <span class="text-right max-w-md">{{ $auditLog->description }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">Объект</span>
                        <span>
                            @if ($auditLog->subject_type)
                                {{ class_basename($auditLog->subject_type) }} #{{ $auditLog->subject_id }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">IP</span>
                        <span class="font-mono">{{ $auditLog->ip ?? '—' }}</span>
                    </div>
                </div>
            </div>

            @if ($auditLog->old_values)
                <div class="card">
                    <div class="card-header"><h2 class="card-title text-red-400">Было</h2></div>
                    <div class="card-body">
                        <pre class="code text-[11px]">{{ json_encode($auditLog->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>
            @endif

            @if ($auditLog->new_values)
                <div class="card">
                    <div class="card-header"><h2 class="card-title text-emerald-400">Стало</h2></div>
                    <div class="card-body">
                        <pre class="code text-[11px]">{{ json_encode($auditLog->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>
            @endif
        </div>

        <div class="card h-fit">
            <div class="card-header"><h2 class="card-title">{{ __('admin.users') }}</h2></div>
            <div class="card-body space-y-2 text-sm">
                @if ($auditLog->user)
                    <a href="{{ route('admin.users.show', $auditLog->user_id) }}"
                       class="flex items-center gap-2 hover:text-brand-300">
                        <span class="w-8 h-8 grid place-items-center rounded-full bg-ink-700 text-xs">
                            {{ $auditLog->user->initials }}
                        </span>
                        <span class="truncate">{{ $auditLog->user->name }}</span>
                    </a>
                @else
                    <div class="text-ink-400">{{ $auditLog->actor_name ?? 'система' }}</div>
                @endif

                @if ($auditLog->actor_role)
                    <div class="divider"></div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">Роль</span>
                        <span>{{ $auditLog->actor_role }}</span>
                    </div>
                @endif

                @if ($auditLog->user_agent)
                    <div class="divider"></div>
                    <p class="label">User-Agent</p>
                    <pre class="code text-[10px] break-all">{{ $auditLog->user_agent }}</pre>
                @endif
            </div>
        </div>
    </div>
@endsection
