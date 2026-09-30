@extends('layouts.dashboard')

@section('title', $server->name . ' — ' . __('servers.console'))

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('panel.servers.show', $server) }}" class="btn btn-ghost btn-sm">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ $server->name }}
        </a>

        <div class="flex items-center gap-2">
            <span @class(['badge-gray', 'text-emerald-400' => $server->isRunning(), 'text-amber-400' => $server->isTransitional(), 'text-ink-400' => ! $server->isRunning()])>
                <span class="status-dot {{ $server->isRunning() ? 'status-online' : 'status-offline' }}"></span>
                {{ $server->statusLabel() }}
            </span>

            <a href="{{ route('panel.server.logs', $server) }}" class="btn btn-secondary btn-sm">
                @include('partials.icon', ['name' => 'file', 'class' => 'w-4 h-4'])
                {{ __('servers.logs') }}
            </a>
        </div>
    </div>

    <div x-data="serverConsole"
         data-stream="{{ route('panel.server.console.stream', $server) }}"
         data-send="{{ route('panel.server.console.send', $server) }}"
         data-logs="{{ route('panel.server.logs.download', $server) }}"
         data-control="{{ $canControl ? 1 : 0 }}"
         class="card overflow-hidden">

        <!-- Заголовок -->
        <div class="card-header flex-wrap gap-2">
            <h2 class="card-title">
                <span class="status-dot"
                      :class="connected ? 'status-online animate-pulse-dot' : 'status-offline'"></span>
                {{ __('servers.console') }}
            </h2>

            <div class="flex items-center gap-3 text-xs text-ink-400">
                <span x-show="stats.cpu !== undefined">CPU: <span x-text="Math.round(stats.cpu || 0)"></span>%</span>
                <span x-show="stats.memory !== undefined">
                    RAM: <span x-text="Math.round((stats.memory || 0) / 1024)"></span> ГБ
                </span>
                <span x-show="stats.players !== undefined">
                    {{ __('common.players') }}: <span x-text="stats.players"></span>/<span x-text="stats.slots"></span>
                </span>
            </div>

            <div class="flex items-center gap-1 ml-auto">
                <button @click="togglePause()" class="btn btn-ghost btn-sm" :class="paused && 'text-amber-400'"
                        :title="paused ? 'Продолжить' : 'Пауза'">
                    <span x-text="paused ? '▶' : '⏸'"></span>
                </button>
                <button @click="clear()" class="btn btn-ghost btn-sm" title="{{ __('common.reset') }}">✕</button>
                <a :href="logsUrl" class="btn btn-ghost btn-sm" title="{{ __('common.download') }}">
                    @include('partials.icon', ['name' => 'download', 'class' => 'w-4 h-4'])
                </a>
            </div>
        </div>

        <!-- Вывод -->
        <div class="bg-black p-0">
            <div class="console !h-[560px] !rounded-none !p-4"
                 @scroll="onScroll($event)"
                 x-ref="output">
                <template x-for="(line, index) in lines" :key="index">
                    <div :class="{
                        'console-line-error': line.type === 'error' || line.stream === 'stderr',
                        'console-line-warn': line.type === 'warn',
                        'console-line-command': line.stream === 'command',
                        'console-line-system': line.stream === 'system',
                    }" x-text="line.text"></div>
                </template>

                <div x-show="lines.length === 0" class="console-line-system">
                    {{ __('common.waiting') }}… {{ __('common.no_data') }}
                </div>
            </div>
        </div>

        <!-- Ввод -->
        @if ($canControl)
            <form @submit.prevent="send(); remember()" class="flex items-center gap-2 border-t border-ink-700 p-3">
                <span class="text-emerald-400 font-mono select-none">&gt;</span>
                <input x-ref="input" type="text" x-model="command" autocomplete="off" autofocus
                       :disabled="paused"
                       placeholder="{{ __('servers.console') }} — введите команду"
                       class="input !bg-ink-900 !border-ink-700 font-mono text-sm flex-1">
                <button type="submit" class="btn btn-primary btn-sm">{{ __('common.send') }}</button>
            </form>
        @else
            <div class="border-t border-ink-700 px-4 py-3 text-sm text-ink-400">
                У вас нет прав на ввод команд в консоль.
            </div>
        @endif
    </div>

    <p class="mt-3 text-xs text-ink-500">
        {{ __('servers.console') }} работает через WebSocket. Консоль не пропадёт при переподключении —
        история хранится {{ setting_int('hosting.logs.realtime_buffer', 2000) }} строк.
    </p>
@endsection
