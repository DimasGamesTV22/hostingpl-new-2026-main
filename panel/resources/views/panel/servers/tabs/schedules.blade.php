{{-- Вкладка «Планировщик» --}}
@php $server = $server ?? null; @endphp

@include('panel.servers.partials.schedules', [
    'server' => $server,
    'schedules' => $server->schedules()->orderByDesc('is_active')->get(),
    'jobTypes' => (array) setting('hosting.scheduler.job_types'),
    'presets' => [
        ['expression' => '*/30 * * * *', 'label' => 'Каждые 30 минут'],
        ['expression' => '0 */6 * * *', 'label' => 'Каждые 6 часов'],
        ['expression' => '0 4 * * *', 'label' => 'Ежедневно в 04:00'],
        ['expression' => '0 5 * * 1', 'label' => 'По понедельникам в 05:00'],
    ],
])
