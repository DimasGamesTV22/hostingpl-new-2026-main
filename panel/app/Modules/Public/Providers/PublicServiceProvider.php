<?php

declare(strict_types=1);

namespace App\Modules\Public\Providers;

use App\Services\Public\PublicStatusService;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Публичная часть»: лендинг, тарифы, страницы, публичный мониторинг, SEO.
 */
class PublicServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PublicStatusService::class);
    }
}
