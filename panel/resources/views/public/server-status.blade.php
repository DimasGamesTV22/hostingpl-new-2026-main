@extends('layouts.public')

@section('title', $data['name'] . ' — ' . __('nav.status'))

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-12">
        <a href="{{ route('status') }}" class="btn btn-ghost btn-sm mb-6">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
            </svg>
            {{ __('nav.status') }}
        </a>

        <div class="card">
            <div class="card-body">
                <div class="flex flex-wrap items-start gap-5">
                    @if (! empty($data['icon']))
                        <img src="{{ game_image_url($data['icon'], $data['family'] ?? null) }}" alt=""
                             class="h-14 w-14 object-contain" onerror="this.style.display='none'">
                    @endif

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="status-dot {{ $data['status'] === 'online' ? 'status-online' : 'status-offline' }}"></span>
                            <h1 class="text-2xl font-bold">{{ $data['name'] }}</h1>
                            <span class="badge-{{ $data['status'] === 'online' ? 'green' : 'red' }}">
                                {{ $data['status'] === 'online' ? __('common.online') : __('common.offline') }}
                            </span>
                        </div>

                        <p class="mt-1 text-sm text-ink-400">
                            {{ $data['game'] }}
                            @if (! empty($data['version'])) }} {{ $data['version'] }} @endif
                            @if (! empty($data['node'])) }} · {{ $data['node'] }} @endif
                        </p>
                    </div>

                    <div class="text-right">
                        @if (! empty($data['address']))
                            <code class="text-sm bg-ink-900 border border-ink-700 rounded px-3 py-1.5 inline-block">
                                {{ $data['address'] }}
                            </code>
                        @endif
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="stat">
                        <div class="stat-label">{{ __('common.players') }}</div>
                        <div class="stat-value">{{ $data['players'] }} / {{ $data['slots'] }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">{{ __('common.uptime') }}</div>
                        <div class="stat-value">{{ $data['uptime'] }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">{{ __('common.status') }}</div>
                        <div class="stat-value">
                            {{ $data['status'] === 'online' ? __('common.online') : __('common.offline') }}
                        </div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">{{ __('servers.last_seen') }}</div>
                        <div class="stat-value text-base">{{ $data['last_update'] ?? '—' }}</div>
                    </div>
                </div>
            </div>
        </div>

        @if (count($series) > 1)
            <div class="card mt-4" x-data="publicChart(@json($series))">
                <div class="card-header">
                    <h2 class="card-title">{{ __('servers.metrics') }} — 1 {{ mb_strtolower(mb_substr(__('servers.metrics_ranges.1h'), 0, 1)) }}{{ mb_substr(__('servers.metrics_ranges.1h'), 1) }}</h2>
                </div>
                <div class="card-body">
                    <svg viewBox="0 0 600 110" class="w-full h-28" preserveAspectRatio="none">
                        <polyline :points="line" fill="none" stroke="#22c55e" stroke-width="1.5"/>
                    </svg>
                    <div class="flex justify-between text-xs text-ink-500 mt-1">
                        <span x-text="label(0)"></span>
                        <span x-text="label(-1)"></span>
                    </div>
                </div>
            </div>
        @endif

        <div class="mt-6 flex justify-center">
            <a href="{{ route('register') }}" class="btn btn-primary">{{ __('landing.cta_start') }}</a>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function publicChart(points) {
    return {
        points,
        line: '',

        init() {
            const W = 600, H = 110;
            const max = Math.max(1, ...this.points.map((p) => Number(p.players ?? 0)));
            this.line = this.points
                .map((p, i) => {
                    const x = (i / Math.max(1, this.points.length - 1)) * W;
                    const y = H - ((Number(p.players ?? 0) / max) * H);
                    return `${x.toFixed(1)},${y.toFixed(1)}`;
                })
                .join(' ');
        },

        label(offset) {
            const t = this.points.at(offset);
            if (!t) return '';
            return new Date((t.t ?? 0) * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },
    };
}
</script>
@endpush
