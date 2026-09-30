<?php

declare(strict_types=1);

namespace App\Modules\Users\Providers;

use App\Services\Users\AuthService;
use App\Services\Users\RegistrationService;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Пользователи»: регистрация, вход, сессии, 2FA, OAuth, рефералка.
 */
class UsersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegistrationService::class);
        $this->app->singleton(AuthService::class);
    }
}
