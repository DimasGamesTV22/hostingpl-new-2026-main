@extends('layouts.public')

@section('title', __('billing.payment_cancelled') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="max-w-lg mx-auto text-center py-24">
        <div class="mx-auto w-20 h-20 grid place-items-center rounded-2xl bg-amber-500/15 text-amber-400 mb-8">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </div>

        <h1 class="text-3xl font-bold">{{ __('billing.payment_cancelled') }}</h1>
        <p class="mt-3 text-ink-400">
            Платёж не завершён. Деньги не списаны — можете попробовать снова
            или выбрать другой способ оплаты.
        </p>

        <div class="mt-8 flex items-center justify-center gap-3">
            <a href="{{ route('panel.billing.deposit.create') }}" class="btn btn-primary">{{ __('billing.top_up') }}</a>
            <a href="{{ route('panel.billing') }}" class="btn btn-secondary">{{ __('billing.title') }}</a>
        </div>
    </div>
@endsection
