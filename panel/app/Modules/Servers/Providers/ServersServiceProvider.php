<?php

declare(strict_types=1);

namespace App\Modules\Servers\Providers;

use App\Services\Servers\Provisioner;
use App\Services\Servers\SchedulerRunner;
use App\Services\Servers\ServerEventLogger;
use App\Services\Servers\ServerReaper;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Серверы»: провижининг, питание, консоль, файлы, планировщик, уборка.
 */
class ServersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Provisioner::class);
        $this->app->singleton(ServerEventLogger::class);
        $this->app->singleton(ServerReaper::class);
        $this->app->singleton(SchedulerRunner::class);
    }
}
