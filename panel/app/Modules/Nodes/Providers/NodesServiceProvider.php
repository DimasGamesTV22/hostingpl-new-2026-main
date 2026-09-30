<?php

declare(strict_types=1);

namespace App\Modules\Nodes\Providers;

use App\Services\Nodes\NodeManager;
use App\Services\Nodes\NodeScheduler;
use App\Services\Nodes\PortAllocator;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Ноды»: регистрация нод, выбор ноды для сервера, пул портов.
 */
class NodesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NodeManager::class);
        $this->app->singleton(NodeScheduler::class);
        $this->app->singleton(PortAllocator::class);
    }
}
