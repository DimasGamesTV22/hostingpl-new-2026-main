@extends('layouts.public')

@section('title', $page->title)

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold mb-8">{{ $page->title }}</h1>
        <div class="prose prose-invert max-w-none dark:prose-invert">
            {!! $page->body !!}
        </div>
    </article>
@endsection
