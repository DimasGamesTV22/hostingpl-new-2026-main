@extends('layouts.dashboard')

@section('title', __('profile.webhooks') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.profile') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('profile.title') }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('profile.webhooks') }}</h1>
        <p class="mt-1 text-sm text-ink-400">{{ __('profile.webhooks_hint') }}</p>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('common.add') }}</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('panel.profile.webhooks.create') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="label">{{ __('common.name') }}</label>
                        <input type="text" name="name" class="input" maxlength="60" placeholder="Мой бот">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('profile.webhook_url') }}</label>
                        <input type="url" name="url" required class="input" maxlength="1024"
                               placeholder="https://example.com/hooks/gamedock">
                        @error('url') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label">{{ __('profile.webhook_events') }}</label>
                        <div class="max-h-64 overflow-y-auto space-y-1 rounded-lg border border-ink-700 p-3">
                            @foreach ($events as $event)
                                <label class="flex items-center gap-2 text-sm text-ink-300">
                                    <input type="checkbox" name="events[]" value="{{ $event }}" class="checkbox"
                                           @checked($event === 'server.created')>
                                    <code class="text-xs">{{ $event }}</code>
                                </label>
                            @endforeach
                        </div>
                        @error('events') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <button class="btn btn-primary w-full">{{ __('common.create') }}</button>
                </form>
            </div>
        </div>

        <div class="lg:col-span-2 card">
            <div class="card-header">
                <h2 class="card-title">{{ __('profile.webhooks') }}</h2>
                <span class="text-xs text-ink-500">{{ $webhooks->count() }}</span>
            </div>

            @if ($webhooks->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
            @else
                <div class="divide-y divide-ink-800">
                    @foreach ($webhooks as $webhook)
                        <div class="flex flex-wrap items-start gap-3 px-5 py-4">
                            <span class="status-dot status-{{ $webhook->healthColor() === 'green' ? 'online' : ($webhook->healthColor() === 'red' ? 'offline' : 'unknown') }} mt-1.5"></span>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium">{{ $webhook->name }}</span>
                                    <span class="badge-{{ $webhook->healthColor() }}">
                                        {{ $webhook->last_status ?: '—' }}
                                    </span>
                                </div>
                                <code class="text-xs text-ink-400 break-all block mt-0.5">{{ $webhook->url }}</code>
                                <div class="flex flex-wrap gap-1 mt-2">
                                    @foreach ((array) $webhook->events as $event)
                                        <span class="badge-gray">{{ $event }}</span>
                                    @endforeach
                                </div>
                                <div class="text-xs text-ink-600 mt-1">
                                    успешно: {{ $webhook->success_count }} · ошибок: {{ $webhook->failures }}
                                    @if ($webhook->last_fired_at)
                                        · {{ $webhook->last_fired_at->diffForHumans() }}
                                    @endif
                                </div>
                            </div>

                            <form method="POST" action="{{ route('panel.profile.webhooks.destroy', $webhook) }}"
                                  onsubmit="return confirm('{{ __('common.confirm') }}')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-ghost btn-sm text-red-400">
                                    @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><h2 class="card-title">Пример payload</h2></div>
        <div class="card-body">
            <pre class="code">{{ json_encode([
                'event' => 'server.crashed',
                'created_at' => now()->toIso8601String(),
                'data' => [
                    'server_id' => 42,
                    'name' => 'MyServer',
                    'game' => 'cs2',
                    'status' => 'crashed',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            <p class="hint">
                Подпись: <code class="text-ink-300">X-Gamedock-Signature: sha256=…</code> —
                HMAC-SHA256 тела запроса секретом вебхука в заголовке <code>X-Gamedock-Secret</code>.
            </p>
        </div>
    </div>
@endsection
