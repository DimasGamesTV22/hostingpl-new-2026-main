<?php

declare(strict_types=1);

namespace App\Modules\Support\Providers;

use App\Services\Support\TicketService;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Поддержка»: тикеты, отделы, вложения, внутренние заметки.
 */
class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TicketService::class);
    }
}
