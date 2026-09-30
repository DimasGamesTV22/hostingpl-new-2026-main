<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Services\Payments\CryptoBotGateway;
use App\Services\Payments\ManualGateway;
use App\Services\Payments\TinkoffGateway;
use App\Services\Payments\YooKassaGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Платежи»: ЮKassa, Т-Банк, Криптобот, ручной приём.
 * Новый провайдер добавляется реализацией PaymentGateway + регистрацией здесь.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(YooKassaGateway::class);
        $this->app->singleton(TinkoffGateway::class);
        $this->app->singleton(CryptoBotGateway::class);
        $this->app->singleton(ManualGateway::class);
    }
}
