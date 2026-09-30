@extends('layouts.dashboard')

@section('title', __('admin.reports') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('admin.reports') }}</h1>
            <p class="mt-1 text-sm text-ink-400">Период: {{ $days }} дн.</p>
        </div>

        <div class="flex items-center gap-1">
            @foreach ([7, 30, 90, 365] as $range)
                <a href="?days={{ $range }}"
                   @class(['px-2.5 py-1 rounded-md text-xs', 'bg-ink-800 text-ink-100' => $days === $range, 'text-ink-400 hover:text-ink-200' => $days !== $range])>
                    {{ $range }} дн.
                </a>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="stat">
            <div class="stat-label">{{ __('admin.revenue') }}</div>
            <div class="stat-value text-emerald-400">{{ $revenue['income_formatted'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('admin.pending') }}</div>
            <div class="stat-value text-amber-400">{{ $revenue['pending_formatted'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('admin.arpu') }}</div>
            <div class="stat-value">{{ money($revenue['arpu']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">{{ __('common.total') }}</div>
            <div class="stat-value">{{ $revenue['servers'] }}</div>
        </div>
    </div>

    <!-- Выручка по дням -->
    <div class="card mb-4" x-data="barChart(@json($daily->map(fn ($d) => ['label' => (string) $d->day, 'value' => (float) $d->total])->values()))">
        <div class="card-header">
            <h2 class="card-title">{{ __('admin.daily') }} — {{ __('admin.revenue') }}</h2>
        </div>
        <div class="card-body">
            <template x-if="data.length === 0">
                <p class="text-sm text-ink-400 text-center py-8">{{ __('common.no_data') }}</p>
            </template>
            <div class="flex items-end gap-0.5 h-48" x-show="data.length > 0">
                <template x-for="(row, i) in data" :key="row.label">
                    <div class="flex-1 min-w-[2px] group relative flex flex-col justify-end h-full"
                         :title="row.label + ': ' + fmt(row.value)">
                        <div class="bg-brand-500/80 hover:bg-brand-400 rounded-t transition-all"
                             :style="`height: ${height(row.value)}%`"></div>
                    </div>
                </template>
            </div>
            <div class="flex justify-between text-xs text-ink-500 mt-2" x-show="data.length > 0">
                <span x-text="data.length ? data[0].label : ''"></span>
                <span x-text="data.length ? data.at(-1).label : ''"></span>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <!-- Регистрации -->
        <div class="card" x-data="barChart(@json($newUsers->map(fn ($d) => ['label' => (string) $d->day, 'value' => (float) $d->total])->values()))">
            <div class="card-header"><h2 class="card-title">{{ __('admin.new_users') }} / {{ __('admin.daily') }}</h2></div>
            <div class="card-body">
                <div class="flex items-end gap-0.5 h-32" x-show="data.length > 0">
                    <template x-for="row in data" :key="row.label">
                        <div class="flex-1 min-w-[2px] relative flex flex-col justify-end h-full"
                             :title="row.label + ': ' + row.value">
                            <div class="bg-sky-500/80 rounded-t" :style="`height: ${height(row.value)}%`"></div>
                        </div>
                    </template>
                </div>
                <p class="text-sm text-ink-400 text-center py-6" x-show="data.length === 0">{{ __('common.no_data') }}</p>
            </div>
        </div>

        <!-- Новые серверы -->
        <div class="card" x-data="barChart(@json($newServers->map(fn ($d) => ['label' => (string) $d->day, 'value' => (float) $d->total])->values()))">
            <div class="card-header"><h2 class="card-title">{{ __('admin.servers') }} / {{ __('admin.daily') }}</h2></div>
            <div class="card-body">
                <div class="flex items-end gap-0.5 h-32" x-show="data.length > 0">
                    <template x-for="row in data" :key="row.label">
                        <div class="flex-1 min-w-[2px] relative flex flex-col justify-end h-full"
                             :title="row.label + ': ' + row.value">
                            <div class="bg-emerald-500/80 rounded-t" :style="`height: ${height(row.value)}%`"></div>
                        </div>
                    </template>
                </div>
                <p class="text-sm text-ink-400 text-center py-6" x-show="data.length === 0">{{ __('common.no_data') }}</p>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3 mt-4">
        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('admin.by_game') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.games') }}</th>
                            <th class="w-20">{{ __('admin.servers') }}</th>
                            <th class="w-20">{{ __('common.slots') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($byGame as $row)
                            <tr>
                                <td>{{ $row->game?->name ?? '—' }}</td>
                                <td class="tabular-nums">{{ $row->total }}</td>
                                <td class="tabular-nums">{{ (int) $row->slots }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-ink-400 py-6">{{ __('common.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('admin.by_node') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.nodes') }}</th>
                            <th class="w-16">{{ __('admin.servers') }}</th>
                            <th class="w-20">{{ __('common.memory') }}</th>
                            <th class="w-20">{{ __('common.disk') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($byNode as $row)
                            <tr>
                                <td>{{ $row->node?->name ?? '—' }}</td>
                                <td class="tabular-nums">{{ $row->total }}</td>
                                <td class="tabular-nums text-xs">{{ mb_gb($row->memory) }}</td>
                                <td class="tabular-nums text-xs">{{ mb_gb($row->disk) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-ink-400 py-6">{{ __('common.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">{{ __('admin.by_method') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('billing.method') }}</th>
                            <th class="w-20">{{ __('common.total') }}</th>
                            <th class="w-32">{{ __('billing.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($methods as $row)
                            <tr>
                                <td>{{ $row->source ?: '—' }}</td>
                                <td class="tabular-nums">{{ $row->total }}</td>
                                <td class="tabular-nums">{{ money($row->amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-ink-400 py-6">{{ __('common.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function barChart(data) {
    return {
        data,
        max: 1,

        init() {
            this.max = Math.max(1, ...this.data.map((d) => Number(d.value) || 0));
        },

        height(value) {
            const v = Number(value) || 0;
            return Math.max(2, (v / this.max) * 100);
        },

        fmt(value) {
            return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value) + ' ₽';
        },
    };
}
</script>
@endpush
