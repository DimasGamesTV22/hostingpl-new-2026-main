@extends('layouts.dashboard')

@section('title', __('servers.create') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.servers.index') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('servers.title') }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('servers.create') }}</h1>
    </div>

    <form method="POST" action="{{ route('panel.servers.store') }}" class="grid lg:grid-cols-3 gap-6"
          x-data="serverCreate()" x-init="init()">
        @csrf

        <input type="hidden" name="game_id" :value="form.game_id">
        <input type="hidden" name="build" :value="form.build">
        <input type="hidden" name="node_id" :value="form.node_id">

        <!-- Левая колонка: параметры -->
        <div class="lg:col-span-2 space-y-4">
            @error('global') <div class="card p-4 border-red-500/40 text-red-300 text-sm">{{ $message }}</div> @enderror

            <!-- Выбор игры -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">1. {{ __('servers.game') }}</h2>
                </div>
                <div class="card-body">
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 max-h-80 overflow-y-auto scrollbar-none">
                        @foreach ($games as $game)
                            <button type="button"
                                    @click="selectGame(@js([
                                        'id' => $game->id,
                                        'name' => $game->name,
                                        'memory_mb' => (int) $game->default_memory_mb,
                                        'min_memory_mb' => (int) $game->min_memory_mb,
                                        'disk_mb' => (int) $game->default_disk_mb,
                                        'cpu_percent' => (int) $game->default_cpu_percent,
                                        'slots' => (int) $game->default_slots,
                                        'min_slots' => (int) $game->min_slots,
                                        'max_slots' => (int) $game->max_slots,
                                        'builds' => $game->buildList(),
                                    ]))"
                                    :class="form.game_id == {{ $game->id }} && 'ring-2 ring-brand-500 bg-brand-500/10'"
                                    class="flex items-center gap-2.5 p-3 rounded-lg border border-ink-700 text-left hover:border-ink-600 transition">
                                <img src="{{ game_image_url($game->icon, $game->family) }}" alt=""
                                     class="h-8 w-8 rounded object-contain bg-ink-800 shrink-0"
                                     onerror="this.style.display='none'">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium truncate">{{ $game->name }}</div>
                                    <div class="text-xs text-ink-500 truncate">{{ $game->short_description }}</div>
                                </div>
                            </button>
                        @endforeach
                    </div>

                    @error('game_id') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <!-- Тариф -->
            <div class="card" x-show="form.game_id" x-cloak>
                <div class="card-header">
                    <h2 class="card-title">2. {{ __('servers.tariff') }}</h2>
                </div>
                <div class="card-body">
                    @if ($tariffs->isEmpty())
                        <p class="text-ink-400 text-sm">Тарифы не настроены. Обратитесь в поддержку.</p>
                    @else
                        <div class="space-y-2">
                            @foreach ($tariffs as $tariff)
                                <label class="flex items-start gap-3 p-3 rounded-lg border border-ink-700 cursor-pointer hover:border-ink-600 transition"
                                       :class="form.tariff_id == {{ $tariff->id }} && 'ring-1 ring-brand-500 bg-brand-500/5'">
                                    <input type="radio" name="tariff_id" value="{{ $tariff->id }}"
                                           x-model="form.tariff_id" class="checkbox mt-1">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-medium">{{ $tariff->name }}</span>
                                            @if ($tariff->badge)
                                                <span class="badge-indigo">{{ $tariff->badge }}</span>
                                            @endif
                                            @if ($tariff->is_trial)
                                                <span class="badge-green">{{ __('landing.trial_tariff') }}</span>
                                            @endif
                                        </div>
                                        <div class="text-sm text-ink-400 mt-0.5">
                                            {{ $tariff->short_description ?: mb_gb($tariff->memory_mb) . ' RAM · ' . $tariff->slots . ' слотов' }}
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0 font-medium tabular-nums">
                                        {{ $tariff->formattedPrice() }}
                                        <div class="text-xs text-ink-500 font-normal">{{ $tariff->periodLabel() }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('tariff_id') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <!-- Ресурсы -->
            <div class="card" x-show="form.game_id" x-cloak>
                <div class="card-header">
                    <h2 class="card-title">3. {{ __('servers.resources') }}</h2>
                    <span class="text-xs text-ink-500" x-text="form.build_name"></span>
                </div>
                <div class="card-body grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">{{ __('common.memory') }} (МБ)</label>
                        <input type="number" name="memory_mb" x-model.number="form.memory_mb"
                               :min="limits.memory_mb[0]" :max="limits.memory_mb[1]" class="input">
                        <p class="hint">
                            Диапазон: <span x-text="limits.memory_mb[0]"></span>–<span x-text="limits.memory_mb[1]"></span> МБ
                            <span x-show="tariffLimit" x-text="' · по тарифу до ' + tariffLimit + ' МБ'" class="text-amber-400"></span>
                        </p>
                        @error('memory_mb') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('common.disk') }} (МБ)</label>
                        <input type="number" name="disk_mb" x-model.number="form.disk_mb"
                               :min="2048" :max="1048576" class="input">
                        @error('disk_mb') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('common.cpu') }} (%)</label>
                        <input type="number" name="cpu_percent" x-model.number="form.cpu_percent"
                               :min="10" :max="800" class="input">
                        <p class="hint">100% = одно ядро, 200% = два</p>
                        @error('cpu_percent') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('common.slots') }}</label>
                        <input type="number" name="slots" x-model.number="form.slots"
                               :min="limits.slots[0]" :max="limits.slots[1]" class="input">
                        <p class="hint">
                            <span x-text="limits.slots[0]"></span>–<span x-text="limits.slots[1]"></span>
                            <span x-show="tariffSlots" x-text="' · по тарифу до ' + tariffSlots" class="text-amber-400"></span>
                        </p>
                        @error('slots') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('common.network') }} (Мбит/с)</label>
                        <input type="number" name="network_mbps" x-model.number="form.network_mbps"
                               :min="5" :max="1000" class="input">
                    </div>

                    <div>
                        <label class="label">Процессов (pids)</label>
                        <input type="number" name="pids" x-model.number="form.pids"
                               :min="64" :max="8192" class="input">
                    </div>
                </div>

                <div class="px-5 pb-5 space-y-3">
                    <label class="flex items-center gap-2 text-sm text-ink-300 cursor-pointer">
                        <input type="checkbox" name="watchdog" value="1" x-model="form.watchdog" class="checkbox">
                        <span>
                            {{ __('servers.watchdog') }}
                            <span class="block text-xs text-ink-500">{{ __('servers.watchdog_hint') }}</span>
                        </span>
                    </label>

                    <label class="flex items-center gap-2 text-sm text-ink-300 cursor-pointer">
                        <input type="checkbox" name="sub_accounts" value="1" x-model="form.sub_accounts" class="checkbox">
                        {{ __('servers.sub_accounts_enabled') }}
                    </label>
                </div>
            </div>
        </div>

        <!-- Правая колонка: имя, порт, нода -->
        <div class="space-y-4">
            <div class="card lg:sticky lg:top-20">
                <div class="card-header">
                    <h2 class="card-title">{{ __('common.create') }}</h2>
                </div>
                <div class="card-body space-y-4">
                    <div>
                        <label for="name" class="label">{{ __('common.name') }}</label>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required
                               maxlength="80" class="input" placeholder="My server">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    @if ($nodeMode === 'manual')
                        <div>
                            <label for="node_select" class="label">{{ __('servers.node') }}</label>
                            <select id="node_select" class="select" x-model="form.node_id"
                                    @change="syncNode($event.target.value)">
                                <option value="">— {{ __('common.select') }} —</option>
                                @foreach (\App\Models\Node::schedulable()->get() as $node)
                                    <option value="{{ $node->id }}">{{ $node->name }} ({{ $node->region }})</option>
                                @endforeach
                            </select>
                            <p class="hint">{{ __('nodes.mode_hint') }}</p>
                        </div>
                    @else
                        <div class="px-3 py-2.5 rounded-lg bg-ink-900 text-xs text-ink-400">
                            {{ __('nodes.mode_hint') }}
                            <span class="text-brand-400">{{ \App\Models\Node::schedulable()->count() }} {{ __('nodes.title') }}</span>
                        </div>
                    @endif

                    @if (($regions ?? []) !== [])
                        <div>
                            <label for="region" class="label">{{ __('nodes.region') }}</label>
                            <select id="region" name="region" class="select">
                                <option value="">— {{ __('common.select') }} —</option>
                                @foreach ($regions as $region)
                                    <option value="{{ $region }}" @selected(old('region') === $region)>{{ $region }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if (! empty($ports))
                        <div>
                            <label for="port" class="label">{{ __('common.port') }}</label>
                            <select id="port" name="port" class="select">
                                <option value="">— {{ __('common.auto') }} —</option>
                                @foreach ($ports as $band)
                                    <option value="{{ $band['key'] === 'low' ? 3000 : ($band['key'] === 'mid' ? 3050 : 25565) }}">
                                        {{ $band['range'] }} — {{ $band['price'] }} ({{ $band['free'] }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="hint">Покупка конкретного порта. По умолчанию панель выдаст свободный.</p>
                        </div>
                    @endif

                    <button type="submit" class="btn btn-primary w-full py-2.5" x-show="form.game_id" x-cloak
                            :disabled="!form.game_id || busy">
                        <span x-show="!busy">{{ __('servers.create') }}</span>
                        <span x-show="busy" x-cloak>{{ __('common.loading') }}</span>
                    </button>

                    <p class="text-xs text-ink-500 leading-relaxed" x-show="form.game_id" x-cloak>
                        После создания начнётся установка. Прогресс виден на странице сервера.
                        Если установка не удалась — можно запустить переустановку.
                    </p>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
function serverCreate() {
    return {
        busy: false,
        form: {
            game_id: @js(old('game_id')),
            tariff_id: @js(old('tariff_id')),
            build: '',
            node_id: @js(old('node_id') ?: null),
            memory_mb: 1024,
            disk_mb: 10240,
            cpu_percent: 50,
            slots: 20,
            network_mbps: 25,
            pids: 512,
            watchdog: true,
            sub_accounts: true,
        },
        limits: { memory_mb: [512, 65536], slots: [1, 2000] },
        build_name: '',
        selectedGame: null,

        games: @json($games->map(fn ($g) => [
            'id' => $g->id,
            'name' => $g->name,
            'memory_mb' => (int) $g->default_memory_mb,
            'min_memory_mb' => (int) $g->min_memory_mb,
            'disk_mb' => (int) $g->default_disk_mb,
            'cpu_percent' => (int) $g->default_cpu_percent,
            'slots' => (int) $g->default_slots,
            'min_slots' => (int) $g->min_slots,
            'max_slots' => (int) $g->max_slots,
            'builds' => $g->buildList(),
        ])->values()),

        tariffs: @json($tariffs->map(fn ($t) => [
            'id' => $t->id, 'memory_mb' => (int) $t->memory_mb, 'slots' => (int) $t->slots,
        ])->values()),

        get selectedTariff() {
            return this.tariffs.find(t => t.id === this.form.tariff_id);
        },
        get tariffLimit() { return this.selectedTariff?.memory_mb || 0; },
        get tariffSlots() { return this.selectedTariff?.slots || 0; },

        init() {
            if (this.form.game_id) {
                const game = this.games.find(g => g.id == this.form.game_id);
                if (game) this.selectGame(game);
            }
        },

        selectGame(game) {
            this.selectedGame = game;
            this.form.game_id = game.id;
            this.form.memory_mb = game.memory_mb;
            this.form.disk_mb = game.disk_mb;
            this.form.cpu_percent = game.cpu_percent;
            this.form.slots = Math.min(game.slots, game.max_slots);
            this.limits.memory_mb = [game.min_memory_mb, Math.max(65536, game.memory_mb * 8)];
            this.limits.slots = [game.min_slots, game.max_slots];

            // Сборка по умолчанию
            if (game.builds && game.builds.length) {
                this.form.build = game.builds[0].id;
                this.build_name = game.builds[0].name;
            } else {
                this.form.build = '';
                this.build_name = '';
            }

            this.applyTariff();
        },

        applyTariff() {
            const t = this.selectedTariff;
            if (!t) return;
            if (t.memory_mb > 0) this.form.memory_mb = Math.min(this.form.memory_mb, t.memory_mb);
            if (t.slots > 0) this.form.slots = Math.min(this.form.slots, t.slots);
        },

        syncNode(id) { this.form.node_id = id; },

        submit() { this.busy = true; },
    };
}
</script>
@endpush
