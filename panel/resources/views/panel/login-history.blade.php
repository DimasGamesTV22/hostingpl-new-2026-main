@extends('layouts.dashboard')

@section('title', __('security.login_history') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.security') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('security.title') }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('security.login_history') }}</h1>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <!-- Сессии -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('security.sessions') }}</h2>
                <span class="text-xs text-ink-500">{{ $history->count() }}</span>
            </div>

            @if ($history->isEmpty())
                <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
            @else
                <div class="divide-y divide-ink-800">
                    @foreach ($history as $session)
                        <div class="px-5 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm">{{ $session->deviceLabel() }}</span>
                                <span @class(['badge-gray', 'badge-red' => $session->revoked_at])>
                                    {{ $session->revoked_at ? __('common.disabled') : __('common.active') }}
                                </span>
                            </div>
                            <div class="text-xs text-ink-500 mt-0.5 font-mono">
                                {{ $session->ip }} · {{ $session->last_activity_at?->format('d.m.Y H:i') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Попытки входа -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('security.failed_attempts') }}</h2>
                <span class="text-xs text-ink-500">{{ $attempts->count() }}</span>
            </div>

            @if ($attempts->isEmpty())
                <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('common.date') }}</th>
                                <th>IP</th>
                                <th>Email</th>
                                <th>{{ __('common.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($attempts as $attempt)
                                <tr>
                                    <td class="whitespace-nowrap text-xs text-ink-400">
                                        {{ $attempt->created_at }}
                                    </td>
                                    <td class="font-mono text-xs">{{ $attempt->ip }}</td>
                                    <td class="text-xs text-ink-400">{{ $attempt->email ?? '—' }}</td>
                                    <td>
                                        <span class="badge-{{ $attempt->successful ? 'green' : 'red' }}">
                                            {{ $attempt->successful ? 'OK' : 'FAIL' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
