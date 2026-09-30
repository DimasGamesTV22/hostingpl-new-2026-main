@extends('layouts.public')

@section('title', $announcement->title)

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12">
        <a href="{{ route('news') }}" class="btn btn-ghost btn-sm mb-6">← {{ __('common.back') }}</a>

        @if ($announcement->image)
            <img src="{{ $announcement->image }}" alt="" class="w-full rounded-xl mb-8">
        @endif

        <h1 class="text-3xl font-bold">{{ $announcement->title }}</h1>
        <p class="mt-2 text-sm text-ink-500">{{ $announcement->published_at?->format('d.m.Y H:i') }}</p>

        <div class="mt-8 prose prose-invert max-w-none dark:prose-invert">
            {!! $announcement->body !!}
        </div>
    </article>
@endsection
