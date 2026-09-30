@php
    /** Карточки тарифов. Ожидает: $tariffs (Collection|Tariff[]) */
    $trialTariff = setting_array('hosting.marketing.trial');
@endphp

<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 {{ count($tariffs) > 3 ? 'xl:grid-cols-4' : '' }}">
    @foreach ($tariffs as $tariff)
        @if ($tariff->is_trial && ! setting_bool('hosting.marketing.trial.enabled', true))
            @continue
        @endif

        <div @class([
            'card relative p-6 flex flex-col',
            'ring-2 ring-brand-500 shadow-glow' => $tariff->is_popular,
        ])>
            @if ($tariff->badge)
                <span @class([
                    'absolute -top-3 left-6 badge',
                    'badge-indigo' => ! $tariff->is_popular,
                    'badge-yellow' => $tariff->is_popular,
                ])>{{ $tariff->badge }}</span>
            @endif

            <div class="flex items-baseline justify-between gap-2">
                <h3 class="text-lg font-semibold">{{ $tariff->name }}</h3>
                <span class="text-xs text-ink-500">/{{ $tariff->billing_period === 'month' ? 'мес' : ($tariff->billing_period === 'day' ? 'день' : $tariff->billing_period) }}</span>
            </div>

            @if ($tariff->short_description)
                <p class="mt-1 text-sm text-ink-400">{{ $tariff->short_description }}</p>
            @endif

            <div class="mt-4 flex items-baseline gap-1">
                @if ($tariff->model === 'slots' && (float) $tariff->price === 0.0)
                    <span class="text-3xl font-bold">{{ money($tariff->priceForSlots(10)) }}</span>
                    <span class="text-sm text-ink-400">/мес за 10 слотов</span>
                @else
                    <span class="text-3xl font-bold">{{ $tariff->formattedPrice() }}</span>
                @endif
            </div>

            @if ($tariff->is_trial)
                <p class="mt-2 text-xs text-emerald-400">
                    {{ __('landing.trial_hint', ['days' => $trialTariff['days'] ?? 3]) }}
                </p>
            @endif

            <!-- Ресурсы -->
            <dl class="mt-5 space-y-2 text-sm flex-1">
                @if (! $tariff->is_trial)
                    <div class="flex justify-between">
                        <dt class="text-ink-400">{{ __('common.slots') }}</dt>
                        <dd class="font-medium">{{ $tariff->slots > 0 ? $tariff->slots : '∞' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-400">{{ __('common.memory') }}</dt>
                        <dd class="font-medium">{{ $tariff->memory_mb > 0 ? mb_gb($tariff->memory_mb) : '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-400">{{ __('common.cpu') }}</dt>
                        <dd class="font-medium">{{ $tariff->cpu_percent > 0 ? $tariff->cpu_percent . '%' : '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-400">{{ __('common.disk') }}</dt>
                        <dd class="font-medium">{{ $tariff->disk_mb > 0 ? mb_gb($tariff->disk_mb) : '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-400">{{ __('common.players') }}/{{ __('common.uptime') }}</dt>
                        <dd class="font-medium">{{ $tariff->max_servers }} {{ __('common.server') }}</dd>
                    </div>
                @endif
            </dl>

            <!-- Особенности -->
            @if ($tariff->features)
                <ul class="mt-5 space-y-1.5 text-sm">
                    @foreach ($tariff->features as $feature)
                        <li class="flex items-start gap-2 text-ink-300">
                            <svg class="w-4 h-4 text-emerald-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                            </svg>
                            <span>{{ $feature['text'] ?? '' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <!-- Кнопка -->
            <div class="mt-6">
                @auth
                    <a href="{{ route('panel.servers.create', ['tariff' => $tariff->id]) }}"
                       @class(['btn w-full', 'btn-primary' => $tariff->is_popular, 'btn-secondary' => ! $tariff->is_popular])>
                        {{ __('landing.choose') }}
                    </a>
                @else
                    <a href="{{ route('register') }}"
                       @class(['btn w-full', 'btn-primary' => $tariff->is_popular, 'btn-secondary' => ! $tariff->is_popular])>
                        {{ __('landing.choose') }}
                    </a>
                @endauth
            </div>
        </div>
    @endforeach
</div>
