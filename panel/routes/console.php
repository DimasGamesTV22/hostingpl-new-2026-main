<?php

declare(strict_types=1);

use App\Console\Commands\SchedulerCommand;
use App\Console\Commands\WsServerCommand;
use App\Services\Billing\BillingService;
use App\Services\Backups\BackupService;
use App\Services\Games\GameManager;
use App\Services\Nodes\NodeManager;
use App\Services\Servers\ServerReaper;
use App\Services\Servers\SchedulerRunner;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Консольные команды
|--------------------------------------------------------------------------
*/

Schedule::command(SchedulerCommand::class, ['task' => 'tick'])
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(SchedulerCommand::class, ['task' => 'billing'])
    ->hourlyAt(5)
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(SchedulerCommand::class, ['task' => 'metrics-aggregate'])
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(SchedulerCommand::class, ['task' => 'public-status'])
    ->everyTwoMinutes()
    ->withoutOverlapping();

Schedule::command(SchedulerCommand::class, ['task' => 'backups'])
    ->hourlyAt(20)
    ->withoutOverlapping();

Schedule::command(SchedulerCommand::class, ['task' => 'rotate'])
    ->dailyAt('04:30')
    ->withoutOverlapping();

// ── Планировщик игровых серверов (cron пользователей) ──────────────────────
Schedule::call(fn () => app(SchedulerRunner::class)->runDue())
    ->everyMinute()
    ->name('gd:server-schedules')
    ->withoutOverlapping();

// ── Автопродление/проверка подписок ─────────────────────────────────────────
Schedule::call(function (BillingService $billing) {
    $stats = $billing->processDueCharges();
    logger()->info('gamedock: billing tick', $stats);
})->everyTenMinutes()->name('gd:billing');

// ── Удаление «мёртвых» серверов ────────────────────────────────────────────
Schedule::call(fn () => app(ServerReaper::class)->reap())
    ->everyFifteenMinutes()
    ->name('gd:reaper');

// ── Ротация логов панели и старых логов установки ───────────────────────────
Schedule::call(function () {
    $path = storage_path('app/private/logs-servers');
    if (! is_dir($path)) {
        return;
    }

    $days = (int) setting('hosting.logs.keep_days', 30);

    foreach (glob($path.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $files = glob($dir.'/*.log') ?: [];
        $rotated = glob($dir.'/*.log.*') ?: [];

        foreach (array_merge($files, $rotated) as $file) {
            if (filemtime((string) $file) < now()->subDays($days)->timestamp) {
                @unlink((string) $file);
            }
        }
    }
})->daily()->name('gd:log-cleanup');

// ── Синхронизация нод ───────────────────────────────────────────────────────
Schedule::call(fn () => app(NodeManager::class)->syncRuntimes())
    ->everyTenMinutes()
    ->name('gd:node-sync');

// ── Проверка наличия обновлений панели ─────────────────────────────────────
Schedule::call(function () {
    if (setting_bool('hosting.updates.check', true)) {
        app(GameManager::class)->checkForUpdates();
    }
})->weeklyOn(1, '05:00')->name('gd:update-check');

// ── Очистка старых сессий и токенов ─────────────────────────────────────────
Schedule::call(function () {
    \App\Models\UserSession::where('last_activity_at', '<', now()->subDays(30))->delete();
    \App\Models\LoginCode::where('expires_at', '<', now()->subDay())->delete();
    \App\Models\AuditLog::where('created_at', '<', now()->subDays(180))->delete();
    \App\Models\WebhookDelivery::where('created_at', '<', now()->subDays(30))->delete();
    \App\Models\NodeHealthLog::where('created_at', '<', now()->subDays(30))->delete();
})->dailyAt('03:00')->name('gd:cleanup');
