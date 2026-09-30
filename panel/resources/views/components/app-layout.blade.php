{{--
    Компонент-обёртка: <x-app-layout>…</x-app-layout>
    Раскладывает содержимое в секцию content базового dashboard-макета.
--}}
@extends('layouts.dashboard')

@section('content')
    {{ $slot }}
@endsection
