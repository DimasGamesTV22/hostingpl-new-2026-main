@extends('layouts.dashboard')

@section('title', __('nav.ip_bans') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('nav.ip_bans') }}</h1>
        <p class="mt-1 text-sm text-ink-400">Блокировка доступа к входу и API по IP или подсети.</p>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    @include('partials.icon', ['name' => 'ban', 'class' => 'w-5 h-5'])
                    {{ __('common.add') }}
                </h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.ip_bans.store') }}" class="space-y-3">
                    @csrf

                    <div>
                        <label class="label">IP или подсеть</label>
                        <input type="text" name="cidr" required class="input font-mono" maxlength="64"
                               placeholder="1.2.3.4 или 1.2.3.0/24">
                        @error('cidr') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('common.reason') }}</label>
                        <input type="text" name="reason" required class="input" maxlength="255"
                               placeholder="Брутфорс, спам">
                        @error('reason') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">Раздел</label>
                        <select name="scope" class="select">
                            <option value="all">{{ __('admin.ip_bans.all') }}</option>
                            <option value="login">{{ __('admin.ip_bans.login') }}</option>
                            <option value="api">{{ __('admin.ip_bans.api') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="label">Действует до (пусто = бессрочно)</label>
                        <input type="datetime-local" name="expires_at" class="input">
                    </div>

                    <button class="btn btn-primary btn-sm w-full">{{ __('common.create') }}</button>
                </form>
            </div>
        </div>

        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">Активные баны</h2>
                <span class="text-xs text-ink-500">{{ $bans->total() }}</span>
            </div>

            @if ($bans->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-40">IP / подсеть</th>
                                <th>{{ __('common.reason') }}</th>
                                <th class="w-28">Раздел</th>
                                <th class="w-24">Попаданий</th>
                                <th class="w-32">Действует до</th>
                                <th class="w-32">Добавил</th>
                                <th class="w-16"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bans as $ban)
                                <tr>
                                    <td class="font-mono text-sm">{{ $ban->cidr }}</td>
                                    <td class="text-ink-400">{{ $ban->reason }}</td>
                                    <td>
                                        <span class="badge-gray">
                                            {{ $ban->scope === 'all' ? __('admin.ip_bans.all') : __('admin.ip_bans.' . $ban->scope) }}
                                        </span>
                                    </td>
                                    <td class="tabular-nums">{{ $ban->hits }}</td>
                                    <td class="text-xs text-ink-400">
                                        {{ $ban->expires_at?->format('d.m.Y H:i') ?? __('common.never') }}
                                    </td>
                                    <td class="text-xs text-ink-500">{{ $ban->created_by_name ?? '—' }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.ip_bans.destroy', $ban) }}"
                                              onsubmit="return confirm('{{ __('common.confirm') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-ghost btn-sm text-red-400">
                                                @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-4 border-t border-ink-800">{{ $bans->links() }}</div>
            @endif
        </div>
    </div>
@endsection
