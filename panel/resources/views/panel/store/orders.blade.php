@extends('layouts.dashboard')

@section('title', __('store.orders') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">{{ __('store.orders') }}</h1>
        <a href="{{ route('panel.store') }}" class="btn btn-secondary btn-sm">{{ __('store.title') }}</a>
    </div>

    <div class="card">
        @if ($orders->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.date') }}</th>
                            <th>{{ __('billing.services') }}</th>
                            <th>{{ __('servers.server') }}</th>
                            <th class="w-32">{{ __('common.quantity') }}</th>
                            <th class="w-32">{{ __('store.price') }}</th>
                            <th class="w-28">{{ __('common.status') }}</th>
                            <th class="w-32"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td class="whitespace-nowrap text-ink-400">
                                    {{ $order->created_at->format('d.m.Y H:i') }}
                                </td>
                                <td>
                                    <div class="text-sm">{{ $order->label }}</div>
                                    <div class="text-xs text-ink-500">{{ $order->product }}</div>
                                </td>
                                <td class="text-ink-400">
                                    @if ($order->server)
                                        <a href="{{ route('panel.servers.show', $order->server_id) }}" class="hover:text-brand-300">
                                            {{ $order->server->name }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="tabular-nums">
                                    {{ $order->quantity }} {{ $order->unit }}
                                </td>
                                <td class="tabular-nums">{{ $order->totalFormatted() }}</td>
                                <td><span class="badge-{{ $order->statusColor() }}">{{ $order->status }}</span></td>
                                <td>
                                    @if ($order->status === \App\Models\StoreOrder::STATUS_PENDING)
                                        <form method="POST" action="{{ route('panel.store.orders.pay', $order) }}"
                                              onsubmit="return confirm('{{ __('common.confirm') }}')">
                                            @csrf
                                            <button class="btn btn-primary btn-sm w-full">{{ __('store.pay_with_balance') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-ink-800">{{ $orders->links() }}</div>
        @endif
    </div>
@endsection
