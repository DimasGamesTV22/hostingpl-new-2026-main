<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerSchedule;
use App\Services\Agent\AgentClient;
use App\Services\Billing\WalletService;
use App\Services\Backups\BackupService;
use Illuminate\Support\Facades\Log;

/**
 * Планировщик задач игровых серверов (cron пользователей).
 * Запускается каждую минуту: находит задачи, у которых подошло время.
 */
class SchedulerRunner
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly BackupService $backups,
    ) {}

    public function runDue(): int
    {
        $schedules = ServerSchedule::due()
            ->with('server', 'server.game', 'server.node')
            ->limit(200)
            ->get();

        $executed = 0;

        foreach ($schedules as $schedule) {
            if (! $schedule->server || $schedule->server->is_frozen) {
                $this->reschedule($schedule);

                continue;
            }

            if (! $schedule->run_on_stopped && ! $schedule->server->isRunning()) {
                $this->reschedule($schedule);

                continue;
            }

            $ok = $this->execute($schedule);
            $executed++;

            $schedule->forceFill([
                'last_run_at' => now(),
                'last_status' => $ok ? 'done' : 'failed',
                'last_output' => mb_substr($ok ? 'OK' : 'Ошибка', 0, 2000),
                'run_count' => (int) $schedule->run_count + 1,
                'failure_count' => (int) $schedule->failure_count + ($ok ? 0 : 1),
                'next_run_at' => $schedule->calculateNextRun(),
            ])->save();
        }

        if ($executed > 0) {
            Log::info("SchedulerRunner: выполнено задач: {$executed}");
        }

        return $executed;
    }

    private function execute(ServerSchedule $schedule): bool
    {
        $server = $schedule->server;
        $node = $server->node;

        if (! $node) {
            return false;
        }

        return match ($schedule->job_type) {
            ServerSchedule::JOB_COMMAND => (function () use ($schedule, $node, $server) {
                $command = (string) data_get($schedule->payload, 'command', '');
                if ($command === '') {
                    return false;
                }

                return $this->agent->writeConsole($node, $server, $command)['ok'];
            })(),

            ServerSchedule::JOB_RESTART => $this->agent->restart($node, $server)['ok'],
            ServerSchedule::JOB_START => $this->agent->start($node, $server)['ok'],
            ServerSchedule::JOB_STOP => $this->agent->stop($node, $server)['ok'],
            ServerSchedule::JOB_UPDATE => $this->agent->updateGame($node, $server)['ok'],
            ServerSchedule::JOB_BACKUP => (function () use ($schedule, $server) {
                $this->backups->create($server, null, [
                    'type' => \App\Models\ServerSnapshot::TYPE_AUTO,
                    'name' => (string) data_get($schedule->payload, 'name', 'scheduled'),
                ]);

                return true;
            })(),

            ServerSchedule::JOB_WEBHOOK => $this->runWebhook($schedule, $server),

            default => false,
        };
    }

    /** HTTP-запрос на произвольный URL (интеграции, вебхуки игровых плагинов). */
    private function runWebhook(ServerSchedule $schedule, Server $server): bool
    {
        $url = (string) data_get($schedule->payload, 'url', '');

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'X-GameDock-Server' => (string) $server->id,
                    'X-GameDock-Secret' => (string) data_get($schedule->payload, 'secret', ''),
                ])
                ->get($url, (array) data_get($schedule->payload, 'params', []));

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Scheduler webhook failed: '.$e->getMessage(), ['schedule' => $schedule->id]);

            return false;
        }
    }

    private function reschedule(ServerSchedule $schedule): void
    {
        $schedule->forceFill(['next_run_at' => $schedule->calculateNextRun()])->save();
    }

    /** Проверка и пересчёт next_run_at для всех задач (раз в сутки). */
    public function recalculateAll(): int
    {
        $count = 0;

        ServerSchedule::where('is_active', true)->chunkById(100, function ($schedules) use (&$count) {
            foreach ($schedules as $schedule) {
                $next = $schedule->calculateNextRun();

                if ($next && ($schedule->next_run_at === null || $schedule->next_run_at->isPast())) {
                    $schedule->forceFill(['next_run_at' => $next])->save();
                    $count++;
                }
            }
        });

        return $count;
    }
}
