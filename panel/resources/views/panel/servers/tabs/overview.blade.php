{{-- Обзор сервера: метрики, ресурсы, адреса, последние события --}}
@php $server = $server ?? null; @endphp

<div class="grid lg:grid-cols-3 gap-4">
    <!-- Метрики -->
    <div class="lg:col-span-2 space-y-4">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('servers.metrics') }}</h2>
                <div class="flex items-center gap-1">
                    @foreach (['15m', '1h', '6h', '24h', '7d'] as $r)
                        <a href="?tab=overview&range={{ $r }}"
                           @class(['px-2.5 py-1 rounded-md text-xs', 'bg-ink-800 text-ink-100' => ($range ?? '1h') === $r, 'text-ink-400 hover:text-ink-200' => ($range ?? '1h') !== $r])>
                            {{ __('servers.metrics_ranges.' . $r) }}
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="card-body">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                    @foreach ([
                        ['label' => __('common.cpu'), 'value' => round($server->cpu_usage) . '%',
                         'pct' => min(100, $server->cpu_usage)],
                        ['label' => __('common.memory'), 'value' => mb_gb($server->memory_usage_mb),
                         'pct' => $server->memoryUsagePercent()],
                        ['label' => __('common.disk'), 'value' => mb_gb($server->disk_used_mb),
                         'pct' => $server->disk_mb > 0 ? round($server->disk_used_mb / $server->disk_mb * 100) : 0],
                        ['label' => __('common.players'), 'value' => $server->players_online . ' / ' . $server->slots,
                         'pct' => $server->playersPercent()],
                    ] as $m)
                        <div class="rounded-lg bg-ink-900 p-3">
                            <div class="text-[10px] uppercase tracking-wider text-ink-500">{{ $m['label'] }}</div>
                            <div class="mt-0.5 text-lg font-semibold tabular-nums">{{ $m['value'] }}</div>
                            <div class="mt-2 h-1 rounded-full bg-ink-800 overflow-hidden">
                                <div @class([
                                    'h-full transition-all',
                                    'bg-emerald-500' => $m['pct'] < 70,
                                    'bg-amber-500' => $m['pct'] >= 70 && $m['pct'] < 90,
                                    'bg-red-500' => $m['pct'] >= 90,
                                ]) style="width: {{ min(100, $m['pct']) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (count($series) > 1)
                    <div x-data="metricChart(@json($series))">
                        <svg viewBox="0 0 600 120" class="w-full h-32" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="cpuGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#6366f1" stop-opacity="0.4"/>
                                    <stop offset="100%" stop-color="#6366f1" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <polyline :points="cpuLine" fill="none" stroke="#6366f1" stroke-width="1.5"/>
                            <polygon :points="cpuArea" fill="url(#cpuGrad)"/>
                            <polyline :points="memLine" fill="none" stroke="#0ea5e9" stroke-width="1.5"
                                      stroke-dasharray="3 3"/>
                        </svg>
                        <div class="flex justify-between text-xs text-ink-500 mt-1">
                            <span x-text="timeLabel(-1)"></span>
                            <span x-text="timeLabel(0)"></span>
                        </div>
                        <div class="flex gap-4 mt-2 text-xs">
                            <span class="flex items-center gap-1.5">
                                <span class="w-2.5 h-0.5 bg-brand-500"></span> {{ __('common.cpu') }}
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="w-2.5 h-0.5 bg-sky-500"></span> {{ __('common.memory') }}
                            </span>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-ink-400 text-center py-6">
                        {{ __('common.no_data') }} — {{ __('servers.last_seen') }}: {{ $server->metrics_at?->diffForHumans() ?? '—' }}
                    </p>
                @endif
            </div>
        </div>

        <!-- Журнал -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('servers.events') }}</h2>
                <a href="?tab=events" class="text-sm text-brand-400 hover:text-brand-300">{{ __('common.show_all') }}</a>
            </div>
            <div class="divide-y divide-ink-800">
                @forelse ($events as $event)
                    <div class="flex items-start gap-3 px-5 py-3">
                        <span class="badge-{{ $event->levelColor() }} shrink-0">{{ $event->type }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm">{{ $event->title }}</div>
                            @if ($event->message)
                                <div class="text-xs text-ink-400 mt-0.5">{{ Str::limit($event->message, 140) }}</div>
                            @endif
                        </div>
                        <span class="text-xs text-ink-500 shrink-0">{{ $event->created_at->diffForHumans() }}</span>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Правая колонка -->
    <div class="space-y-4">
        <!-- Адреса и порты -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('servers.info') }}</h2></div>
            <div class="card-body space-y-2 text-sm">
                @foreach ([
                    __('servers.connect_address') => $server->address,
                    __('common.port') => $server->game_port,
                    'Query' => $server->query_port,
                    'RCON' => $server->rcon_port,
                ] as $label => $value)
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ $label }}</span>
                        <span class="font-medium font-mono">{{ $value ?? '—' }}</span>
                    </div>
                @endforeach

                <div class="divider"></div>

                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('servers.node') }}</span>
                    <span>{{ $server->node?->name ?? '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('common.uptime') }}</span>
                    <span>{{ $server->uptimeHuman() }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-ink-400">{{ __('servers.last_seen') }}</span>
                    <span>{{ $server->metrics_at?->diffForHumans() ?? '—' }}</span>
                </div>
            </div>
        </div>

        <!-- Ресурсы -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('servers.resources') }}</h2></div>
            <div class="card-body space-y-2 text-sm">
                @foreach ([
                    __('common.memory') => [mb_gb($server->memory_usage_mb), mb_gb($server->memory_mb)],
                    __('common.disk') => [mb_gb($server->disk_used_mb), mb_gb($server->disk_mb)],
                    __('common.cpu') => [round($server->cpu_usage) . '%', $server->cpu_percent . '%'],
                    __('common.network') => [$server->network_mbps . ' Мбит/с', $server->network_mbps . ' Мбит/с'],
                ] as $label => [$used, $limit])
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ $label }}</span>
                        <span class="font-medium tabular-nums">{{ $used }} / {{ $limit }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Оплата -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('servers.expires') }}</h2></div>
            <div class="card-body">
                <div class="text-lg font-semibold">{{ $server->expires_at?->format('d.m.Y H:i') ?? '—' }}</div>
                <div @class(['text-sm mt-1', 'text-red-400' => $server->isExpired(), 'text-ink-400' => ! $server->isExpired()])>
                    {{ $server->expiresInHuman() }}
                </div>
                <a href="{{ route('panel.billing') }}" class="btn btn-secondary btn-sm w-full mt-4">
                    {{ __('nav.billing') }}
                </a>
            </div>
        </div>

        <!-- Команда запуска -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('servers.command_preview') }}</h2></div>
            <div class="card-body">
                <pre class="code text-[11px]">{{ $command ?: '—' }}</pre>
            </div>
        </div>

        <!-- Опасная зона -->
        @can('delete', $server)
            <div class="card border-red-500/30">
                <div class="card-header"><h2 class="card-title text-red-400">{{ __('servers.delete_server') }}</h2></div>
                <div class="card-body" x-data="{ show: false }">
                    <button @click="show = !show" class="btn btn-danger btn-sm w-full">
                        {{ __('servers.delete_server') }}
                    </button>

                    <form x-show="show" x-cloak method="POST" action="{{ route('panel.servers.destroy', $server) }}"
                          class="mt-4 space-y-3">
                        @csrf
                        @method('DELETE')
                        <p class="text-xs text-ink-400">{{ __('servers.delete_confirm') }}</p>
                        <input type="text" name="name" required class="input" placeholder="{{ $server->name }}">
                        <label class="flex items-center gap-2 text-xs text-ink-300">
                            <input type="checkbox" name="purge" value="1" checked class="checkbox">
                            Удалить все файлы
                        </label>
                        <button class="btn btn-danger btn-sm w-full">{{ __('common.delete') }}</button>
                    </form>
                </div>
            </div>
        @endcan
    </div>
</div>

@push('scripts')
<script>
function metricChart(points) {
    return {
        points,
        cpuLine: '', cpuArea: '', memLine: '',

        init() {
            const W = 600, H = 120;
            const max = (key) => Math.max(1, ...this.points.map(p => p[key] ?? 0));

            const maxCpu = 100;
            const maxMem = max('memory');

            const x = (i) => (i / Math.max(1, this.points.length - 1)) * W;

            const cpu = this.points.map((p, i) =>
                `${x(i).toFixed(1)},${(H - ((p.cpu ?? 0) / maxCpu) * H).toFixed(1)}`).join(' ');

            const mem = this.points.map((p, i) =>
                `${x(i).toFixed(1)},${(H - ((p.memory ?? 0) / maxMem) * H).toFixed(1)}`).join(' ');

            this.cpuLine = cpu;
            this.cpuArea = `0,${H} ${cpu} ${W},${H}`;
            this.memLine = mem;
        },

        timeLabel(offset) {
            const t = this.points.at(offset);
            if (!t) return '';
            return new Date(t.t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },
    };
}
</script>
@endpush
