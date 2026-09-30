<?php

declare(strict_types=1);

namespace App\Modules\Promo\Providers;

use App\Services\Promo\PromoService;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Промокоды»: скидки, бонусы, дни аренды, генерация кодов для акций.
 */
class PromoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromoService::class);
    }
}
