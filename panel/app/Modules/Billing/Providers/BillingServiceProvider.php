<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentManager;
use App\Services\Billing\WalletService;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Биллинг»: кошелёк, тарифы, начисления, автоостановка при долге.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WalletService::class);
        $this->app->singleton(BillingService::class);
        $this->app->singleton(PaymentManager::class);
    }
}
