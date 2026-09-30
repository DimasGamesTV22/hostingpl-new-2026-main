@php
    /**
     * Планировщик (cron-задачи сервера).
     * Ожидает: $server, $schedules, $jobTypes (map type => label), $presets
     */
@endphp

<div class="grid gap-4 lg:grid-cols-3">
    <!-- Форма добавления -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                @include('partials.icon', ['name' => 'clock', 'class' => 'w-5 h-5'])
                {{ __('servers.scheduler.add') }}
            </h2>
        </div>

        <div class="card-body">
            <form method="POST" action="{{ route('panel.server.schedules.store', $server) }}"
                  class="space-y-4" x-data="scheduleForm('{{ old('job_type', 'command') }}')">
                @csrf

                <div>
                    <label class="label">{{ __('servers.scheduler.name') }}</label>
                    <input type="text" name="name" value="{{ old('name') }}" required class="input"
                           maxlength="120" placeholder="Ночной рестарт">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label">{{ __('servers.scheduler.job_type') }}</label>
                    <select name="job_type" class="select" x-model="type"
                            @change="$dispatch('job-changed', $event.target.value)">
                        @foreach ($jobTypes as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('job_type') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div x-show="type === 'command'" x-cloak>
                    <label class="label">{{ __('servers.scheduler.command') }}</label>
                    <input type="text" name="command" value="{{ old('command') }}" class="input font-mono"
                           maxlength="2000" placeholder="say АНОНС">
                    @error('command') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div x-show="type === 'backup' || type === 'update'" x-cloak>
                    <label class="label" x-text="type === 'backup' ? '{{ __('backups.name') }}' : '{{ __('servers.build') }}'"></label>
                    <input type="text" name="name_option" value="{{ old('name_option') }}" class="input"
                           maxlength="120" :placeholder="type === 'backup' ? 'auto' : 'последняя'">
                </div>

                <div x-show="type === 'webhook'" x-cloak>
                    <label class="label">Webhook URL</label>
                    <input type="url" name="url" value="{{ old('url') }}" class="input" maxlength="1024"
                           placeholder="https://example.com/hook">
                    @error('url') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label">{{ __('servers.scheduler.expression') }}</label>
                    <input type="text" name="expression" value="{{ old('expression', '0 4 * * *') }}" required
                           class="input font-mono" maxlength="120">
                    <p class="hint">минуты, часы, день, месяц, день недели</p>
                    @error('expression') <p class="error">{{ $message }}</p> @enderror

                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach ($presets as $preset)
                            <button type="button"
                                    class="px-2 py-1 rounded-md text-[11px] bg-ink-800 text-ink-300 hover:bg-ink-700"
                                    @click="$el.closest('form').querySelector('[name=expression]').value = @js($preset['expression'])">
                                {{ $preset['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-2 pt-2 border-t border-ink-800">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_active" value="1" class="checkbox" checked>
                        {{ __('servers.scheduler.active') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="run_on_stopped" value="1" class="checkbox" checked>
                        <span class="text-ink-300">{{ __('servers.scheduler.runs_on_stopped') }}</span>
                    </label>
                </div>

                <button class="btn btn-primary w-full">{{ __('common.add') }}</button>
            </form>
        </div>
    </div>

    <!-- Список -->
    <div class="lg:col-span-2 card">
        <div class="card-header">
            <h2 class="card-title">{{ __('servers.scheduler.title') }}</h2>
            <span class="text-xs text-ink-500">{{ $schedules->count() }} / {{ setting('hosting.scheduler.max_per_server', 20) }}</span>
        </div>

        @if ($schedules->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-ink-400">
                {{ __('servers.scheduler.empty') }}
            </div>
        @else
            <div class="divide-y divide-ink-800">
                @foreach ($schedules as $schedule)
                    <div class="px-5 py-4" x-data="{ active: {{ $schedule->is_active ? 'true' : 'false' } }">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="status-dot {{ $schedule->is_active ? 'status-online' : 'status-offline' }}"></span>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium">{{ $schedule->name }}</span>
                                    <span class="badge-{{ $schedule->statusColor() }}">{{ $schedule->jobLabel() }}</span>
                                    @if ($schedule->last_status === 'failed')
                                        <span class="badge-red">{{ __('servers.scheduler.failed', ['count' => $schedule->failure_count]) }}</span>
                                    @endif
                                </div>
                                <div class="text-xs text-ink-400 mt-0.5 font-mono">{{ $schedule->commandPreview() }}</div>
                                <div class="text-xs text-ink-500 mt-0.5">
                                    <code>{{ $schedule->expression }}</code> · {{ $schedule->humanInterval() }}
                                    @if ($schedule->next_run_at)
                                        · {{ __('servers.scheduler.next_run') }} {{ $schedule->next_run_at->format('d.m.Y H:i') }}
                                    @endif
                                    @if ($schedule->last_run_at)
                                        · {{ __('servers.scheduler.last_run') }} {{ $schedule->last_run_at->diffForHumans() }}
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-1 shrink-0">
                                <form method="POST" action="{{ route('panel.server.schedules.run', [$server, $schedule]) }}">
                                    @csrf
                                    <button class="btn btn-ghost btn-sm" title="{{ __('servers.scheduler.run_now') }}">
                                        @include('partials.icon', ['name' => 'play', 'class' => 'w-4 h-4'])
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('panel.server.schedules.toggle', [$server, $schedule]) }}">
                                    @csrf
                                    <button class="btn btn-ghost btn-sm"
                                            title="{{ $schedule->is_active ? __('servers.scheduler.paused') : __('servers.scheduler.active') }}">
                                        @include('partials.icon', ['name' => $schedule->is_active ? 'stop' : 'check', 'class' => 'w-4 h-4'])
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('panel.server.schedules.destroy', [$server, $schedule]) }}"
                                      onsubmit="return confirm('{{ __('common.confirm') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-ghost btn-sm text-red-400" title="{{ __('common.delete') }}">
                                        @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                    </button>
                                </form>
                            </div>
                        </div>

                        @if ($schedule->last_output)
                            <details class="mt-2">
                                <summary class="text-xs text-ink-500 cursor-pointer">{{ __('servers.scheduler.last_run') }}</summary>
                                <pre class="code mt-1 text-[11px]">{{ $schedule->last_output }}</pre>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
function scheduleForm(initial) {
    return {
        type: initial,
    };
}
</script>
@endpush
