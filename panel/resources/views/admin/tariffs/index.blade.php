@extends('layouts.dashboard')

@section('title', __('nav.tariffs') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('nav.tariffs') }}</h1>
            <p class="mt-1 text-sm text-ink-400">{{ $tariffs->count() }}</p>
        </div>

        <a href="{{ route('admin.tariffs.create') }}" class="btn btn-primary">
            @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
            {{ __('common.create') }}
        </a>
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('common.name') }}</th>
                        <th class="w-32">{{ __('common.type') }}</th>
                        <th class="w-28">{{ __('store.price') }}</th>
                        <th class="w-32">{{ __('servers.resources') }}</th>
                        <th class="w-20">{{ __('admin.servers') }}</th>
                        <th class="w-20">{{ __('store.orders') }}</th>
                        <th class="w-24">{{ __('common.status') }}</th>
                        <th class="w-32"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tariffs as $tariff)
                        <tr @class(['opacity-60' => ! $tariff->is_active])>
                            <td>
                                <a href="{{ route('admin.tariffs.edit', $tariff) }}"
                                   class="font-medium hover:text-brand-300">{{ $tariff->name }}</a>
                                <div class="text-xs text-ink-500">{{ $tariff->slug }}</div>
                                @if ($tariff->is_trial)
                                    <span class="badge-indigo mt-1">тестовый</span>
                                @endif
                            </td>
                            <td><span class="badge-gray">{{ $models[$tariff->model] ?? $tariff->model }}</span></td>
                            <td class="tabular-nums text-sm">{{ $tariff->formattedPrice() }}</td>
                            <td class="text-xs text-ink-400">
                                {{ $tariff->slots }} сл · {{ mb_gb($tariff->memory_mb) }} · {{ $tariff->cpu_percent }}%
                            </td>
                            <td class="tabular-nums">{{ $tariff->servers_count }}</td>
                            <td class="tabular-nums">{{ $tariff->prices_count }}</td>
                            <td>
                                <span class="badge-{{ $tariff->is_active ? 'green' : 'gray' }}">
                                    {{ $tariff->is_active ? __('common.active') : __('common.inactive') }}
                                </span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1 justify-end">
                                    <form method="POST" action="{{ route('admin.tariffs.toggle', $tariff) }}">
                                        @csrf
                                        <button class="btn btn-ghost btn-sm">
                                            @include('partials.icon', ['name' => $tariff->is_active ? 'stop' : 'play', 'class' => 'w-4 h-4'])
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.tariffs.duplicate', $tariff) }}"
                                          x-data="{ open: false }" class="flex gap-1">
                                        @csrf
                                        <input type="text" name="name" x-show="open" x-cloak required
                                               class="input !py-1 !text-xs w-32" placeholder="Название">
                                        <button class="btn btn-ghost btn-sm" @click="open = ! open"
                                                x-text="open ? 'OK' : '⧉'"></button>
                                    </form>

                                    <a href="{{ route('admin.tariffs.edit', $tariff) }}" class="btn btn-ghost btn-sm">
                                        @include('partials.icon', ['name' => 'cog', 'class' => 'w-4 h-4'])
                                    </a>

                                    @unless ($tariff->is_trial || $tariff->servers_count > 0)
                                        <form method="POST" action="{{ route('admin.tariffs.destroy', $tariff) }}"
                                              onsubmit="return confirm('{{ __('common.confirm') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-ghost btn-sm text-red-400">
                                                @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
