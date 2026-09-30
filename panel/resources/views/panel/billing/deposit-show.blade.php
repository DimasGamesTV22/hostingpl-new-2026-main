@extends('layouts.dashboard')

@section('title', __('billing.deposit.status') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="max-w-2xl mx-auto">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('billing.deposit.status') }}</h2>
                <span class="badge-{{ $deposit->statusColor() }}">{{ $deposit->methodLabel() }}</span>
            </div>

            <div class="card-body text-center py-10">
                <div @class([
                    'mx-auto w-16 h-16 grid place-items-center rounded-2xl mb-5',
                    'bg-emerald-500/15 text-emerald-400' => $deposit->isPaid(),
                    'bg-sky-500/15 text-sky-400' => $deposit->status === \App\Models\Deposit::STATUS_PROCESSING,
                    'bg-amber-500/15 text-amber-400' => $deposit->status === \App\Models\Deposit::STATUS_PENDING,
                    'bg-red-500/15 text-red-400' => in_array($deposit->status, [\App\Models\Deposit::STATUS_FAILED, \App\Models\Deposit::STATUS_EXPIRED], true),
                ])>
                    @include('partials.icon', [
                        'name' => $deposit->isPaid() ? 'check' : 'wallet',
                        'class' => 'w-8 h-8',
                    ])
                </div>

                <div class="text-3xl font-bold tabular-nums">{{ money($deposit->amount) }}</div>

                <p class="mt-2 text-sm text-ink-400">
                    @if ($deposit->isPaid())
                        {{ __('billing.payment_succeeded') }} — {{ $deposit->paid_at?->format('d.m.Y H:i') }}
                    @elseif ($deposit->isExpired())
                        {{ __('billing.errors.payments_disabled') }} ({{ __('billing.deposit.expires') }}: {{ $deposit->expires_at?->format('d.m.Y H:i') }})
                    @else
                        {{ __('billing.payment_pending') }}
                    @endif
                </p>

                @if ($deposit->error)
                    <p class="mt-2 text-xs text-red-400">{{ $deposit->error }}</p>
                @endif
            </div>

            <div class="px-5 pb-5 space-y-2">
                @if ($deposit->invoice_url && ! $deposit->isPaid())
                    <a href="{{ $deposit->invoice_url }}" target="_blank" rel="noopener"
                       class="btn btn-primary w-full">{{ __('billing.deposit.open_link') }}</a>
                @endif

                @unless ($deposit->isPaid() || $deposit->isExpired())
                    <a href="{{ route('panel.billing.deposits.show', $deposit->uuid) }}" class="btn btn-secondary w-full">
                        {{ __('billing.deposit.check') }}
                    </a>
                @endunless

                <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-ghost w-full">
                    {{ __('common.back') }}
                </a>
            </div>

            <div class="px-5 py-3 border-t border-ink-800 text-xs text-ink-500 space-y-1">
                <div>{{ __('billing.deposit.created') }}: {{ $deposit->created_at->format('d.m.Y H:i') }}</div>
                @if ($deposit->expires_at)
                    <div>{{ __('billing.deposit.expires') }}: {{ $deposit->expires_at->format('d.m.Y H:i') }}</div>
                @endif
                <div>ID: <code>{{ $deposit->uuid }}</code></div>
            </div>
        </div>
    </div>
@endsection

@if (in_array($deposit->status, [\App\Models\Deposit::STATUS_PENDING, \App\Models\Deposit::STATUS_PROCESSING], true))
    @push('scripts')
    <script>
    // Платёж ещё обрабатывается — обновляем страницу, пока статус не изменится
    (function () {
        const deadline = Date.now() + 5 * 60 * 1000;

        const timer = setInterval(() => {
            if (Date.now() > deadline) {
                clearInterval(timer);
                return;
            }
            location.reload();
        }, 15000);
    })();
    </script>
    @endpush
@endif
