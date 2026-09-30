@extends('layouts.base')

@section('body')
    @include('partials.header')

    <main>
        @yield('content')
    </main>

    @include('partials.footer')
@endsection
