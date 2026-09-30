@extends('layouts.public')

@section('title', __('nav.news') . ' — ' . setting('hosting.branding.name', 'GameDock'))

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold">{{ __('nav.news') }}</h1>

        <div class="mt-8 space-y-4">
            @forelse ($announcements as $news)
                <a href="{{ route('news.show', $news) }}" class="card p-5 block transition hover:border-brand-500/40">
                    <div class="flex items-center gap-2 mb-2">
                        @if ($news->is_pinned)
                            <span class="badge-yellow">{{ __('common.beta') }}</span>
                        @endif
                        <span class="text-xs text-ink-500">{{ $news->published_at?->format('d.m.Y') }}</span>
                    </div>
                    <h2 class="font-semibold text-lg">{{ $news->title }}</h2>
                    <p class="mt-2 text-sm text-ink-400 line-clamp-2">
                        {{ $news->summary ?? \Illuminate\Support\Str::limit(strip_tags($news->body), 160) }}
                    </p>
                </a>
            @empty
                <div class="card p-8 text-center text-ink-400">{{ __('common.no_data') }}</div>
            @endforelse
        </div>

        <div class="mt-6">{{ $announcements->links() }}</div>
    </div>
@endsection
