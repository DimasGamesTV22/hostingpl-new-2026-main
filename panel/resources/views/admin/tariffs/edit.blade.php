@extends('layouts.dashboard')

@section('title', ($tariff->exists ? __('common.edit') : __('common.create')) . ' — ' . __('nav.tariffs'))

@section('content')
    @php
        // Строки таблицы цен: существующие + три пустые для добавления
        $priceRows = $prices->values()->all();
        $resourceOptions = [
            'extra_slots' => 'Доп. слоты',
            'extra_memory' => 'Доп. RAM',
            'extra_disk' => 'Доп. диск',
            'extra_cpu' => 'Доп. CPU',
            'port' => 'Покупка порта',
            'backup' => 'Доп. бэкап',
            'support' => 'Приоритетная поддержка',
        ];
    @endphp

    <div class="mb-6">
        <a href="{{ route('admin.tariffs') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('nav.tariffs') }}
        </a>
        <h1 class="text-2xl font-bold">{{ $tariff->exists ? $tariff->name : 'Новый тариф' }}</h1>
    </div>

    <form method="POST"
          action="{{ $tariff->exists ? route('admin.tariffs.update', $tariff) : route('admin.tariffs.store') }}"
          class="grid gap-4 lg:grid-cols-3">
        @csrf
        @if ($tariff->exists)
            @method('PUT')
        @endif

        <div class="lg:col-span-2 space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.info') }}</h2></div>
                <div class="card-body grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">{{ __('common.name') }}</label>
                        <input type="text" name="name" value="{{ old('name', $tariff->name) }}" required
                               class="input" maxlength="120">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">Slug</label>
                        <input type="text" name="slug" value="{{ old('slug', $tariff->slug) }}" class="input" maxlength="80">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="label">Краткое описание</label>
                        <input type="text" name="short_description"
                               value="{{ old('short_description', $tariff->short_description) }}"
                               class="input" maxlength="300">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="label">Описание</label>
                        <textarea name="description" rows="3" class="input" maxlength="2000">{{ old('description', $tariff->description) }}</textarea>
                    </div>

                    <div>
                        <label class="label">Модель тарифа</label>
                        <select name="model" class="select" x-data="{ model: @js(old('model', $tariff->model)) }" x-model="model">
                            <option value="package">Фикс-пакет</option>
                            <option value="slots">По слотам</option>
                            <option value="hybrid">Пакет + докупки</option>
                        </select>
                    </div>

                    <div>
                        <label class="label">Период оплаты</label>
                        <select name="billing_period" class="select">
                            @foreach (['day' => 'день', 'week' => 'неделя', 'month' => 'месяц', 'year' => 'год'] as $key => $label)
                                <option value="{{ $key }}" @selected(old('billing_period', $tariff->billing_period) === $key)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label">Длительность периода, дней</label>
                        <input type="number" name="duration_days" value="{{ old('duration_days', $tariff->duration_days) }}"
                               required class="input" min="1" max="3650">
                    </div>

                    <div>
                        <label class="label">{{ __('store.price') }}, ₽</label>
                        <input type="number" name="price" value="{{ old('price', $tariff->price) }}" required
                               class="input" min="0" max="1000000" step="0.01">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('servers.resources') }}</h2></div>
                <div class="card-body grid sm:grid-cols-3 gap-4">
                    <div>
                        <label class="label">Слотов (0 = не ограничено)</label>
                        <input type="number" name="slots" value="{{ old('slots', $tariff->slots) }}"
                               required class="input" min="0" max="10000">
                    </div>
                    <div>
                        <label class="label">RAM, МБ</label>
                        <input type="number" name="memory_mb" value="{{ old('memory_mb', $tariff->memory_mb) }}"
                               required class="input" min="0" max="65536" step="128">
                    </div>
                    <div>
                        <label class="label">CPU, %</label>
                        <input type="number" name="cpu_percent" value="{{ old('cpu_percent', $tariff->cpu_percent) }}"
                               required class="input" min="0" max="800" step="5">
                    </div>
                    <div>
                        <label class="label">Диск, МБ</label>
                        <input type="number" name="disk_mb" value="{{ old('disk_mb', $tariff->disk_mb) }}"
                               required class="input" min="0" max="1048576" step="1024">
                    </div>
                    <div>
                        <label class="label">Сеть, Мбит/с</label>
                        <input type="number" name="network_mbps" value="{{ old('network_mbps', $tariff->network_mbps) }}"
                               required class="input" min="0" max="1000">
                    </div>
                    <div>
                        <label class="label">Процессов (pids)</label>
                        <input type="number" name="pids" value="{{ old('pids', $tariff->pids) }}"
                               required class="input" min="0" max="8192">
                    </div>
                    <div>
                        <label class="label">Бэкапов</label>
                        <input type="number" name="backups" value="{{ old('backups', $tariff->backups) }}"
                               required class="input" min="0" max="100">
                    </div>
                    <div>
                        <label class="label">Серверов на тариф</label>
                        <input type="number" name="max_servers" value="{{ old('max_servers', $tariff->max_servers) }}"
                               required class="input" min="0" max="100">
                    </div>
                </div>
            </div>

            <!-- Докупки -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Докупки</h2>
                    <span class="text-xs text-ink-500">Таблица цен для магазина услуг</span>
                </div>
                <div class="card-body">
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('common.type') }}</th>
                                    <th>{{ __('common.name') }}</th>
                                    <th class="w-24">{{ __('common.unit') }}</th>
                                    <th class="w-28">Шаг</th>
                                    <th class="w-32">{{ __('store.price') }}</th>
                                    <th class="w-24">Мин</th>
                                    <th class="w-24">Макс</th>
                                    <th class="w-20">{{ __('common.sort') }}</th>
                                    <th class="w-16"></th>
                                </tr>
                            </thead>
                            <tbody id="price-rows">
                                @foreach ($priceRows as $row)
                                    @include('admin.tariffs._price-row', ['row' => $row, 'resourceOptions' => $resourceOptions])
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <template id="price-row-template">
                        @include('admin.tariffs._price-row', ['row' => null, 'resourceOptions' => $resourceOptions])
                    </template>

                    <button type="button" class="btn btn-secondary btn-sm mt-3" @click="addPriceRow()">
                        @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
                        {{ __('common.add') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Возможности</h2></div>
                <div class="card-body space-y-2">
                    @foreach ([
                        'allow_sub_accounts' => 'Доп. пользователи',
                        'allow_custom_port' => 'Выбор порта',
                        'allow_console' => 'Веб-консоль',
                        'allow_scheduler' => 'Планировщик',
                        'allow_file_manager' => 'Файловый менеджер',
                        'allow_rcon' => 'RCON',
                        'priority_support' => 'Приоритетная поддержка',
                    ] as $field => $label)
                        <label class="flex items-center gap-2 text-sm text-ink-300">
                            <input type="checkbox" name="{{ $field }}" value="1" class="checkbox"
                                   @checked(old($field, $tariff->{$field}))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.status') }}</h2></div>
                <div class="card-body space-y-2">
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="is_active" value="1" class="checkbox" @checked(old('is_active', $tariff->is_active))>
                        {{ __('common.active') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="is_public" value="1" class="checkbox" @checked(old('is_public', $tariff->is_public))>
                        Публичный
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="is_popular" value="1" class="checkbox" @checked(old('is_popular', $tariff->is_popular))>
                        Популярный (подсветка)
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="is_trial" value="1" class="checkbox" @checked(old('is_trial', $tariff->is_trial))>
                        Тестовый
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="private" value="1" class="checkbox" @checked(old('private', $tariff->private))>
                        Скрытая (не в магазине)
                    </label>

                    <div>
                        <label class="label">Бейдж</label>
                        <input type="text" name="badge" value="{{ old('badge', $tariff->badge) }}"
                               class="input" maxlength="32" placeholder="Хит">
                    </div>

                    <div>
                        <label class="label">{{ __('common.sort') }}</label>
                        <input type="number" name="sort" value="{{ old('sort', $tariff->sort) }}"
                               class="input" min="0" max="999">
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button class="btn btn-primary flex-1">{{ __('common.save') }}</button>
                <a href="{{ route('admin.tariffs') }}" class="btn btn-ghost">{{ __('common.cancel') }}</a>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
function addPriceRow() {
    const tbody = document.getElementById('price-rows');
    const template = document.getElementById('price-row-template');
    if (! tbody || ! template) return;

    const html = template.innerHTML.replace(/__INDEX__/g, String(tbody.children.length));
    tbody.insertAdjacentHTML('beforeend', html);
}
</script>
@endpush
