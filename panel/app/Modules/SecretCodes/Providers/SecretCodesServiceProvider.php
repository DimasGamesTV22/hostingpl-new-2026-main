<?php

declare(strict_types=1);

namespace App\Modules\SecretCodes\Providers;

use App\Services\SecretCodes\SecretCodeResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Секретные коды»: перехват кода в игровом чате и начисление бонуса.
 */
class SecretCodesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecretCodeResolver::class);
    }
}
