{{-- Вкладка «Бэкапы» (заглушка — загружается список аяксом) --}}
@php $server = $server ?? null; @endphp

@include('panel.servers.partials.backups', ['server' => $server])
