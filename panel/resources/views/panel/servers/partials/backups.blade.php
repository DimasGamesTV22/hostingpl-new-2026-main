@php
    /**
     * Список бэкапов. Ожидает: $server
     * Для вкладки на странице сервера подгружает аяксом.
     */
    $standalone = ! isset($snapshots);
@endphp

<div class="card" x-data="backupList(@js($server->id))">
    <div class="card-header">
        <h2 class="card-title">
            @include('partials.icon', ['name' => 'archive', 'class' => 'w-5 h-5'])
            {{ __('backups.title') }}
        </h2>

        <form method="POST" action="{{ route('panel.server.backups.store', $server) }}"
              onsubmit="return confirm('Создать бэкап? Это может занять время и место на диске.')">
            @csrf
            <button class="btn btn-primary btn-sm">{{ __('backups.create') }}</button>
        </form>
    </div>

    <div class="card-body" x-show="loading" x-cloak>
        <p class="text-sm text-ink-400">{{ __('common.loading') }}…</p>
    </div>

    <div x-show="!loading && snapshots.length === 0" x-cloak
         class="px-5 py-10 text-center text-sm text-ink-400">
        {{ __('backups.empty') }}
    </div>

    <div class="overflow-x-auto" x-show="!loading && snapshots.length > 0" x-cloak>
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('common.name') }}</th>
                    <th>{{ __('common.type') }}</th>
                    <th>{{ __('common.size') }}</th>
                    <th>{{ __('common.created') }}</th>
                    <th class="w-40"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="snap in snapshots" :key="snap.uuid">
                    <tr>
                        <td>
                            <div class="font-medium" x-text="snap.name"></div>
                            <div x-show="snap.error" class="text-xs text-red-400" x-text="snap.error"></div>
                        </td>
                        <td>
                            <span class="badge-gray" x-text="snap.type"></span>
                        </td>
                        <td class="text-ink-400 tabular-nums" x-text="formatBytes(snap.size)"></td>
                        <td class="text-ink-400 text-xs" x-text="timeAgo(snap.created_at)"></td>
                        <td>
                            <div class="flex items-center gap-1 justify-end">
                                <button @click="toggleLock(snap)"
                                        class="btn btn-ghost btn-sm"
                                        :title="snap.locked ? 'Снять защиту' : 'Защитить от удаления'"
                                        :class="snap.locked && 'text-amber-400'">
                                    @include('partials.icon', ['name' => 'lock', 'class' => 'w-4 h-4'])
                                </button>

                                <button @click="restore(snap)" class="btn btn-ghost btn-sm"
                                        title="{{ __('backups.restore') }}">
                                    @include('partials.icon', ['name' => 'refresh', 'class' => 'w-4 h-4'])
                                </button>

                                <form @submit="remove(snap, $event)" x-show="!snap.locked">
                                    <button class="btn btn-ghost btn-sm text-red-400" title="{{ __('common.delete') }}">
                                        @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <div class="px-5 py-3 border-t border-ink-800 text-xs text-ink-500">
        {{ __('backups.quota', ['used' => ($snapshots ?? collect())->count(), 'quota' => $quota ?? '—']) }}
        · Расписание: {{ __('backups.schedules.' . setting('hosting.backups.default_schedule', 'daily')) }}
    </div>

    <div x-ref="toast" x-cloak x-show="toast" x-transition
         class="fixed bottom-4 right-4 z-50 px-4 py-3 rounded-lg text-sm shadow-glow"
         :class="toastClass" x-text="toast"></div>
</div>

@push('scripts')
<script>
function backupList(serverId) {
    return {
        snapshots: @json(isset($snapshots) ? $snapshots->map(fn($s) => [
            'uuid' => $s->uuid, 'name' => $s->name, 'type' => $s->type,
            'size' => (int) $s->size_bytes, 'locked' => (bool) $s->locked,
            'error' => $s->error, 'created_at' => $s->created_at?->toIso8601String(),
        ]) : []),
        loading: {{ isset($snapshots) ? 'false' : 'true' }},
        toast: '', toastClass: 'bg-ink-800 border border-ink-700 text-ink-100',

        async load() {
            try {
                const res = await fetch(`/panel/servers/${serverId}/backups`, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json();
                this.snapshots = data.data ?? data.snapshots ?? [];
            } catch (e) {
                // остаётся пустым
            } finally {
                this.loading = false;
            }
        },

        notify(message, ok = true) {
            this.toast = message;
            this.toastClass = ok
                ? 'bg-emerald-500/20 border border-emerald-500/40 text-emerald-100'
                : 'bg-red-500/20 border border-red-500/40 text-red-100';
            setTimeout(() => (this.toast = ''), 4000);
        },

        async toggleLock(snap) {
            const res = await fetch(`/panel/servers/${serverId}/backups/${snap.uuid}/toggle`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            });

            if (res.ok) {
                const data = await res.json();
                snap.locked = data.locked;
                this.notify(data.locked ? 'Защищено от удаления' : 'Защита снята');
            }
        },

        async restore(snap) {
            if (!confirm('Восстановить «' + snap.name + '»? Текущие файлы будут заменены.')) return;
            if (!confirm('Точно? Перед восстановлением будет создан страховочный бэкап.')) return;

            const res = await fetch(`/panel/servers/${serverId}/backups/${snap.uuid}/restore`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ confirm: '1' }),
            });

            this.notify(res.ok ? 'Восстановление запущено' : 'Ошибка восстановления', res.ok);
        },

        async remove(snap, event) {
            event.preventDefault();
            if (!confirm('Удалить бэкап «' + snap.name + '»?')) return;

            const res = await fetch(`/panel/servers/${serverId}/backups/${snap.uuid}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            });

            if (res.ok) {
                this.snapshots = this.snapshots.filter(s => s.uuid !== snap.uuid);
                this.notify('Бэкап удалён');
            } else {
                this.notify('Не удалось удалить', false);
            }
        },

        formatBytes(bytes) {
            if (!bytes) return '0 Б';
            const units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
            const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
            return (bytes / Math.pow(1024, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i];
        },

        timeAgo(iso) {
            if (!iso) return '';
            const diff = (Date.now() - new Date(iso).getTime()) / 1000;
            if (diff < 60) return 'только что';
            if (diff < 3600) return Math.floor(diff / 60) + ' мин назад';
            if (diff < 86400) return Math.floor(diff / 3600) + ' ч назад';
            return Math.floor(diff / 86400) + ' дн назад';
        },

        init() { if (this.loading) this.load(); },
    };
}
</script>
@endpush
