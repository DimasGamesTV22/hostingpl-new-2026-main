@extends('layouts.dashboard')

@section('title', __('billing.deposit.closed') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="max-w-xl mx-auto text-center py-16">
        <div class="mx-auto w-16 h-16 grid place-items-center rounded-2xl bg-ink-800 text-ink-500 mb-6">
            @include('partials.icon', ['name' => 'wallet', 'class' => 'w-8 h-8'])
        </div>

        <h1 class="text-2xl font-bold">{{ __('billing.deposit.closed') }}</h1>
        <p class="mt-2 text-ink-400">{{ __('billing.deposit.closed_hint') }}</p>

        <div class="mt-8 flex items-center justify-center gap-2">
            <a href="{{ route('panel.billing') }}" class="btn btn-secondary">{{ __('billing.title') }}</a>
            <a href="{{ route('panel.tickets.create') }}" class="btn btn-primary">{{ __('support.new_ticket') }}</a>
        </div>
    </div>
@endsection
