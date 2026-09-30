{{-- Вкладка «Консоль» на странице сервера (упрощённая, без полноэкранного режима) --}}
@php $server = $server ?? null; @endphp

<div class="card overflow-hidden">
    <div class="card-header">
        <h2 class="card-title">
            <span class="status-dot {{ $server->isRunning() ? 'status-online' : 'status-offline' }}"></span>
            {{ __('servers.console') }}
        </h2>
        <a href="{{ route('panel.server.console', $server) }}" class="btn btn-primary btn-sm">
            {{ __('common.more') }}
        </a>
    </div>

    <div class="card-body">
        <div class="console !h-64">
            @forelse (array_slice($events->pluck('message')->filter()->values()->all(), -25) as $line)
                <div>{{ $line }}</div>
            @empty
                <div class="console-line-system">{{ __('common.no_data') }}</div>
            @endforelse
        </div>

        <p class="hint mt-3">
            Откройте {{ __('servers.console') }} для полного интерфейса с вводом команд и живым потоком.
        </p>
    </div>
</div>
