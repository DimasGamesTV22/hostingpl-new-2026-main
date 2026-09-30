{{-- Вкладка «Журнал событий» --}}
@php $server = $server ?? null; @endphp

<div class="card">
    <div class="card-header">
        <h2 class="card-title">{{ __('servers.events') }}</h2>
        <span class="text-xs text-ink-500">{{ $server->events()->count() }} записей</span>
    </div>

    @if ($events->isEmpty())
        <div class="px-5 py-10 text-center text-sm text-ink-400">{{ __('common.no_data') }}</div>
    @else
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('common.date') }}</th>
                        <th>{{ __('common.type') }}</th>
                        <th>{{ __('servers.events') }}</th>
                        <th>{{ __('common.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr>
                            <td class="whitespace-nowrap text-ink-400">
                                {{ $event->created_at->format('d.m.Y H:i:s') }}
                                <span class="block text-xs text-ink-600">{{ $event->created_at->diffForHumans() }}</span>
                            </td>
                            <td><span class="badge-gray">{{ $event->type }}</span></td>
                            <td>
                                <div>{{ $event->title }}</div>
                                @if ($event->message)
                                    <div class="text-xs text-ink-400 mt-0.5">{{ Str::limit($event->message, 160) }}</div>
                                @endif
                                @if ($event->context)
                                    <details class="mt-1">
                                        <summary class="text-xs text-ink-500 cursor-pointer">контекст</summary>
                                        <pre class="code mt-1 text-[10px]">{{ json_encode($event->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td><span class="badge-{{ $event->levelColor() }}">{{ $event->level }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="px-5 py-4">{{ $events->links() }}</div>
    @endif
</div>
