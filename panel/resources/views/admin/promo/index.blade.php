@extends('layouts.dashboard')

@section('title', __('nav.promo_codes') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('nav.promo_codes') }}</h1>
            <p class="mt-1 text-sm text-ink-400">{{ $codes->total() }}</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.promo.create') }}" class="btn btn-primary">
                @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
                {{ __('common.create') }}
            </a>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap gap-2 text-xs">
        <span class="text-ink-500">Включено:</span>
        <span class="badge-{{ $features['discount'] ? 'green' : 'gray' }}">скидки</span>
        <span class="badge-{{ $features['duration'] ? 'green' : 'gray' }}">дни аренды</span>
        <span class="badge-{{ $features['bonus'] ? 'green' : 'gray' }}">бонусы</span>
        <span class="ml-auto text-ink-500">настраивается в разделе «Настройки»</span>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.promo') }}" class="flex flex-wrap gap-3 items-end">
                <div class="relative flex-1 min-w-[220px]">
                    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="{{ __('common.search') }}">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-ink-500" fill="none"
                         stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                </div>

                <div>
                    <label class="label">{{ __('common.type') }}</label>
                    <select name="type" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>
                                {{ __('promo.types.' . $type) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <button class="btn btn-primary btn-sm">{{ __('common.apply_filter') }}</button>
                <a href="{{ route('admin.promo') }}" class="btn btn-ghost btn-sm">{{ __('common.reset_filter') }}</a>
            </form>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 card">
            @if ($codes->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_results') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('promo.title') }}</th>
                                <th class="w-28">{{ __('common.type') }}</th>
                                <th class="w-32">Значение</th>
                                <th class="w-32">Использований</th>
                                <th class="w-28">{{ __('common.status') }}</th>
                                <th class="w-32"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($codes as $promo)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.promo.edit', $promo) }}"
                                           class="font-mono font-medium hover:text-brand-300">{{ $promo->code }}</a>
                                        @if ($promo->name)
                                            <div class="text-xs text-ink-500">{{ $promo->name }}</div>
                                        @endif
                                    </td>
                                    <td><span class="badge-gray">{{ __('promo.types.' . $promo->type) }}</span></td>
                                    <td class="text-sm">{{ $promo->valueLabel() }}</td>
                                    <td>
                                        <div class="text-xs tabular-nums">
                                            {{ $promo->used_count }} / {{ $promo->max_uses ?? '∞' }}
                                        </div>
                                        @if ($promo->max_uses)
                                            <div class="mt-1 h-1 rounded-full bg-ink-800 overflow-hidden">
                                                <div class="h-full bg-brand-500" style="width: {{ $promo->progressPercent() }}%"></div>
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge-{{ $promo->statusColor() }}">
                                            {{ $promo->isExpired() ? 'истёк' : ($promo->is_active ? 'активен' : 'выкл') }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-1 justify-end">
                                            <a href="{{ route('admin.promo.usages', $promo) }}" class="btn btn-ghost btn-sm"
                                               title="{{ __('promo.my_uses') }}">
                                                @include('partials.icon', ['name' => 'chart', 'class' => 'w-4 h-4'])
                                            </a>

                                            <form method="POST" action="{{ route('admin.promo.toggle', $promo) }}">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm">
                                                    @include('partials.icon', ['name' => $promo->is_active ? 'stop' : 'play', 'class' => 'w-4 h-4'])
                                                </button>
                                            </form>

                                            <a href="{{ route('admin.promo.edit', $promo) }}" class="btn btn-ghost btn-sm">
                                                @include('partials.icon', ['name' => 'cog', 'class' => 'w-4 h-4'])
                                            </a>

                                            <form method="POST" action="{{ route('admin.promo.destroy', $promo) }}"
                                                  onsubmit="return confirm('{{ __('common.confirm') }}')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-ghost btn-sm text-red-400">
                                                    @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-4 border-t border-ink-800">{{ $codes->links() }}</div>
            @endif
        </div>

        <!-- Генератор -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Массовая генерация</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.promo.generate') }}" class="space-y-3"
                      x-data="{ type: @js(old('type', 'discount')) }">
                    @csrf

                    <div>
                        <label class="label">Количество</label>
                        <input type="number" name="count" value="{{ old('count', 10) }}" required
                               class="input" min="1" max="1000">
                    </div>

                    <div>
                        <label class="label">Префикс</label>
                        <input type="text" name="prefix" value="{{ old('prefix', 'PROMO') }}" class="input" maxlength="16">
                    </div>

                    <div>
                        <label class="label">{{ __('common.type') }}</label>
                        <select name="type" class="select" x-model="type">
                            @foreach ($types as $key)
                                <option value="{{ $key }}">{{ __('promo.types.' . $key) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="type === 'discount'" x-cloak>
                        <label class="label">Скидка, %</label>
                        <input type="number" name="percent" value="{{ old('percent', 10) }}" class="input" min="1" max="90">
                    </div>

                    <div x-show="type === 'duration'" x-cloak>
                        <label class="label">Дней</label>
                        <input type="number" name="days" value="{{ old('days', 3) }}" class="input" min="1" max="365">
                    </div>

                    <div x-show="type === 'bonus'" x-cloak>
                        <label class="label">Бонус, ₽</label>
                        <input type="number" name="bonus_rub" value="{{ old('bonus_rub', 100) }}" class="input" min="0" max="100000">
                    </div>

                    <div>
                        <label class="label">Макс. использований на код</label>
                        <input type="number" name="max_uses" value="{{ old('max_uses', 1) }}" class="input" min="1" max="1000000">
                    </div>

                    <div>
                        <label class="label">Лимит на пользователя</label>
                        <input type="number" name="per_user_limit" value="{{ old('per_user_limit', 1) }}" class="input" min="1" max="100">
                    </div>

                    <div>
                        <label class="label">Действителен до</label>
                        <input type="date" name="valid_until" value="{{ old('valid_until', now()->addMonth()->format('Y-m-d')) }}"
                               class="input">
                    </div>

                    <button class="btn btn-primary btn-sm w-full">{{ __('common.create') }}</button>
                </form>
            </div>
        </div>
    </div>
@endsection
