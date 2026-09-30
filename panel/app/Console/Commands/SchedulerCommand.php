<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\Server;
use App\Services\Monitoring\AlertService;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Nodes\NodeScheduler;
use Illuminate\Console\Command;

/**
 * Планировщик GameDock. Регистрируется в routes/console.php.
 */
class SchedulerCommand extends Command
{
    protected $signature = 'gamedock:scheduler
        {task=run : run|tick|billing|metrics|alerts|nodes|backups|rotate|metrics-aggregate|public-status}
        {--force : Не спрашивать подтверждение}';

    protected $description = 'Фоновые задачи GameDock: биллинг, метрики, алерты, статусы нод';

    public function handle(
        BillingTask $billing,
        MetricsCollector $metrics,
        AlertService $alerts,
        NodeScheduler $scheduler,
    ): int {
        $task = (string) $this->argument('task');

        return match ($task) {
            'tick' => $this->tick($metrics, $alerts, $scheduler),
            'billing' => $this->billing($billing),
            'metrics' => $this->metrics($metrics),
            'metrics-aggregate' => $this->metricsAggregate($metrics),
            'public-status' => $this->publicStatus($metrics),
            'alerts' => $this->alerts($alerts),
            'nodes' => $this->nodes($scheduler),
            'backups' => $this->backups(),
            'rotate' => $this->rotate(),
            default => $this->info('Доступные задачи: tick, billing, metrics, metrics-aggregate, public-status, alerts, nodes, backups, rotate'),
        };
    }

    /** Ежеминутный тик: быстрые проверки. */
    private function tick(MetricsCollector $metrics, AlertService $alerts, NodeScheduler $scheduler): int
    {
        $this->nodes($scheduler);
        $this->metricsAggregate($metrics);
        $this->alerts($alerts);

        return self::SUCCESS;
    }

    /** Статусы нод по heartbeat'ам. */
    private function nodes(NodeScheduler $scheduler): int
    {
        Node::query()->each(function (Node $node) use ($scheduler) {
            $was = $node->status;
            $node->refreshStatus();

            if ($node->status !== $was) {
                $this->line("Нода «{$node->name}»: {$was} → {$node->status}");
            }
        });

        $count = $scheduler->recalculateAll();

        $this->info("Нод обработано: {$count}");

        return self::SUCCESS;
    }

    /** Агрегация метрик (каждые 5 минут). */
    private function metricsAggregate(MetricsCollector $metrics): int
    {
        $rows = $metrics->aggregate(5);
        $this->info("Агрегация метрик: {$rows} серверов");

        return self::SUCCESS;
    }

    /** Быстрая проверка порогов (каждые 2 минуты). */
    private function metrics(MetricsCollector $metrics): int
    {
        $this->metricsAggregate($metrics);
        $this->publicStatus($metrics);

        return self::SUCCESS;
    }

    /** Публичный статус на лендинге. */
    private function publicStatus(MetricsCollector $metrics): int
    {
        $count = $metrics->refreshPublicSnapshots();
        $this->info("Публичный статус обновлён: {$count} серверов");

        return self::SUCCESS;
    }

    /** Проверка алертов (каждую минуту). */
    private function alerts(AlertService $alerts): int
    {
        Server::query()
            ->whereIn('status', [Server::STATUS_RUNNING, Server::STATUS_CRASHED, Server::STATUS_ERROR])
            ->with('user')
            ->limit(500)
            ->each(function (Server $server) use ($alerts) {
                $alerts->checkServerUsage($server);
                $alerts->checkServerDown($server);
                $alerts->checkLowBalance($server);
            });

        Node::query()
            ->where('status', Node::STATUS_OFFLINE)
            ->each(function (Node $node) use ($alerts) {
                $alerts->checkNodeOffline($node);
            });

        return self::SUCCESS;
    }

    private function billing(BillingTask $task): int
    {
        $stats = $task->run();
        $this->info('Биллинг: '.json_encode($stats, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function backups(): int
    {
        $count = app(\App\Services\Backups\BackupService::class)->runScheduled();
        $this->info("Создано бэкапов: {$count}");

        return self::SUCCESS;
    }

    private function rotate(): int
    {
        $total = 0;

        Server::query()
            ->active()
            ->where('is_frozen', false)
            ->chunkById(100, function ($servers) use (&$total) {
                $service = app(\App\Services\Backups\BackupService::class);

                foreach ($servers as $server) {
                    foreach (['hourly', 'daily', 'weekly', 'monthly'] as $schedule) {
                        $total += $service->rotate($server, $schedule);
                    }
                }
            });

        $this->info("Удалено бэкапов: {$total}");

        return self::SUCCESS;
    }
}
