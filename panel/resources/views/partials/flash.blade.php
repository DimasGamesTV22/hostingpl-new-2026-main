<div class="fixed top-4 right-4 z-[100] space-y-2 w-full max-w-sm" x-data="{ show: true }" x-show="show">
    @foreach (['success', 'error', 'warning', 'info'] as $type)
        @if (session($type))
            @php
                $styles = [
                    'success' => ['border-emerald-500/40 bg-emerald-500/10 text-emerald-200', 'check-circle'],
                    'error' => ['border-red-500/40 bg-red-500/10 text-red-200', 'alert-circle'],
                    'warning' => ['border-amber-500/40 bg-amber-500/10 text-amber-200', 'alert-triangle'],
                    'info' => ['border-sky-500/40 bg-sky-500/10 text-sky-200', 'info'],
                ][$type];
            @endphp
            <div x-data="{ show: true }" x-show="show" x-transition
                 class="flex items-start gap-3 rounded-lg border px-4 py-3 shadow-glow backdrop-blur {{ $styles[0] }}">
                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                    @if ($styles[1] === 'check-circle')
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    @elseif ($styles[1] === 'alert-circle')
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                    @elseif ($styles[1] === 'alert-triangle')
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z"/>
                    @else
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                    @endif
                </svg>
                <div class="flex-1 text-sm">{{ session($type) }}</div>
                <button @click="show = false" class="opacity-50 hover:opacity-100 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        @endif
    @endforeach

    @isset($token_plain)
        <div x-data="{ show: true }" x-show="show" x-transition
             class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 shadow-glow backdrop-blur">
            <div class="text-sm text-amber-200 font-medium mb-2">{{ __('profile.copy_now') }}</div>
            <div class="flex gap-2">
                <code class="flex-1 bg-black/40 rounded px-2 py-1 text-xs break-all select-all">{{ $token_plain }}</code>
                <button type="button" class="btn btn-sm btn-secondary shrink-0"
                        x-data x-on:click="navigator.clipboard.writeText(@js($token_plain))">
                    {{ __('common.copy') }}
                </button>
            </div>
        </div>
    @endisset

    @isset($node_token)
        <div x-data="{ show: true }" x-show="show" x-transition
             class="rounded-lg border border-sky-500/40 bg-sky-500/10 px-4 py-3 shadow-glow backdrop-blur">
            <div class="text-sm text-sky-200 font-medium mb-2">{{ __('nodes.token_saved') }}</div>
            <code class="block bg-black/40 rounded px-2 py-1 text-xs break-all select-all">{{ $node_token }}</code>
        </div>
    @endisset
</div>
