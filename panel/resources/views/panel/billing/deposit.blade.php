@extends('layouts.dashboard')

@section('title', __('billing.deposit.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    @php $currentMethod = (string) old('method', $methods[0]['code'] ?? ''); @endphp

    <div class="mb-6">
        <a href="{{ route('panel.billing') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('billing.title') }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('billing.deposit.title') }}</h1>
        <p class="mt-1 text-sm text-ink-400">Текущий баланс: {{ money(auth()->user()->balance) }}</p>
    </div>

    <form method="POST" action="{{ route('panel.billing.deposit.store') }}"
          class="grid gap-4 lg:grid-cols-3"
          x-data="{ method: @js($currentMethod), amount: @js((float) old('amount', $amount)) }">
        @csrf

        <div class="lg:col-span-2 space-y-4">
            <!-- Способы -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('billing.deposit.choose_method') }}</h2>
                </div>
                <div class="card-body grid sm:grid-cols-2 gap-3">
                    @foreach ($methods as $method)
                        <label @class([
                            'flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition',
                            'border-brand-500 bg-brand-500/5' => true,
                        ])>
                            <input type="radio" name="method" value="{{ $method['code'] }}"
                                   class="border-ink-500 bg-ink-800 text-brand-600 focus:ring-brand-500/50 mt-0.5"
                                   x-model="method" @checked($currentMethod === $method['code'])>
                            <div class="min-w-0">
                                <div class="font-medium text-sm">{{ $method['label'] }}</div>
                                <div class="text-xs text-ink-500 mt-0.5">
                                    от {{ money($method['min']) }}
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Сумма -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('billing.deposit.amount') }}</h2>
                </div>
                <div class="card-body space-y-4">
                    <div>
                        <input type="number" name="amount" x-model="amount" required class="input text-lg"
                               min="{{ $min }}" max="1000000" step="1"
                               placeholder="{{ (int) $min }}">
                        @error('amount') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <p class="label">{{ __('billing.deposit.quick_amounts') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([100, 300, 500, 1000, 3000, 5000] as $quick)
                                <button type="button" class="btn btn-secondary btn-sm"
                                        @click="amount = {{ $quick }}">{{ money($quick) }}</button>
                            @endforeach
                        </div>
                    </div>

                    @if ($currentMethod === 'cryptobot')
                        <div>
                            <label class="label">{{ __('billing.crypto') }}</label>
                            <select name="crypto" class="select">
                                @foreach ($cryptoAssets as $asset)
                                    <option value="{{ $asset }}" @selected(old('crypto') === $asset)>{{ $asset }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <label class="label">{{ __('billing.promo_code') }}</label>
                        <input type="text" name="promo_code" value="{{ old('promo_code') }}" class="input font-mono uppercase"
                               maxlength="40" placeholder="WELCOME10">
                        @error('promo_code') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <button class="btn btn-primary w-full sm:w-auto">
                        {{ __('billing.deposit.pay') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Сводка -->
        <div class="space-y-4">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('common.info') }}</h2></div>
                <div class="card-body space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('billing.amount') }}</span>
                        <span class="font-medium tabular-nums" x-text="fmt(amount)"></span>
                    </div>
                    <div class="divider"></div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('billing.balance') }}</span>
                        <span class="font-medium">{{ money(auth()->user()->balance) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('billing.auto_topup') }}</span>
                        <span class="text-ink-300">{{ implode(', ', array_map('strval', (array) $autoTopup)) ?: '—' }}</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body text-xs text-ink-400 space-y-2">
                    <p>{{ __('billing.deposit.waiting') }}</p>
                    <p>
                        Пополнить баланс можно в любое время. Средства списываются автоматически
                        раз в {{ setting('hosting.billing.billing_period_days', 30) }} дн. за каждый сервер.
                    </p>
                    <a href="{{ route('panel.tickets.create') }}" class="link">Не получается? Напишите в поддержку</a>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
function fmt(n) {
    return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
        .format(Number(n) || 0) + ' ' + @js(setting('hosting.billing.currency_symbol', '₽'));
}
</script>
@endpush
