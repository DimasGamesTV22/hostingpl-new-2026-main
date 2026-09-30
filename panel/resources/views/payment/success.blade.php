@extends('layouts.public')

@section('title', __('billing.payment_succeeded') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="max-w-lg mx-auto text-center py-24">
        <div class="mx-auto w-20 h-20 grid place-items-center rounded-2xl bg-emerald-500/15 text-emerald-400 mb-8">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
            </svg>
        </div>

        <h1 class="text-3xl font-bold">{{ __('billing.payment_succeeded') }}</h1>
        <p class="mt-3 text-ink-400">
            Средства уже зачислены на баланс. Спасибо!
        </p>

        <div class="mt-8 flex items-center justify-center gap-3">
            <a href="{{ route('panel.billing') }}" class="btn btn-primary">{{ __('billing.title') }}</a>
            <a href="{{ route('panel.servers.index') }}" class="btn btn-secondary">{{ __('nav.my_servers') }}</a>
        </div>
    </div>
@endsection
