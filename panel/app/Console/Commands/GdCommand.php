<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GameTemplate;
use App\Models\Node;
use App\Models\Server;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Games\GameManager;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Nodes\NodeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CLI-управление GameDock: диагностика и обслуживание.
 */
class GdCommand extends Command
{
    protected $signature = 'gamedock
        {action=status : status|doctor|nodes|sync|queue|rotate|revenue|games|tariffs|user|token|prune}
        {--id= : ID объекта (нода, сервер, пользователь)}
        {--email= : Email пользователя}
        {--days=30 : Период в днях}
        {--force : Без подтверждения}';

    protected $description = 'Обслуживание GameDock из консоли';

    public function handle(
        BillingService $billing,
        MetricsCollector $metrics,
        NodeManager $nodes,
        GameManager $games,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'status' => $this->status($metrics),
            'doctor' => $this->doctor(),
            'nodes' => $this->listNodes($nodes),
            'sync' => $this->sync($nodes),
            'queue' => $this->queue(),
            'rotate' => $this->rotate(),
            'revenue' => $this->revenue($billing),
            'games' => $this->games(),
            'tariffs' => $this->tariffs(),
            'user' => $this->user(),
            'token' => $this->token($nodes),
            'prune' => $this->prune(),
            default => $this->error("Неизвестное действие: {$action}"),
        };
    }

    // ── Статус ─────────────────────────────────────────────────────────

    private function status(MetricsCollector $metrics): int
    {
        $this->newLine();
        $this->line('  <fg=green;options=bold>GameDock</> — состояние панели');
        $this->newLine();

        $rows = [
            ['Панель', config('app.url')],
            ['Версия Laravel', app()->version()],
            ['PHP', PHP_VERSION],
            ['Режим нод', node_mode()],
            ['Рантайм', default_runtime()],
            ['Валюта', setting('hosting.billing.currency', 'RUB').' '.setting('hosting.branding.currency_symbol', '₽')],
        ];

        $this->table(['Параметр', 'Значение'], $rows);

        $this->newLine();
        $this->line('  <options=bold>Данные:</>');
        $this->table(
            ['Сущность', 'Всего'],
            [
                ['Пользователи', User::count()],
                ['Серверы', Server::count()],
                ['  из них запущено', Server::where('status', Server::STATUS_RUNNING)->count()],
                ['  ошибок', Server::whereIn('status', [Server::STATUS_ERROR, Server::STATUS_CRASHED])->count()],
                ['Игры', Game::where('is_active', true)->count()],
                ['Тарифы', Tariff::where('is_active', true)->count()],
                ['Ноды', Node::count()],
                ['  онлайн', Node::where('status', Node::STATUS_ONLINE)->count()],
            ],
        );

        return self::SUCCESS;
    }

    // ── Диагностика ────────────────────────────────────────────────────

    private function doctor(): int
    {
        $this->newLine();
        $this->line('  <fg=green;options=bold>Диагностика</>');
        $this->newLine();

        $checks = [
            ['PHP >= 8.2', PHP_VERSION_ID >= 80200, PHP_VERSION],
            ['Расширение PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'да' : 'нет — поставьте php-mysql'],
            ['Расширение Redis', extension_loaded('redis'), extension_loaded('redis') ? 'да' : 'нет (используется predis)'],
            ['Расширение Zip', extension_loaded('zip'), extension_loaded('zip') ? 'да' : 'нет'],
            ['Расширение bcmath', extension_loaded('bcmath'), extension_loaded('bcmath') ? 'да' : 'нет'],
            ['APP_KEY задан', filled(config('app.key')), filled(config('app.key')) ? 'да' : 'нет — php artisan key:generate'],
        ];

        // Соединения
        try {
            DB::connection()->getPdo();
            $checks[] = ['База данных', true, 'подключена'];
        } catch (\Throwable $e) {
            $checks[] = ['База данных', false, $e->getMessage()];
        }

        try {
            \Illuminate\Support\Facades\Redis::connection()->ping();
            $checks[] = ['Redis', true, 'доступен'];
        } catch (\Throwable $e) {
            $checks[] = ['Redis', false, $e->getMessage()];
        }

        // Каталоги
        foreach (['storage/framework/cache', 'storage/framework/sessions', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $path = base_path($dir);
            $checks[] = ["Каталог {$dir}", is_writable($path), is_writable($path) ? 'доступен для записи' : 'нет прав'];
        }

        // Сервисы
        $checks[] = [
            'APP_ENV=production',
            config('app.env') === 'production',
            config('app.env'),
        ];

        $failed = 0;

        foreach ($checks as [$name, $ok, $detail]) {
            $mark = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$mark} {$name} <fg=gray>({$detail})</>");

            if (! $ok) {
                $failed++;
            }
        }

        $this->newLine();

        if ($failed === 0) {
            $this->line('  <fg=green>Всё в порядке.</>');
        } else {
            $this->line("  <fg=yellow>Проблем: {$failed}. Исправьте их перед запуском в продакшене.</>");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ── Ноды ───────────────────────────────────────────────────────────

    private function listNodes(NodeManager $nodes): int
    {
        $nodesList = Node::withCount('servers')->get();

        if ($nodesList->isEmpty()) {
            $this->warn('Ноды не добавлены. Создайте в панели: Админка → Ноды.');
            $this->line('  <fg=gray>php artisan gamedock node-create --help</>');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Имя', 'Рантайм', 'Статус', 'Серверы', 'RAM', 'Диск', 'Регион'],
            $nodesList->map(fn (Node $n) => [
                $n->id,
                $n->name,
                $n->runtime,
                $n->statusLabel(),
                "{$n->servers_count} (max {$n->max_servers})",
                mb_gb($n->freeMemoryMb()).' / '.mb_gb($n->allocatableMemoryMb()),
                mb_gb($n->freeDiskMb()).' / '.mb_gb($n->allocatableDiskMb()),
                $n->region ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function token(NodeManager $nodes): int
    {
        $id = $this->option('id');

        $node = $id ? Node::find($id) : Node::first();

        if (! $node) {
            $this->error('Нода не найдена. Укажите --id.');

            return self::FAILURE;
        }

        if (! $this->confirm("Ротировать токен ноды «{$node->name}»? Агент перестанет подключаться.", $this->option('force'))) {
            return self::SUCCESS;
        }

        $token = $nodes->rotateToken($node);

        $this->info("Новый токен ноды «{$node->name}»:");
        $this->line("  <options=bold>{$token}</>");
        $this->newLine();
        $this->line('  Обновите его на ноде:');
        $this->line("  <fg=gray>sed -i 's/\"token\".*/\"token\": \"{$token}\",/' /etc/gamedock/agent-{$node->id}.json</>");
        $this->line("  <fg=gray>systemctl restart gamedock-agent@{$node->id}</>");

        return self::SUCCESS;
    }

    private function sync(NodeManager $nodes): int
    {
        $count = $nodes->syncRuntimes();

        $this->info("Синхронизировано нод: {$count}");

        foreach (Node::get() as $node) {
            $this->line(sprintf(
                '  #%d %s — %s%s',
                $node->id,
                $node->name,
                $node->statusLabel(),
                $node->last_heartbeat_at ? ' (heartbeat '.$node->last_heartbeat_at->diffForHumans().')' : '',
            ));
        }

        return self::SUCCESS;
    }

    // ── Обслуживание ───────────────────────────────────────────────────

    private function queue(): int
    {
        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();

        $this->table(['Очередь', 'Задач'], [
            ['В работе (jobs)', $pending],
            ['Провалено (failed_jobs)', $failed],
        ]);

        if ($failed > 0 && $this->confirm('Показать проваленные задачи?', false)) {
            $this->table(
                ['ID', 'Подключение', 'Ошибка'],
                DB::table('failed_jobs')->orderByDesc('id')->limit(20)->get()
                    ->map(fn ($job) => [
                        $job->id,
                        $job->connection,
                        mb_substr(json_decode($job->payload, true)['displayName'] ?? $job->exception, 0, 60),
                    ])->all(),
            );
        }

        return self::SUCCESS;
    }

    private function rotate(): int
    {
        $removed = 0;

        Server::query()->active()->chunkById(100, function ($servers) use (&$removed) {
            $service = app(\App\Services\Backups\BackupService::class);

            foreach ($servers as $server) {
                foreach (['hourly', 'daily', 'weekly', 'monthly'] as $schedule) {
                    $removed += $service->rotate($server, $schedule);
                }
            }
        });

        $this->info("Удалено старых бэкапов: {$removed}");

        return self::SUCCESS;
    }

    private function prune(): int
    {
        if (! $this->confirm('Удалить старые логи, метрики и аудит?', $this->option('force'))) {
            return self::SUCCESS;
        }

        $days = (int) $this->option('days');

        $logs = \App\Models\NodeHealthLog::where('created_at', '<', now()->subDays($days))->delete();
        $audit = \App\Models\AuditLog::where('created_at', '<', now()->subDays(180))->delete();
        $hooks = \App\Models\WebhookDelivery::where('created_at', '<', now()->subDays(30))->delete();
        $sessions = \App\Models\UserSession::where('last_activity_at', '<', now()->subDays(30))->delete();

        $this->info(sprintf(
            'Удалено: health-логи %d, аудит %d, вебхуки %d, сессии %d',
            $logs, $audit, $hooks, $sessions,
        ));

        return self::SUCCESS;
    }

    // ── Отчёты ─────────────────────────────────────────────────────────

    private function revenue(BillingService $billing): int
    {
        $days = (int) $this->option('days');
        $report = $billing->revenue($days);

        $this->table(['Показатель', 'Значение'], [
            ["Выручка за {$days} дн.", $report['income_formatted']],
            ['Ожидается к оплате', $report['pending_formatted']],
            ['Серверов всего', $report['servers']],
            ['Активных', $report['active_servers']],
            ['Пользователей', $report['users']],
            ['Средний чек', money($report['arpu'])],
        ]);

        return self::SUCCESS;
    }

    private function games(): int
    {
        $games = Game::withCount('servers')->orderBy('family')->orderBy('name')->get();

        if ($games->isEmpty()) {
            $this->warn('Каталог игр пуст. Выполните: php artisan migrate --force');
            $this->line('  <fg=gray>Или загрузите заново: php artisan migrate:refresh --force</>');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Название', 'Семейство', 'Серверов', 'Слоты', 'Цена/слот', 'Активна'],
            $games->map(fn (Game $g) => [
                $g->slug,
                $g->name,
                $g->family,
                $g->servers_count,
                $g->slots_range,
                money($g->price_per_slot_month),
                $g->is_active ? 'да' : 'нет',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function tariffs(): int
    {
        $tariffs = Tariff::withCount('servers')->orderBy('sort')->get();

        $this->table(
            ['Slug', 'Название', 'Модель', 'Цена', 'Слоты', 'RAM', 'Серверов'],
            $tariffs->map(fn (Tariff $t) => [
                $t->slug,
                $t->name,
                $t->model,
                $t->formattedPrice(),
                $t->slots,
                mb_gb($t->memory_mb),
                $t->servers_count,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function user(): int
    {
        $email = $this->option('email');

        if (! $email) {
            $this->error('Укажите --email');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("Пользователь {$email} не найден");

            return self::FAILURE;
        }

        $this->table(['Поле', 'Значение'], [
            ['ID', $user->id],
            ['Имя', $user->name],
            ['Роль', $user->roleLabel()],
            ['Статус', $user->statusLabel()],
            ['Баланс', money($user->balance)],
            ['Серверов', $user->servers()->count()],
            ['Регистрация', $user->created_at?->format('d.m.Y H:i')],
            ['Последний вход', $user->last_login_at?->diffForHumans() ?? '—'],
            ['2FA', $user->hasTwoFactor() ? 'включена' : 'нет'],
            ['Реферальный код', $user->referral_code],
        ]);

        return self::SUCCESS;
    }
}
