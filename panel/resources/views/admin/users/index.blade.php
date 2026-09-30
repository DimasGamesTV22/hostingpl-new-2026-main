@extends('layouts.dashboard')

@section('title', __('admin.users') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('admin.users') }}</h1>
        <p class="mt-1 text-sm text-ink-400">{{ $users->total() }}</p>
    </div>

    <!-- Фильтры -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.users') }}" class="flex flex-wrap gap-3 items-end">
                <div class="relative flex-1 min-w-[220px]">
                    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9"
                           placeholder="Имя, email, username, ID">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-ink-500" fill="none"
                         stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                </div>

                <div>
                    <label class="label">Роль</label>
                    <select name="role" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>{{ $role }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label">{{ __('common.status') }}</label>
                    <select name="status" class="select !py-1.5 !text-xs">
                        <option value="">{{ __('common.all') }}</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>active</option>
                        <option value="blocked" @selected(($filters['status'] ?? '') === 'blocked')>blocked</option>
                    </select>
                </div>

                <label class="flex items-center gap-2 text-sm pb-1.5">
                    <input type="checkbox" name="online" value="1" class="checkbox" @checked($filters['online'] ?? false)>
                    Онлайн
                </label>

                <button class="btn btn-primary btn-sm">{{ __('common.apply_filter') }}</button>
                <a href="{{ route('admin.users') }}" class="btn btn-ghost btn-sm">{{ __('common.reset_filter') }}</a>
            </form>
        </div>
    </div>

    <div class="card" x-data="bulkOps(@js(route('admin.users.bulk')))">
        <!-- Массовые операции -->
        <div x-show="selected.length > 0" x-cloak
             class="flex flex-wrap items-center gap-3 px-5 py-3 bg-brand-500/5 border-b border-ink-800">
            <span class="text-sm">Выбрано: <span class="font-medium" x-text="selected.length"></span></span>

            <select class="select !py-1 !text-xs w-40" x-model="action">
                <option value="block">Заблокировать</option>
                <option value="unblock">Разблокировать</option>
                <option value="role">Сменить роль</option>
                <option value="delete">Удалить</option>
            </select>

            <input type="text" class="input !py-1 !text-xs w-48" x-model="value"
                   :placeholder="action === 'role' ? 'роль' : 'причина'">

            <button class="btn btn-primary btn-sm" @click="run">
                Выполнить
            </button>

            <button class="btn btn-ghost btn-sm" @click="reset">Снять выделение</button>
        </div>

        <form method="POST" action="{{ route('admin.users.bulk') }}">
            @csrf

            @if ($users->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-ink-400">{{ __('common.no_results') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10"></th>
                                <th>{{ __('common.name') }}</th>
                                <th class="w-32">{{ __('nav.balance') }}</th>
                                <th class="w-20">{{ __('admin.servers') }}</th>
                                <th class="w-28">Роль</th>
                                <th class="w-24">{{ __('common.status') }}</th>
                                <th class="w-32">{{ __('common.created') }}</th>
                                <th class="w-16"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="ids[]" value="{{ $user->id }}" class="checkbox"
                                               x-model.number="selected" @change="sync()">
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.users.show', $user) }}"
                                           class="font-medium hover:text-brand-300">{{ $user->name }}</a>
                                        <div class="text-xs text-ink-500">{{ $user->email }}</div>
                                    </td>
                                    <td class="tabular-nums">{{ money($user->balance) }}</td>
                                    <td class="tabular-nums">{{ $user->servers_count }}</td>
                                    <td>
                                        @if ($user->isAdmin() || $user->isStaff())
                                            <span class="badge-indigo">{{ $user->roleLabel() }}</span>
                                        @else
                                            <span class="badge-gray">{{ $user->role }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge-{{ $user->statusColor() }}">{{ $user->statusLabel() }}</span>
                                    </td>
                                    <td class="text-xs text-ink-500">{{ $user->created_at->format('d.m.Y') }}</td>
                                    <td>
                                        <a href="{{ route('admin.users.show', $user) }}" class="btn btn-ghost btn-sm">
                                            @include('partials.icon', ['name' => 'chevron', 'class' => 'w-4 h-4'])
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-4 border-t border-ink-800">{{ $users->links() }}</div>
            @endif
        </form>
    </div>
@endsection

@push('scripts')
<script>
function bulkOps(endpoint) {
    return {
        selected: [],
        action: 'block',
        value: '',

        sync() {
            const ids = Array.from(document.querySelectorAll('input[name="ids[]"]'))
                .filter((el) => el.checked)
                .map((el) => el.value);
            this.selected = ids;
        },

        reset() {
            document.querySelectorAll('input[name="ids[]"]').forEach((el) => (el.checked = false));
            this.selected = [];
        },

        async run() {
            if (!confirm('Выполнить операцию «' + this.action + '» для ' + this.selected.length + ' записей?')) return;

            const body = new FormData();
            body.append('action', this.action);
            body.append('value', this.value);
            this.selected.forEach((id) => body.append('ids[]', id));

            const res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body,
            });

            if (res.ok) {
                location.reload();
            } else {
                alert('Не удалось выполнить операцию');
            }
        },
    };
}
</script>
@endpush
