<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Providers;

use App\Services\Monitoring\AlertService;
use App\Services\Monitoring\ConsoleBroadcaster;
use App\Services\Monitoring\MetricsCollector;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Мониторинг»: сбор метрик, живая консоль, алерты, публичный статус.
 */
class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetricsCollector::class);
        $this->app->singleton(ConsoleBroadcaster::class);
        $this->app->singleton(AlertService::class);
    }
}
