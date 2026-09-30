<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Server;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\ServerPolicy;
use App\Policies\TicketPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // Права, используемые во вьюхах и политиках
        Gate::define('admin-access', fn (User $user) => $user->isStaff());
        Gate::define('manage-panel', fn (User $user) => $user->atLeast(User::ROLE_ADMIN));
        Gate::define('superadmin-only', fn (User $user) => $user->isSuperAdmin());
        Gate::define('manage-billing', fn (User $user) => $user->atLeast(User::ROLE_ADMIN));
    }
}
