<?php

declare(strict_types=1);

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSchedule;
use App\Services\Servers\SchedulerRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function __construct(private readonly SchedulerRunner $runner) {}

    public function index(Request $request, Server $server): View
    {
        $this->authorize('update', $server);

        return view('panel.servers.schedules', [
            'server' => $server,
            'schedules' => $server->schedules()->orderBy('is_active', 'desc')->orderBy('next_run_at')->get(),
            'jobTypes' => (array) setting('hosting.scheduler.job_types'),
            'maxPerServer' => (int) setting('hosting.scheduler.max_per_server', 20),
            'presets' => $this->presets(),
        ]);
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $jobTypes = array_keys((array) setting('hosting.scheduler.job_types'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expression' => ['required', 'string', 'max:120'],
            'job_type' => ['required', Rule::in($jobTypes)],
            'command' => ['nullable', 'string', 'max:2000'],
            'name_option' => ['nullable', 'string', 'max:120'],
            'url' => ['nullable', 'url', 'max:1024'],
            'run_on_stopped' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($server->schedules()->count() >= (int) setting('hosting.scheduler.max_per_server', 20)) {
            return back()->with('error', __('servers.errors.schedule_limit'));
        }

        $payload = match ($data['job_type']) {
            ServerSchedule::JOB_COMMAND => ['command' => $data['command'] ?? ''],
            ServerSchedule::JOB_BACKUP => ['name' => $data['name_option'] ?? 'scheduled'],
            ServerSchedule::JOB_UPDATE => ['build' => $data['name_option'] ?? null],
            ServerSchedule::JOB_WEBHOOK => ['url' => $data['url'] ?? '', 'secret' => bin2hex(random_bytes(8))],
            default => [],
        };

        if ($data['job_type'] === ServerSchedule::JOB_COMMAND && blank($payload['command'])) {
            return back()->withInput()->with('error', __('servers.errors.command_required'));
        }

        $schedule = ServerSchedule::create([
            'server_id' => $server->id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'expression' => $data['expression'],
            'job_type' => $data['job_type'],
            'payload' => $payload,
            'is_active' => $request->boolean('is_active', true),
            'run_on_stopped' => $request->boolean('run_on_stopped', true),
        ]);

        $schedule->forceFill(['next_run_at' => $schedule->calculateNextRun()])->save();

        if ($schedule->next_run_at === null) {
            return back()->with('error', __('servers.errors.bad_cron'));
        }

        return back()->with('success', __('servers.messages.schedule_created', [
            'time' => $schedule->next_run_at->format(config('hosting.locale.datetime_format')),
        ]));
    }

    public function toggle(Server $server, ServerSchedule $schedule): JsonResponse
    {
        $this->authorize('update', $server);

        abort_if($schedule->server_id !== $server->id, 404);

        $schedule->forceFill([
            'is_active' => ! $schedule->is_active,
            'next_run_at' => $schedule->is_active ? null : $schedule->calculateNextRun(),
        ])->save();

        return response()->json(['ok' => true, 'active' => $schedule->is_active]);
    }

    public function run(Server $server, ServerSchedule $schedule): JsonResponse
    {
        $this->authorize('update', $server);

        abort_if($schedule->server_id !== $server->id, 404);

        $schedule->forceFill(['next_run_at' => now()])->save();

        return response()->json(['ok' => true, 'message' => __('servers.messages.schedule_queued')]);
    }

    public function destroy(Server $server, ServerSchedule $schedule): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_if($schedule->server_id !== $server->id, 404);

        $schedule->delete();

        return back()->with('success', __('servers.messages.schedule_deleted'));
    }

    private function presets(): array
    {
        return [
            ['expression' => '*/5 * * * *', 'label' => 'Каждые 5 минут'],
            ['expression' => '*/30 * * * *', 'label' => 'Каждые 30 минут'],
            ['expression' => '0 * * * *', 'label' => 'Каждый час'],
            ['expression' => '0 */6 * * *', 'label' => 'Каждые 6 часов'],
            ['expression' => '0 4 * * *', 'label' => 'Ежедневно в 04:00'],
            ['expression' => '0 0 * * *', 'label' => 'Ежедневно в полночь'],
            ['expression' => '0 5 * * 1', 'label' => 'По понедельникам в 05:00'],
            ['expression' => '0 0 1 * *', 'label' => '1-го числа месяца'],
        ];
    }
}
