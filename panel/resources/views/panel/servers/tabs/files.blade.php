{{-- Вкладка «Файлы» --}}
@php $server = $server ?? null; @endphp

@include('panel.servers.partials.files', [
    'server' => $server,
    'path' => '.',
    'entries' => [],
    'breadcrumbs' => [['name' => __('files.root'), 'path' => '.']],
    'error' => null,
    'canWrite' => auth()->user()->can('writeFiles', $server),
    'usage' => ['used' => $server->disk_used_mb, 'total' => $server->disk_mb],
])
