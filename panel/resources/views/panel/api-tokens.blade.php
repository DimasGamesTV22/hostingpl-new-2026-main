@extends('layouts.dashboard')

@section('title', __('profile.api_tokens') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.profile') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('profile.title') }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('profile.api_tokens') }}</h1>
        <p class="mt-1 text-sm text-ink-400">{{ __('profile.api_tokens_hint') }}</p>
    </div>

    @if (session('token_plain'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30">
            <p class="text-sm text-emerald-200 mb-2">{{ __('profile.copy_now') }}</p>
            <div class="flex gap-2" x-data="{ copied: false }">
                <code class="flex-1 font-mono text-xs bg-ink-900 border border-ink-700 rounded px-3 py-2 break-all">
                    {{ session('token_plain') }}
                </code>
                <button type="button" class="btn btn-success btn-sm shrink-0"
                        @click="navigator.clipboard.writeText(@js(session('token_plain'))); copied = true; setTimeout(() => copied = false, 1500)"
                        x-text="copied ? '{{ __('common.copied') }}' : '{{ __('common.copy') }}'">
                </button>
            </div>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('common.add') }}</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('panel.profile.api_tokens.create') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="label">{{ __('profile.token_name') }}</label>
                        <input type="text" name="name" required class="input" maxlength="60" placeholder="CI/CD">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('profile.token_expires') }}</label>
                        <select name="expires_in" class="select">
                            <option value="0">{{ __('common.never') }}</option>
                            <option value="7">7 дней</option>
                            <option value="30">30 дней</option>
                            <option value="90">90 дней</option>
                            <option value="365">365 дней</option>
                        </select>
                    </div>

                    <div>
                        <label class="label">Права</label>
                        <div class="space-y-1">
                            @foreach (['*', 'servers:read', 'servers:write', 'servers:control', 'billing:read', 'profile:read'] as $ability)
                                <label class="flex items-center gap-2 text-sm text-ink-300">
                                    <input type="checkbox" name="abilities[]" value="{{ $ability }}" class="checkbox"
                                           @checked($ability === '*')>
                                    <code class="text-xs">{{ $ability }}</code>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="label">{{ __('profile.ip_whitelist') }}</label>
                        <input type="text" name="ip_whitelist" class="input" maxlength="500" placeholder="1.2.3.4, 5.6.7.0/24">
                        <p class="hint">{{ __('profile.ip_whitelist_hint') }}</p>
                    </div>

                    <button class="btn btn-primary w-full">{{ __('common.create') }}</button>
                </form>
            </div>
        </div>

        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">{{ __('profile.api_tokens') }}</h2>
                <span class="text-xs text-ink-500">{{ $tokens->count() }}</span>
            </div>

            @if ($tokens->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('common.name') }}</th>
                                <th>Права</th>
                                <th class="w-40">{{ __('profile.last_used') }}</th>
                                <th class="w-32">{{ __('profile.token_expires') }}</th>
                                <th class="w-16"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tokens as $token)
                                <tr @class(['opacity-50' => ! $token->isActive()])>
                                    <td>
                                        <div class="font-medium">{{ $token->name }}</div>
                                        <code class="text-xs text-ink-500">{{ $token->token_prefix }}…</code>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ((array) $token->abilities as $ability)
                                                <span class="badge-gray">{{ $ability }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="text-xs text-ink-400">
                                        {{ $token->last_used_at?->diffForHumans() ?? __('profile.never') }}
                                        @if ($token->last_used_ip)
                                            <span class="block font-mono text-ink-600">{{ $token->last_used_ip }}</span>
                                        @endif
                                    </td>
                                    <td class="text-xs text-ink-400">
                                        {{ $token->expires_at?->format('d.m.Y') ?? __('common.never') }}
                                    </td>
                                    <td>
                                        @if (! $token->revoked_at)
                                            <form method="POST" action="{{ route('panel.profile.api_tokens.destroy', $token) }}"
                                                  onsubmit="return confirm('{{ __('common.confirm') }}')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-ghost btn-sm text-red-400">
                                                    @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                                </button>
                                            </form>
                                        @else
                                            <span class="badge-gray">{{ __('common.disabled') }}</span>
                                        @endif
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
