@extends('layouts.dashboard')

@section('title', __('files.edit') . ' — ' . $server->name)

@section('content')
    @php
        $parent = $path !== '' && str_contains($path, '/')
            ? substr($path, 0, strrpos($path, '/'))
            : '.';
    @endphp

    <div class="mb-6">
        <a href="{{ route('panel.server.files', [$server, 'path' => $parent]) }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('files.title') }}
        </a>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-bold font-mono truncate">{{ $path }}</h1>
                <p class="text-xs text-ink-400 mt-1">
                    {{ __('common.size') }}: {{ bytes_human($size) }}
                    · {{ $language }}
                    @if ($binary) · <span class="text-amber-400">{{ __('files.binary_file') }}</span> @endif
                </p>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('panel.server.files.download', [$server, 'path' => $path]) }}"
                   class="btn btn-ghost btn-sm">
                    @include('partials.icon', ['name' => 'download', 'class' => 'w-4 h-4'])
                    {{ __('common.download') }}
                </a>
            </div>
        </div>
    </div>

    <div class="card" x-data="codeEditor(@js($canWrite))">
        <div class="card-body">
            @unless ($canWrite)
                <div class="mb-4 px-4 py-3 rounded-lg bg-ink-800 border border-ink-700 text-sm text-ink-300">
                    {{ $binary ? __('files.binary_file') : __('files.read_only_hint') }}
                </div>
            @endunless

            <textarea name="content"
                      class="w-full h-[60vh] rounded-lg bg-ink-900 border border-ink-700 text-ink-100 text-[13px] font-mono
                             leading-relaxed p-4 focus:border-brand-500 focus:ring-1 focus:ring-brand-500/50
                             focus:outline-none resize-y"
                      spellcheck="false"
                      x-ref="editor"
                      @input="dirty = true; $el.setAttribute('data-dirty', 1)"
                      @disabled="! canWrite">{{ $content }}</textarea>

            @if ($canWrite)
                <div class="mt-4 flex items-center gap-2">
                    <button @click="save" class="btn btn-primary btn-sm" :disabled="busy || ! dirty">
                        @include('partials.icon', ['name' => 'check', 'class' => 'w-4 h-4'])
                        {{ __('common.save') }}
                    </button>

                    <button @click="format" class="btn btn-ghost btn-sm" x-show="language === 'json'">
                        Форматировать JSON
                    </button>

                    <span class="text-xs text-ink-500 ml-auto" x-text="status"></span>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
<script>
function codeEditor(canWrite) {
    return {
        canWrite,
        busy: false,
        dirty: false,
        status: '',
        language: @js($language),
        url: @js(route('panel.server.files.save', $server)),
        path: @js($path),

        async save() {
            this.busy = true;
            this.status = 'Сохранение…';

            const res = await fetch(this.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    path: this.path,
                    content: this.$refs.editor.value,
                }),
            });

            const data = await res.json().catch(() => ({}));

            this.busy = false;
            this.dirty = false;
            this.status = res.ok ? (data.message || 'Сохранено') : (data.message || 'Ошибка сохранения');

            if (! res.ok) this.status = this.status + ' — ' + (data.errors?.content?.[0] ?? '');

            setTimeout(() => (this.status = ''), 5000);
        },

        format() {
            const editor = this.$refs.editor;

            try {
                editor.value = JSON.stringify(JSON.parse(editor.value), null, 4);
                this.dirty = true;
                this.status = 'JSON отформатирован';
            } catch (e) {
                this.status = 'Некорректный JSON: ' + e.message;
            }
        },

        init() {
            this.$refs.editor.addEventListener('beforeinput', () => (this.dirty = true));
        },
    };
}
</script>
@endpush
