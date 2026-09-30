@extends('layouts.dashboard')

@section('title', __('servers.logs') . ' — ' . $server->name)

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.server.console', $server) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('servers.console') }}
        </a>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">{{ __('servers.logs') }}</h1>

            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('panel.server.logs.search', $server) }}" class="flex gap-2">
                    <input type="search" name="q" class="input !py-1.5 !text-xs w-48" placeholder="{{ __('common.search') }}">
                    <button class="btn btn-secondary btn-sm">{{ __('common.search') }}</button>
                </form>

                <a href="{{ route('panel.server.logs.download', $server) }}"
                   class="btn btn-ghost btn-sm">
                    @include('partials.icon', ['name' => 'download', 'class' => 'w-4 h-4'])
                    {{ __('common.download') }}
                </a>
            </div>
        </div>
    </div>

    @if (! empty($logFiles))
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="text-xs text-ink-500">Файлы:</span>
            <a href="{{ route('panel.server.logs', $server) }}"
               @class(['px-2 py-1 rounded-md text-xs', 'bg-ink-800 text-ink-100' => ! $currentFile, 'text-ink-400 hover:text-ink-200' => $currentFile])>
                latest.log
            </a>
            @foreach ($logFiles as $logFile)
                <a href="{{ route('panel.server.logs', [$server, 'file' => $logFile]) }}"
                   @class(['px-2 py-1 rounded-md text-xs font-mono', 'bg-ink-800 text-ink-100' => $currentFile === $logFile, 'text-ink-400 hover:text-ink-200' => $currentFile !== $logFile])>
                    {{ $logFile }}
                </a>
            @endforeach
        </div>
    @endif

    @if ($error)
        <div class="card p-4 border-red-500/40 text-red-300 text-sm">{{ $error }}</div>
    @elseif (empty($lines))
        <div class="card">
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        </div>
    @else
        <div class="card">
            <div class="card-body">
                <div class="console !h-[65vh]">
                    @foreach ($lines as $line)
                        @php
                            $text = is_array($line) ? (string) ($line['text'] ?? '') : (string) $line;
                            $type = is_array($line) ? (string) ($line['type'] ?? 'stdout') : 'stdout';
                        @endphp
                        <div @class([
                            'console-line-error',
                            'console-line-warn' => $type === 'stderr' || $type === 'warn',
                            'console-line-command' => $type === 'command',
                            'console-line-system' => $type === 'system',
                        ])>{{ $text }}</div>
                    @endforeach
                </div>
            </div>
            <div class="px-5 py-3 border-t border-ink-800 text-xs text-ink-500">
                {{ count($lines) }} строк · файл: {{ $currentFile ?: 'latest.log' }}
            </div>
        </div>
    @endif
@endsection
