@extends('layouts.public')

@section('title', __('nav.faq') . ' — ' . setting('hosting.branding.name', 'GameDock'))

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold">{{ __('landing.faq_title') }}</h1>

        @if ($faqs->isEmpty())
            <div class="card p-8 mt-8 text-center text-ink-400">{{ __('landing.no_faq') }}</div>
        @else
            <div class="mt-8 space-y-8">
                @foreach ($faqs as $category => $items)
                    <section>
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-400 mb-3">{{ $category }}</h2>

                        <div class="space-y-2" x-data="{ open: null }">
                            @foreach ($items as $index => $faq)
                                <div class="card overflow-hidden">
                                    <button @click="open = open === {{ $index }} ? null : {{ $index }}"
                                            class="w-full flex items-center justify-between gap-4 px-5 py-4 text-left hover:bg-ink-800/50 transition">
                                        <span class="font-medium">{{ $faq->question }}</span>
                                        <svg class="w-5 h-5 text-ink-400 shrink-0 transition-transform"
                                             :class="open === {{ $index }} && 'rotate-180'"
                                             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </button>

                                    <div x-show="open === {{ $index }}" x-collapse x-cloak
                                         class="px-5 pb-4 text-sm text-ink-300 leading-relaxed border-t border-ink-800 pt-3">
                                        {!! nl2br(e($faq->answer)) !!}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif

        <div class="card p-6 mt-10 text-center">
            <p class="text-ink-400 mb-4">{{ __('landing.more_questions') }}</p>
            <a href="{{ route('panel.tickets.create') }}" class="btn btn-primary">
                {{ __('landing.create_ticket') }}
            </a>
        </div>
    </div>
@endsection
