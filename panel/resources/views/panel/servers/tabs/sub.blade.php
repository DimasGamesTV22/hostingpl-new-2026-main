{{-- Вкладка «Доп. пользователи» --}}
@php $server = $server ?? null; @endphp

@include('panel.servers.partials.sub-accounts', [
    'server' => $server,
    'accounts' => $server->subAccounts()->get(),
    'roles' => \App\Models\ServerSubAccount::ROLES,
    'permissions' => \App\Models\ServerSubAccount::PERMISSIONS,
    'max' => (int) setting('hosting.sub_accounts.max_per_server', 10),
])
