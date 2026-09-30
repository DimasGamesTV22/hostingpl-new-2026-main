<?php

declare(strict_types=1);

namespace App\Modules\Referral\Providers;

use App\Services\Promo\PromoService;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Реферальная программа»: приглашения, награды, статистика.
 * Логика живёт в PromoService — здесь только точка подключения.
 */
class ReferralServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromoService::class);
    }
}
