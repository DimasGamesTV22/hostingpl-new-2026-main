@extends('layouts.dashboard')

@section('title', __('servers.sub_accounts') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.servers.show', $server) }}?tab=sub" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ $server->name }}
        </a>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">{{ __('servers.sub_accounts') }}</h1>

            <div class="flex items-center gap-2">
                <a href="{{ route('panel.server.secret_codes', $server) }}" class="btn btn-ghost btn-sm">
                    {{ __('servers.secret_codes') }}
                </a>
                <a href="{{ route('panel.server.secret_codes.usages', $server) }}" class="btn btn-ghost btn-sm">
                    {{ __('secret_codes.usages') }}
                </a>
            </div>
        </div>
    </div>

    @if (! $featuresEnabled)
        <div class="mb-4 px-4 py-3 rounded-lg bg-ink-800 border border-ink-700 text-sm text-ink-300">
            {{ __('servers.errors.sub_accounts_disabled') }}
        </div>
    @endif

    @include('panel.servers.partials.sub-accounts', [
        'server' => $server,
        'accounts' => $accounts,
        'roles' => $roles,
        'permissions' => $permissions,
        'max' => $max,
    ])
@endsection
