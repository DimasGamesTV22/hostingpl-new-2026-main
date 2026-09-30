@extends('layouts.dashboard')

@section('title', __('secret_codes.usage_history') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.server.secret_codes', $server) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('secret_codes.title') }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('secret_codes.usage_history') }}</h1>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">{{ __('secret_codes.usages') }}</h2>
            <span class="text-xs text-ink-500">{{ $usages->total() }}</span>
        </div>

        @if ($usages->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th>{{ __('secret_codes.code') }}</th>
                            <th>{{ __('secret_codes.player') }}</th>
                            <th>{{ __('secret_codes.reward') }}</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($usages as $usage)
                            <tr>
                                <td class="whitespace-nowrap text-ink-400">
                                    {{ $usage->created_at->format('d.m.Y H:i') }}
                                    <span class="block text-xs text-ink-600">{{ $usage->created_at->diffForHumans() }}</span>
                                </td>
                                <td class="font-mono">{{ $usage->secretCode?->code ?? '—' }}</td>
                                <td class="text-ink-300">{{ $usage->player_name ?? $usage->player_id ?? '—' }}</td>
                                <td>
                                    <span class="badge-indigo">
                                        {{ __('secret_codes.rewards.' . ($usage->secretCode?->reward_type ?? 'money')) }}
                                    </span>
                                </td>
                                <td class="text-xs text-ink-500 font-mono">{{ $usage->ip ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-ink-800">{{ $usages->links() }}</div>
        @endif
    </div>
@endsection
