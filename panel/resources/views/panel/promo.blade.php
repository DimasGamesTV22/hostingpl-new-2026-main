@extends('layouts.dashboard')

@section('title', __('promo.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('promo.title') }}</h1>
        <p class="mt-1 text-sm text-ink-400">Активируйте промокод — скидка, дни аренды или бонус начислятся автоматически.</p>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Активация -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    @include('partials.icon', ['name' => 'gift', 'class' => 'w-5 h-5'])
                    {{ __('promo.activate') }}
                </h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('panel.promo.activate') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="label">{{ __('promo.enter_code') }}</label>
                        <input type="text" name="code" value="{{ old('code') }}" required
                               class="input font-mono uppercase" maxlength="40" placeholder="WELCOME10"
                               style="text-transform: uppercase">
                        @error('code') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('promo.select_server') }}</label>
                        <select name="server_id" class="select">
                            <option value="">— {{ __('common.none') }} —</option>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}" @selected(old('server_id') == $server->id)>
                                    {{ $server->name }} ({{ $server->game->name }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <button class="btn btn-primary w-full">{{ __('promo.activate') }}</button>
                </form>
            </div>
        </div>

        <div class="lg:col-span-2 space-y-4">
            <!-- Доступные -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('promo.available') }}</h2>
                    <span class="text-xs text-ink-500">{{ count($available) }}</span>
                </div>

                @if (empty($available))
                    <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                @else
                    <div class="divide-y divide-ink-800">
                        @foreach ($available as $promo)
                            <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <code class="font-mono font-medium">{{ $promo->code }}</code>
                                        <span class="badge-indigo">{{ __('promo.types.' . $promo->type) }}</span>
                                        <span class="badge-green">{{ $promo->valueLabel() }}</span>
                                    </div>
                                    @if ($promo->name)
                                        <div class="text-xs text-ink-400 mt-0.5">{{ $promo->name }}</div>
                                    @endif
                                    <div class="text-xs text-ink-500 mt-0.5">
                                        осталось: {{ $promo->usesLeft() ?? '∞' }}
                                        @if ($promo->valid_until)
                                            · до {{ $promo->valid_until->format('d.m.Y') }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- История -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('promo.my_uses') }}</h2>
                </div>

                @if ($myUses->isEmpty())
                    <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('common.date') }}</th>
                                    <th>{{ __('promo.title') }}</th>
                                    <th>{{ __('common.value') }}</th>
                                    <th>{{ __('servers.game') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($myUses as $use)
                                    <tr>
                                        <td class="whitespace-nowrap text-ink-400">
                                            {{ $use->created_at->format('d.m.Y H:i') }}
                                        </td>
                                        <td class="font-mono">{{ $use->promoCode?->code ?? '—' }}</td>
                                        <td class="text-ink-300">{{ $use->promoCode?->valueLabel() ?? '—' }}</td>
                                        <td class="text-ink-400">{{ $use->server?->name ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
