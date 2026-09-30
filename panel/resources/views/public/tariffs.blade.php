@extends('layouts.public')

@section('title', __('landing.tariffs_title') . ' — ' . setting('hosting.branding.name', 'GameDock'))
@section('description', __('landing.tariffs_subtitle'))

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
        <div class="text-center">
            <h1 class="text-3xl font-bold">{{ __('landing.tariffs_title') }}</h1>
            <p class="mt-2 text-ink-400">{{ __('landing.tariffs_subtitle') }}</p>
        </div>

        <div class="mt-10">
            @include('partials.tariff-cards', ['tariffs' => $tariffs])
        </div>

        <!-- Сравнение -->
        <div class="mt-12 overflow-x-auto card">
            <table class="table min-w-[640px]">
                <thead>
                    <tr>
                        <th>{{ __('landing.feature') }}</th>
                        @foreach ($tariffs as $tariff)
                            <th class="text-center">{{ $tariff->name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php
                        $rows = [
                            __('common.slots') => fn ($t) => $t->slots > 0 ? $t->slots : '∞',
                            __('common.memory') => fn ($t) => $t->memory_mb > 0 ? mb_gb($t->memory_mb) : '—',
                            __('common.cpu') => fn ($t) => $t->cpu_percent > 0 ? $t->cpu_percent . '%' : '—',
                            __('common.disk') => fn ($t) => $t->disk_mb > 0 ? mb_gb($t->disk_mb) : '—',
                            __('common.network') => fn ($t) => $t->network_mbps > 0 ? $t->network_mbps . ' Мбит/с' : '—',
                            __('common.server') => fn ($t) => $t->max_servers > 0 ? $t->max_servers : '∞',
                            'Бэкапов' => fn ($t) => $t->backups,
                            'Свой порт' => fn ($t) => $t->allow_custom_port ? '✓' : '—',
                            'RCON' => fn ($t) => $t->allow_rcon ? '✓' : '—',
                            'Доп. пользователи' => fn ($t) => $t->allow_sub_accounts ? '✓' : '—',
                            'Планировщик' => fn ($t) => $t->allow_scheduler ? '✓' : '—',
                            'Приоритетная поддержка' => fn ($t) => $t->priority_support ? '✓' : '—',
                        ];
                    @endphp

                    @foreach ($rows as $label => $getter)
                        <tr>
                            <td class="text-ink-300">{{ $label }}</td>
                            @foreach ($tariffs as $tariff)
                                <td class="text-center tabular-nums">
                                    @php $value = $getter($tariff); @endphp
                                    @if ($value === '✓')
                                        <svg class="w-4 h-4 text-emerald-400 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                        </svg>
                                    @else
                                        {{ $value }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
