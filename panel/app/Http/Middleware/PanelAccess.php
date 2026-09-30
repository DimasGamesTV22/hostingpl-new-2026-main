<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ в личный кабинет: пользователь активен, телефон подтверждён,
 * 2FA настроена (если требуется по роли).
 */
class PanelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->status !== \App\Models\User::STATUS_ACTIVE) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', __('auth.account_blocked'));
        }

        $user->touchActivity();

        return $next($request);
    }
}
