@php
    /**
     * Список серверов (публичный мониторинг).
     * Ожидает: $servers (array), необязательно $compact
     */
@endphp

@php $emptyText = $emptyText ?? __('landing.no_servers'); @endphp

@if (empty($servers))
    <div class="card p-8 text-center text-ink-400">
        {{ $emptyText }}
    </div>
@else
    <div @class(['grid gap-3', 'lg:grid-cols-2' => $compact, 'sm:grid-cols-2 lg:grid-cols-3' => ! $compact])>
        @foreach ($servers as $server)
            <div @class(['card p-4', 'flex items-center gap-3' => !$compact])>
                <span class="status-dot {{ $server['status'] === 'online' ? 'status-online' : 'status-offline' }} shrink-0"></span>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        @if (! empty($server['icon']))
                            <img src="{{ game_image_url($server['icon'], $server['family'] ?? null) }}" alt=""
                                 class="h-5 w-5 rounded object-contain" onerror="this.style.display='none'">
                        @endif
                        <span class="font-medium truncate">{{ $server['name'] }}</span>
                    </div>
                    <div class="mt-0.5 text-xs text-ink-400 flex items-center gap-2 flex-wrap">
                        <span>{{ $server['game'] }}</span>
                        @if (! empty($server['address']))
                            <code class="text-ink-300">{{ $server['address'] }}</code>
                        @endif
                    </div>
                </div>

                <div class="text-right shrink-0">
                    @if (! empty($server['slots']))
                        <div class="text-sm font-medium tabular-nums">
                            {{ $server['players'] }} / {{ $server['slots'] }}
                        </div>
                        <div class="text-xs text-ink-500">{{ __('common.players') }}</div>
                    @else
                        <div class="text-xs text-ink-500">{{ $server['uptime'] ?? '' }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
