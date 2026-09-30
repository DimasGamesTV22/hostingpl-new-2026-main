@php
    /**
     * Дополнительные пользователи сервера.
     * Ожидает: $server, $accounts, $roles, $permissions, $max
     */
@endphp

<div class="space-y-4" x-data="subAccounts(@js($server->id))">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                @include('partials.icon', ['name' => 'users', 'class' => 'w-5 h-5'])
                {{ __('servers.sub_accounts.title') }}
            </h2>
            <span class="text-xs text-ink-500">{{ count($accounts) }} / {{ $max }}</span>
        </div>

        @if (! $server->sub_accounts_enabled)
            <div class="px-5 py-3 bg-amber-500/10 border-b border-amber-500/20 text-xs text-amber-200">
                {{ __('servers.errors.sub_accounts_disabled') }}
            </div>
        @endif

        @if (count($accounts) === 0)
            <div class="px-5 py-12 text-center">
                <div class="mx-auto w-14 h-14 grid place-items-center rounded-xl bg-ink-800 text-ink-500 mb-4">
                    @include('partials.icon', ['name' => 'users', 'class' => 'w-7 h-7'])
                </div>
                <p class="text-sm font-medium">{{ __('servers.sub_accounts.title') }}</p>
                <p class="mt-1 text-xs text-ink-400">{{ __('servers.sub_accounts.add') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('common.name') }}</th>
                            <th class="w-40">{{ __('servers.sub_accounts.role') }}</th>
                            <th>{{ __('servers.sub_accounts.permissions') }}</th>
                            <th class="w-32">{{ __('servers.sub_accounts.last_seen') }}</th>
                            <th class="w-28"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $account->name }}</div>
                                    <div class="text-xs text-ink-400">{{ $account->email }}</div>
                                </td>
                                <td>
                                    <select class="select !py-1 !text-xs"
                                            @change="save({{ $account->id }}, { role: $event.target.value })">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role }}" @selected($account->role === $role)>
                                                {{ __('servers.sub_accounts.roles.' . $role) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($permissions as $key => $label)
                                            @php $on = in_array($key, (array) $account->permissions, true); @endphp
                                            <button type="button"
                                                    class="px-1.5 py-0.5 rounded text-[10px] border transition
                                                           {{ $on
                                                                ? 'bg-brand-500/15 text-brand-300 border-brand-500/30'
                                                                : 'bg-ink-800 text-ink-500 border-ink-700 hover:border-ink-600' }}"
                                                    :class="perm({{ $account->id }}, @js($key)) ? 'bg-brand-500/15 text-brand-300 border-brand-500/30' : 'bg-ink-800 text-ink-500 border-ink-700'"
                                                    @click="toggle({{ $account->id }}, @js($key))"
                                                    title="{{ $label }}">
                                                {{ $label }}
                                            </button>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-xs text-ink-400">
                                    {{ $account->last_seen_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td>
                                    <div class="flex items-center gap-1 justify-end">
                                        <button class="btn btn-ghost btn-sm"
                                                :class="active({{ $account->id }}) ? 'text-emerald-400' : 'text-ink-500'"
                                                @click="toggleActive({{ $account->id }})"
                                                title="{{ __('servers.sub_accounts.disable') }}">
                                            @include('partials.icon', ['name' => 'power', 'class' => 'w-4 h-4'])
                                        </button>
                                        <button class="btn btn-ghost btn-sm text-red-400"
                                                @click="remove({{ $account->id }}, @js($account->name))"
                                                title="{{ __('common.delete') }}">
                                            @include('partials.icon', ['name' => 'trash', 'class' => 'w-4 h-4'])
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Добавление -->
    @can('manageSubAccounts', $server)
        <div class="card" x-data="{ role: 'operator' }">
            <div class="card-header">
                <h2 class="card-title">{{ __('servers.sub_accounts.add') }}</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('panel.server.sub_accounts.store', $server) }}" class="space-y-4">
                    @csrf

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="label">Email</label>
                            <input type="email" name="email" value="{{ old('email') }}" required class="input" maxlength="190">
                            <p class="hint">Если аккаунта нет — создадим с временным паролем.</p>
                            @error('email') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label">{{ __('common.name') }}</label>
                            <input type="text" name="name" value="{{ old('name') }}" required class="input" maxlength="120">
                            @error('name') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label">{{ __('servers.sub_accounts.role') }}</label>
                            <select name="role" class="select" x-model="role">
                                @foreach ($roles as $role)
                                    <option value="{{ $role }}">{{ __('servers.sub_accounts.roles.' . $role) }}</option>
                                @endforeach
                            </select>
                            @error('role') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label">Пароль</label>
                            <input type="text" name="password" value="{{ old('password') }}" class="input" minlength="8"
                                   autocomplete="off" placeholder="необязательно">
                        </div>
                    </div>

                    <div>
                        <label class="label">{{ __('servers.sub_accounts.permissions') }}</label>
                        <div class="grid sm:grid-cols-2 gap-1.5">
                            @foreach ($permissions as $key => $label)
                                <label class="flex items-center gap-2 text-sm text-ink-300">
                                    <input type="checkbox" name="permissions[]" value="{{ $key }}" class="checkbox"
                                           @checked(in_array($key, \App\Models\ServerSubAccount::defaultPermissions(old('role', 'operator')), true))>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <button class="btn btn-primary btn-sm">
                        @include('partials.icon', ['name' => 'plus', 'class' => 'w-4 h-4'])
                        {{ __('common.add') }}
                    </button>
                </form>
            </div>
        </div>
    @endcan
</div>

@push('scripts')
<script>
function subAccounts(serverId) {
    return {
        state: {},

        base: @json(collect($accounts)->mapWithKeys(fn ($a) => [
            $a->id => ['permissions' => $a->permissions, 'is_active' => (bool) $a->is_active],
        ])->all()),

        cache(id) {
            if (!this.state[id]) {
                this.state[id] = JSON.parse(JSON.stringify(this.base[id] ?? { permissions: [], is_active: true }));
            }
            return this.state[id];
        },

        perm(id, key) {
            return this.cache(id).permissions.includes(key);
        },

        active(id) {
            return this.cache(id).is_active;
        },

        async send(url, body, method = 'POST') {
            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(body),
            });
            return res.ok;
        },

        async toggle(id, key) {
            const entry = this.cache(id);
            const index = entry.permissions.indexOf(key);

            if (index >= 0) entry.permissions.splice(index, 1);
            else entry.permissions.push(key);

            const ok = await this.send(
                `/panel/servers/${serverId}/sub-accounts/${id}/update`,
                { permissions: entry.permissions }
            );

            if (!ok) {
                if (index >= 0) entry.permissions.push(key);
                else entry.permissions.splice(index, 1);
            }
        },

        async save(id, data) {
            await this.send(`/panel/servers/${serverId}/sub-accounts/${id}/update`, data);
        },

        async toggleActive(id) {
            const entry = this.cache(id);
            entry.is_active = !entry.is_active;
            await this.save(id, { is_active: entry.is_active ? 1 : 0 });
        },

        async remove(id, name) {
            if (!confirm('Удалить доступ «' + name + '»?')) return;

            const res = await fetch(`/panel/servers/${serverId}/sub-accounts/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
            });

            if (res.ok) location.reload();
        },
    };
}
</script>
@endpush
