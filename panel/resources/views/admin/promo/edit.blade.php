@extends('layouts.dashboard')

@section('title', ($promo->exists ? __('common.edit') : __('common.create')) . ' — ' . __('nav.promo_codes'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.promo') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('nav.promo_codes') }}
        </a>
        <h1 class="text-2xl font-bold">{{ $promo->exists ? $promo->code : 'Новый промокод' }}</h1>
    </div>

    <form method="POST"
          action="{{ $promo->exists ? route('admin.promo.update', $promo) : route('admin.promo.store') }}"
          class="grid gap-4 lg:grid-cols-3"
          x-data="{ type: @js(old('type', $promo->type ?? 'discount')) }">
        @csrf
        @if ($promo->exists)
            @method('PUT')
        @endif

        <div class="lg:col-span-2 space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.info') }}</h2></div>
                <div class="card-body grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">{{ __('secret_codes.code') }}</label>
                        <input type="text" name="code" value="{{ old('code', $promo->code) }}"
                               @required(! $promo->exists)
                               class="input font-mono uppercase" maxlength="40">
                        @error('code') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">Название (внутреннее)</label>
                        <input type="text" name="name" value="{{ old('name', $promo->name) }}"
                               class="input" maxlength="120">
                    </div>

                    <div>
                        <label class="label">{{ __('common.type') }}</label>
                        <select name="type" class="select" x-model="type">
                            @foreach (\App\Models\PromoCode::TYPES as $key)
                                <option value="{{ $key }}" @selected(old('type', $promo->type ?? 'discount') === $key)>
                                    {{ __('promo.types.' . $key) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="type === 'discount'" x-cloak>
                        <label class="label">Скидка, %</label>
                        <input type="number" name="percent" value="{{ old('percent', $promo->percent) }}"
                               class="input" min="1" max="90">
                    </div>

                    <div x-show="type === 'discount'" x-cloak>
                        <label class="label">Фиксированная скидка, ₽</label>
                        <input type="number" name="amount" value="{{ old('amount', $promo->amount) }}"
                               class="input" min="0" step="0.01">
                    </div>

                    <div x-show="type === 'duration'" x-cloak>
                        <label class="label">Дней аренды</label>
                        <input type="number" name="days" value="{{ old('days', $promo->days) }}"
                               class="input" min="1" max="3650">
                    </div>

                    <div x-show="type === 'discount'" x-cloak>
                        <label class="label">Макс. скидка, ₽</label>
                        <input type="number" name="max_discount" value="{{ old('max_discount', $promo->max_discount) }}"
                               class="input" min="0" step="0.01">
                    </div>
                </div>
            </div>

            <div class="card" x-show="type === 'bonus'">
                <div class="card-header"><h2 class="card-title">{{ __('promo.types.bonus') }}</h2></div>
                <div class="card-body grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">Деньги, ₽</label>
                        <input type="number" name="bonus_rub" value="{{ old('bonus_rub', $promo->bonus_rub) }}"
                               class="input" min="0" step="0.01">
                    </div>
                    <div>
                        <label class="label">Слотов</label>
                        <input type="number" name="bonus_slots" value="{{ old('bonus_slots', $promo->bonus_slots) }}"
                               class="input" min="0" max="1000">
                    </div>
                    <div>
                        <label class="label">RAM, МБ</label>
                        <input type="number" name="bonus_memory_mb"
                               value="{{ old('bonus_memory_mb', $promo->bonus_memory_mb) }}"
                               class="input" min="0" max="65536" step="128">
                    </div>
                    <div>
                        <label class="label">Дней аренды</label>
                        <input type="number" name="bonus_days" value="{{ old('bonus_days', $promo->bonus_days) }}"
                               class="input" min="0" max="365">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Ограничения</h2></div>
                <div class="card-body grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">Макс. использований</label>
                        <input type="number" name="max_uses" value="{{ old('max_uses', $promo->max_uses) }}"
                               class="input" min="1" max="1000000">
                    </div>
                    <div>
                        <label class="label">Лимит на пользователя</label>
                        <input type="number" name="per_user_limit" value="{{ old('per_user_limit', $promo->per_user_limit) }}"
                               class="input" min="1" max="100">
                    </div>
                    <div>
                        <label class="label">Мин. сумма заказа, ₽</label>
                        <input type="number" name="min_order" value="{{ old('min_order', $promo->min_order) }}"
                               class="input" min="0" step="0.01">
                    </div>
                    <div>
                        <label class="label">Действует с</label>
                        <input type="date" name="valid_from" value="{{ old('valid_from', $promo->valid_from?->format('Y-m-d')) }}"
                               class="input">
                    </div>
                    <div>
                        <label class="label">Действует до</label>
                        <input type="date" name="valid_until" value="{{ old('valid_until', $promo->valid_until?->format('Y-m-d')) }}"
                               class="input">
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.status') }}</h2></div>
                <div class="card-body space-y-2">
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="is_active" value="1" class="checkbox" @checked(old('is_active', $promo->is_active ?? true))>
                        {{ __('common.active') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="first_payment_only" value="1" class="checkbox"
                               @checked(old('first_payment_only', $promo->first_payment_only))>
                        Только для первого пополнения
                    </label>
                </div>
            </div>

            @if ($promo->exists)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Статистика</h2></div>
                    <div class="card-body space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-ink-400">Использований</span>
                            <span class="font-medium">{{ $promo->used_count }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-ink-400">Создан</span>
                            <span>{{ $promo->created_at?->format('d.m.Y') }}</span>
                        </div>
                        <a href="{{ route('admin.promo.usages', $promo) }}" class="btn btn-secondary btn-sm w-full">
                            {{ __('promo.my_uses') }}
                        </a>
                    </div>
                </div>
            @endif

            <div class="flex gap-2">
                <button class="btn btn-primary flex-1">{{ __('common.save') }}</button>
                <a href="{{ route('admin.promo') }}" class="btn btn-ghost">{{ __('common.cancel') }}</a>
            </div>
        </div>
    </form>
@endsection
