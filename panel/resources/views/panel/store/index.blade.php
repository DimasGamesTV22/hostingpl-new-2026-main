@extends('layouts.dashboard')

@section('title', __('store.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('store.title') }}</h1>
            <p class="mt-1 text-sm text-ink-400">
                {{ __('nav.balance') }}: <span class="font-medium text-ink-100">{{ money($balance) }}</span>
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('panel.store.orders') }}" class="btn btn-ghost btn-sm">{{ __('store.orders') }}</a>
            <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-primary btn-sm">{{ __('billing.top_up') }}</a>
        </div>
    </div>

    @if ($servers->isEmpty())
        <div class="card">
            <div class="px-5 py-12 text-center text-sm text-ink-400">
                Сначала создайте сервер — услуги применяются к конкретным серверам.
            </div>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($products as $key => $product)
                @php
                    if (isset($product['enabled']) && ! $product['enabled']) {
                        continue;
                    }
                @endphp

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ $product['label'] ?? __('store.products.' . $key) }}</h2>
                        <span class="text-xs text-ink-500">{{ $product['unit'] ?? '' }}</span>
                    </div>

                    <div class="card-body">
                        <form method="POST" action="{{ route('panel.store.store') }}" class="space-y-3">
                            @csrf
                            <input type="hidden" name="product" value="{{ $key }}">

                            <div class="grid gap-3 sm:grid-cols-3 items-end">
                                <div>
                                    <label class="label">{{ __('store.select_server') }}</label>
                                    <select name="server_id" class="select" @required($key !== 'backup_slots' && $key !== 'support')>
                                        @if ($key === 'backup_slots' || $key === 'support')
                                            <option value="">— {{ __('common.none') }} —</option>
                                        @endif
                                        @foreach ($servers as $server)
                                            <option value="{{ $server->id }}">
                                                {{ $server->name }} ({{ $server->game->name }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                @if ($key === 'port')
                                    <div>
                                        <label class="label">{{ __('store.select_port') }}</label>
                                        <input type="number" name="port" class="input" min="1" max="65535" required>
                                        <p class="hint">
                                            @foreach ($portRanges as $range)
                                                {{ $range['range'] }} — {{ $range['price'] }} ({{ __('store.free_ports', ['count' => $range['free']]) }})
                                            @endforeach
                                        </p>
                                    </div>
                                @else
                                    <div>
                                        <label class="label">{{ __('store.quantity') }}</label>
                                        <input type="number" name="quantity" value="1" min="1"
                                               max="{{ $key === 'extra_slots' ? 1000 : ($key === 'extra_memory' ? 64 : ($key === 'extra_storage' ? 1024 : 32)) }}"
                                               class="input" @required($key !== 'port')>
                                    </div>
                                @endif

                                <div>
                                    <button class="btn btn-primary w-full">{{ __('store.buy') }}</button>
                                </div>
                            </div>

                            @if ($key === 'extra_slots' && $servers->isNotEmpty())
                                <div class="flex flex-wrap gap-3 text-xs text-ink-500">
                                    @foreach ($servers as $server)
                                        @php $prices = $priceList[$server->id] ?? []; @endphp
                                        @if (isset($prices['extra_slots']))
                                            <span>{{ $server->name }}: {{ $prices['extra_slots']['price'] }}/{{ $prices['extra_slots']['unit'] }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
