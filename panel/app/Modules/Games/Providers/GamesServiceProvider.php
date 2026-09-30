<?php

declare(strict_types=1);

namespace App\Modules\Games\Providers;

use App\Services\Games\ConfigFileService;
use App\Services\Games\GameManager;
use App\Services\Games\StartupBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Игры»: каталог, шаблоны плагинов, редактор конфигов, сборка команды запуска.
 */
class GamesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GameManager::class);
        $this->app->singleton(ConfigFileService::class);
        $this->app->singleton(StartupBuilder::class);
    }
}
