<?php

declare(strict_types=1);

namespace App\Modules\Agent\Providers;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentRegistry;
use App\Services\Agent\FileService;
use App\Services\Monitoring\ConsoleBroadcaster;
use App\Services\Monitoring\MetricsCollector;
use App\Services\Monitoring\AlertService;
use App\Services\SecretCodes\SecretCodeResolver;
use App\Services\Servers\ServerEventLogger;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль транспорта панель <-> агент.
 * Здесь же поднимается WSS-сервер, к которому агенты подключаются
 * (консольная команда gamedock:ws-server).
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AgentClient::class, fn ($app) => new AgentClient($app->make(AgentRegistry::class)));
        $this->app->singleton(FileService::class);
        $this->app->singleton(ConsoleBroadcaster::class);
        $this->app->singleton(MetricsCollector::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(SecretCodeResolver::class);
        $this->app->singleton(ServerEventLogger::class);
    }
}
