@php
    /**
     * Файловый менеджер. Ожидает:
     * $server, $path, $entries, $breadcrumbs, $error, $canWrite, $usage
     * Необязательно: $base — базовый URL списка (по умолчанию вкладка на странице сервера)
     */
    $base = $base ?? '?tab=files';
@endphp

<div class="space-y-4" x-data="fileManager(@js($canWrite))">
    @if ($error)
        <div class="card p-4 border-red-500/40 text-red-300 text-sm">{{ $error }}</div>
    @endif

    <div class="card">
        <!-- Панель инструментов -->
        <div class="card-header flex-wrap gap-2">
            <div class="flex items-center gap-1 text-sm min-w-0 overflow-x-auto scrollbar-none">
                @foreach ($breadcrumbs as $i => $crumb)
                    @if ($i > 0)
                        <span class="text-ink-600">/</span>
                    @endif
                    <a href="{{ $base }}&path={{ urlencode($crumb['path']) }}"
                       @class(['whitespace-nowrap', 'text-brand-400' => $i === count($breadcrumbs) - 1, 'text-ink-300 hover:text-ink-100' => $i !== count($breadcrumbs) - 1])>
                        {{ $crumb['name'] }}
                    </a>
                @endforeach
            </div>

            <div class="flex items-center gap-2 ml-auto">
                <div class="relative hidden sm:block">
                    <input type="search" class="input !py-1 !text-xs w-48 pl-8"
                           placeholder="{{ __('files.search_placeholder') }}"
                           @keydown.enter="window.location.href = `?tab=files&path={{ urlencode($path) }}`"
                           x-ref="search">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-ink-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                </div>

                @if ($canWrite)
                    <label class="btn btn-secondary btn-sm cursor-pointer">
                        @include('partials.icon', ['name' => 'upload', 'class' => 'w-4 h-4'])
                        {{ __('common.upload') }}
                        <input type="file" class="hidden" x-ref="upload" @change="upload($event)">
                    </label>

                    <button @click="prompt('{{ __('files.new_folder') }}').then(name => name && createDir(name))"
                            class="btn btn-secondary btn-sm">
                        @include('partials.icon', ['name' => 'folder', 'class' => 'w-4 h-4'])
                        <span class="hidden sm:inline">{{ __('files.new_folder') }}</span>
                    </button>
                @endif

                <a href="{{ route('panel.server.files', $server) }}" class="btn btn-ghost btn-sm"
                   title="{{ __('common.refresh') }}">
                    @include('partials.icon', ['name' => 'refresh', 'class' => 'w-4 h-4'])
                </a>
            </div>
        </div>

        <!-- Список -->
        @if (empty($entries))
            <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('files.no_files') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.name') }}</th>
                            <th class="w-28">{{ __('common.size') }}</th>
                            <th class="w-40">{{ __('common.updated') }}</th>
                            <th class="w-32"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($path !== '.')
                            <tr>
                                <td colspan="4">
                                    <a href="{{ $base }}&path={{ urlencode(implode('/', array_slice(explode('/', trim($path, '/')), 0, -1)) ?: '.') }}"
                                       class="flex items-center gap-2 text-brand-400">
                                        @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
                                        {{ __('files.root') }}
                                    </a>
                                </td>
                            </tr>
                        @endif

                        @foreach ($entries as $entry)
                            <tr x-data="{ busy: false }">
                                <td>
                                    @if ($entry['type'] === 'dir')
                                    <a href="{{ $base }}&path={{ urlencode($entry['path']) }}"
                                       class="flex items-center gap-2 font-medium text-brand-400 hover:text-brand-300">
                                        @include('partials.icon', ['name' => 'folder', 'class' => 'w-4 h-4 shrink-0'])
                                        <span class="truncate">{{ $entry['name'] }}</span>
                                    </a>
                                @else
                                    <a href="{{ route('panel.server.files.read', $server) }}?path={{ urlencode($entry['path']) }}"
                                           class="flex items-center gap-2 hover:text-brand-300">
                                            @include('partials.icon', ['name' => 'file', 'class' => 'w-4 h-4 shrink-0 text-ink-500'])
                                            <span class="truncate">{{ $entry['name'] }}</span>
                                        </a>
                                    @endif
                                </td>

                                <td class="text-ink-400 tabular-nums">{{ bytes_human($entry['size']) }}</td>
                                <td class="text-ink-400 text-xs">{{ date('d.m.Y H:i', $entry['modified']) }}</td>

                                <td>
                                    <div class="flex items-center gap-1 justify-end">
                                        @if ($entry['type'] === 'file')
                                            <a href="{{ route('panel.server.files.download', $server) }}?path={{ urlencode($entry['path']) }}"
                                               class="btn btn-ghost btn-sm" title="{{ __('common.download') }}">
                                                @include('partials.icon', ['name' => 'download', 'class' => 'w-4 h-4'])
                                            </a>
                                        @endif

                                        @if ($canWrite)
                                            <button @click="rename('{{ addslashes($entry['path']) }}', @js($entry['name']))"
                                                    class="btn btn-ghost btn-sm" title="{{ __('files.rename') }}">
                                                @include('partials.icon', ['name' => 'file', 'class' => 'w-4 h-4'])
                                            </button>

                                            <button @click="remove('{{ addslashes($entry['path']) }}', @js($entry['name']), '{{ $entry['type'] === 'dir' ? '1' : '0' }}')"
                                                    class="btn btn-ghost btn-sm text-red-400" title="{{ __('common.delete') }}">
                                                @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <!-- Использование диска -->
        <div class="px-5 py-3 border-t border-ink-800">
            <div class="flex justify-between text-xs text-ink-400 mb-1.5">
                <span>{{ __('files.usage', ['used' => mb_gb($usage['used']), 'total' => mb_gb($usage['total'])]) }}</span>
                <span class="tabular-nums">{{ $usage['total'] > 0 ? round($usage['used'] / $usage['total'] * 100) : 0 }}%</span>
            </div>
            <div class="h-1.5 rounded-full bg-ink-800 overflow-hidden">
                @php $pct = $usage['total'] > 0 ? min(100, $usage['used'] / $usage['total'] * 100) : 0; @endphp
                <div @class(['h-full', 'bg-emerald-500' => $pct < 70, 'bg-amber-500' => $pct >= 70 && $pct < 90, 'bg-red-500' => $pct >= 90])
                     style="width: {{ $pct }}%"></div>
            </div>
        </div>
    </div>

    <div x-ref="toast" x-cloak x-show="toast" x-transition
         class="fixed bottom-4 right-4 z-50 px-4 py-3 rounded-lg text-sm shadow-glow"
         :class="toastClass" x-text="toast"></div>
</div>

@push('scripts')
<script>
function fileManager(canWrite) {
    return {
        canWrite,
        toast: '', toastClass: 'bg-ink-800 border border-ink-700 text-ink-100',

        notify(message, ok = true) {
            this.toast = message;
            this.toastClass = ok
                ? 'bg-emerald-500/20 border border-emerald-500/40 text-emerald-100'
                : 'bg-red-500/20 border border-red-500/40 text-red-100';
            setTimeout(() => (this.toast = ''), 4000);
        },

        async send(url, body) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(body),
            });

            const data = await res.json().catch(() => ({}));
            return { ok: res.ok, data };
        },

        async createDir(name) {
            const { ok, data } = await this.send(@js(route('panel.server.files.mkdir', $server)), {
                path: '{{ addslashes(rtrim($path, '/')) }}/' + name,
            });

            if (ok) { this.notify('Папка создана'); setTimeout(() => location.reload(), 600); }
            else this.notify(data.message || 'Ошибка', false);
        },

        async rename(path, currentName) {
            const name = await prompt('Новое имя', currentName);
            if (!name || name === currentName) return;

            const { ok, data } = await this.send(@js(route('panel.server.files.rename', $server)), {
                from: path, to: path.replace(/\/[^/]+$/, '') + '/' + name,
            });

            if (ok) { this.notify('Переименовано'); setTimeout(() => location.reload(), 600); }
            else this.notify(data.message || 'Ошибка', false);
        },

        async remove(path, name, isDir) {
            if (!confirm('Удалить «' + name + '»?')) return;

            const { ok, data } = await this.send(@js(route('panel.server.files.delete', $server)), {
                path, recursive: isDir === '1',
            });

            if (ok) { this.notify('Удалено'); setTimeout(() => location.reload(), 600); }
            else this.notify(data.message || 'Ошибка', false);
        },

        async upload(event) {
            const file = event.target.files[0];
            if (!file) return;

            this.notify('Загрузка ' + file.name + '…');

            const reader = new FileReader();
            reader.onload = async () => {
                const content = reader.result.split(',')[1];
                const path = '{{ addslashes(rtrim($path, '/')) }}/' + file.name;

                const { ok, data } = await this.send(@js(route('panel.server.files.upload', $server)), {
                    path, content,
                });

                if (ok) { this.notify('Загружено'); setTimeout(() => location.reload(), 600); }
                else this.notify(data.message || 'Ошибка загрузки', false);

                event.target.value = '';
            };

            reader.readAsDataURL(file);
        },
    };
}
</script>
@endpush
