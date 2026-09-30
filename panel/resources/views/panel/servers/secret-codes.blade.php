@extends('layouts.dashboard')

@section('title', __('secret_codes.title') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ $server->name }}
        </a>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">{{ __('secret_codes.title') }}</h1>

            <div class="flex items-center gap-2">
                <a href="{{ route('panel.server.secret_codes.usages', $server) }}" class="btn btn-ghost btn-sm">
                    {{ __('secret_codes.usage_history') }}
                </a>
            </div>
        </div>
    </div>

    @unless ($enabled)
        <div class="mb-4 px-4 py-3 rounded-lg bg-ink-800 border border-ink-700 text-sm text-ink-300">
            {{ __('secret_codes.errors.disabled') }}
        </div>
    @endunless

    @if ($inGame)
        <div class="mb-4 px-4 py-3 rounded-lg bg-sky-500/10 border border-sky-500/30 text-sky-200 text-sm">
            {{ __('secret_codes.in_chat_hint', ['prefixes' => implode(', ', $prefixes)]) }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Коды -->
        <div class="lg:col-span-2 space-y-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('secret_codes.server_codes') }}</h2>
                    <span class="text-xs text-ink-500">{{ count($serverCodes) }}</span>
                </div>

                @if ($serverCodes->isEmpty())
                    <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('secret_codes.code') }}</th>
                                    <th>{{ __('secret_codes.reward') }}</th>
                                    <th class="w-28">{{ __('secret_codes.max_uses') }}</th>
                                    <th class="w-16"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($serverCodes as $code)
                                    <tr @class(['opacity-50' => ! $code->is_active])>
                                        <td>
                                            <div class="font-mono font-medium">{{ $code->maskedCode() }}</div>
                                            @if ($code->hint)
                                                <div class="text-xs text-ink-500">{{ $code->hint }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge-indigo">{{ $code->rewardLabel() }}</span>
                                        </td>
                                        <td class="tabular-nums text-ink-400">
                                            {{ $code->used_count }} / {{ $code->max_uses }}
                                        </td>
                                        <td>
                                            @if ($code->is_active)
                                                <form method="POST"
                                                      action="{{ route('panel.server.secret_codes.destroy', [$server, $code]) }}"
                                                      onsubmit="return confirm('{{ __('common.confirm') }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-ghost btn-sm text-red-400"
                                                            title="{{ __('secret_codes.messages.disabled') }}">
                                                        @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                                    </button>
                                                </form>
                                            @else
                                                <span class="badge-gray">{{ __('secret_codes.disabled') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            @if (count($myCodes) > 0)
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('secret_codes.my_codes') }}</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('secret_codes.code') }}</th>
                                    <th>{{ __('secret_codes.reward') }}</th>
                                    <th class="w-28">{{ __('secret_codes.max_uses') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($myCodes as $code)
                                    <tr>
                                        <td>
                                            <div class="font-mono">{{ $code->maskedCode() }}</div>
                                            @if ($code->hint)
                                                <div class="text-xs text-ink-500">{{ $code->hint }}</div>
                                            @endif
                                        </td>
                                        <td><span class="badge-indigo">{{ $code->rewardLabel() }}</span></td>
                                        <td class="tabular-nums text-ink-400">{{ $code->used_count }} / {{ $code->max_uses }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <!-- Создание -->
        @if ($enabled)
            <div class="card" x-data="{ reward: 'money', scope: 'server' }">
                <div class="card-header">
                    <h2 class="card-title">{{ __('secret_codes.create') }}</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('panel.server.secret_codes.store', $server) }}" class="space-y-4">
                        @csrf

                        <div>
                            <label class="label">{{ __('secret_codes.scope') }}</label>
                            <select name="scope" class="select" x-model="scope">
                                <option value="server">{{ __('secret_codes.scope_server') }}</option>
                                <option value="personal">{{ __('secret_codes.scope_personal') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="label">{{ __('secret_codes.code') }}</label>
                            <input type="text" name="code" value="{{ old('code') }}" class="input font-mono uppercase"
                                   maxlength="64" placeholder="— сгенерировать —">
                            @error('code') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label">{{ __('secret_codes.hint') }}</label>
                            <input type="text" name="hint" value="{{ old('hint') }}" class="input" maxlength="190"
                                   placeholder="Что нужно сделать игроку">
                            @error('hint') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label">{{ __('secret_codes.reward') }}</label>
                            <select name="reward_type" class="select" x-model="reward">
                                @foreach ($rewards as $reward)
                                    <option value="{{ $reward }}">{{ __('secret_codes.rewards.' . $reward) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div x-show="['money', 'credit'].includes(reward)" x-cloak>
                            <label class="label">Сумма, {{ setting('hosting.billing.currency', 'RUB') }}</label>
                            <input type="number" name="reward_value" value="{{ old('reward_value', 100) }}"
                                   class="input" min="0" max="100000" step="1">
                        </div>

                        <div x-show="reward === 'slots'" x-cloak>
                            <label class="label">Слотов</label>
                            <input type="number" name="reward_value" value="{{ old('reward_value', 5) }}"
                                   class="input" min="0" max="100000">
                        </div>

                        <div x-show="reward === 'memory'" x-cloak>
                            <label class="label">МБ памяти</label>
                            <input type="number" name="reward_memory_mb" value="{{ old('reward_memory_mb', 512) }}"
                                   class="input" min="0" max="65536" step="128">
                        </div>

                        <div x-show="reward === 'days'" x-cloak>
                            <label class="label">Дней аренды</label>
                            <input type="number" name="reward_days" value="{{ old('reward_days', 1) }}"
                                   class="input" min="1" max="365">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="label">{{ __('secret_codes.max_uses') }}</label>
                                <input type="number" name="max_uses" value="{{ old('max_uses', 1) }}"
                                       class="input" min="1" max="100000">
                            </div>
                            <div>
                                <label class="label">{{ __('secret_codes.per_player') }}</label>
                                <input type="number" name="per_player_limit" value="{{ old('per_player_limit', 1) }}"
                                       class="input" min="1" max="100">
                            </div>
                        </div>

                        <button class="btn btn-primary w-full">{{ __('secret_codes.create') }}</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
