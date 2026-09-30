<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\Auditor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход администратора в аккаунт пользователя (имперсонация).
 * Пишет в аудит и подвешивает бейдж в интерфейсе.
 */
class TrackImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $impersonatorId = session('impersonator_id');

        view()->share('impersonating', $impersonatorId ? User::find($impersonatorId) : null);

        return $next($request);
    }

    /** Выполнить вход под пользователем от имени администратора. */
    public static function impersonate(\App\Models\User $target, \App\Models\User $admin): void
    {
        session([
            'impersonator_id' => $admin->id,
            'impersonator_name' => $admin->name,
            'impersonator_role' => $admin->role,
        ]);

        Auth::login($target);

        Auditor::impersonate($target);
    }

    public static function stop(): void
    {
        $admin = \App\Models\User::find(session('impersonator_id'));

        if ($admin) {
            Auth::login($admin);
        }

        session()->forget(['impersonator_id', 'impersonator_name', 'impersonator_role']);
    }
}
