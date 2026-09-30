@php
    $user = auth()->user();
@endphp

<footer class="border-t border-ink-800 mt-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-white text-sm">GD</span>
                    <span class="font-semibold">{{ setting('hosting.branding.name', 'GameDock') }}</span>
                </div>
                <p class="text-sm text-ink-400 leading-relaxed">
                    {{ __('footer.description') }}
                </p>
            </div>

            <div>
                <h3 class="text-sm font-semibold mb-3">{{ __('footer.product') }}</h3>
                <ul class="space-y-2 text-sm text-ink-400">
                    <li><a href="{{ route('games') }}" class="hover:text-ink-200">{{ __('nav.games') }}</a></li>
                    <li><a href="{{ route('tariffs') }}" class="hover:text-ink-200">{{ __('nav.tariffs') }}</a></li>
                    <li><a href="{{ route('status') }}" class="hover:text-ink-200">{{ __('nav.status') }}</a></li>
                    <li><a href="{{ route('news') }}" class="hover:text-ink-200">{{ __('nav.news') }}</a></li>
                </ul>
            </div>

            <div>
                <h3 class="text-sm font-semibold mb-3">{{ __('footer.help') }}</h3>
                <ul class="space-y-2 text-sm text-ink-400">
                    <li><a href="{{ route('faq') }}" class="hover:text-ink-200">{{ __('nav.faq') }}</a></li>
                    <li><a href="{{ route('panel.tickets.create') }}" class="hover:text-ink-200">{{ __('nav.support') }}</a></li>
                    @if (setting('hosting.branding.docs_url'))
                        <li><a href="{{ setting('hosting.branding.docs_url') }}" target="_blank" class="hover:text-ink-200">{{ __('nav.docs') }}</a></li>
                    @endif
                </ul>
            </div>

            <div>
                <h3 class="text-sm font-semibold mb-3">{{ __('footer.contacts') }}</h3>
                <ul class="space-y-2 text-sm text-ink-400">
                    <li>{{ setting('hosting.branding.support_email') }}</li>
                    @if (setting('hosting.branding.support_chat_url'))
                        <li>
                            <a href="{{ setting('hosting.branding.support_chat_url') }}" target="_blank" class="hover:text-ink-200">
                                Telegram
                            </a>
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="mt-8 pt-6 border-t border-ink-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-ink-500">
            <p>© {{ date('Y') }} {{ setting('hosting.branding.legal_name', 'GameDock') }}. {{ __('footer.rights') }}</p>
            <div class="flex gap-4">
                <a href="{{ route('legal.terms') }}" class="hover:text-ink-300">{{ __('footer.terms') }}</a>
                <a href="{{ route('legal.privacy') }}" class="hover:text-ink-300">{{ __('footer.privacy') }}</a>
            </div>
        </div>
    </div>
</footer>
