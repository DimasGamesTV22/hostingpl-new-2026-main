<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Требует включённую двухфакторную аутентификацию.
 * В течение «grace»-периода (config hosting.auth.two_factor.grace_days)
 * пользователя предупреждают, но пускают.
 */
class EnforceTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! setting_bool('hosting.auth.two_factor.enabled', true)) {
            return $next($request);
        }

        if ($user->hasTwoFactor()) {
            return $next($request);
        }

        if (! $user->twoFactorGraceExpired()) {
            view()->share('twoFactorPending', $user->atLeast(\App\Models\User::ROLE_ADMIN)
                && in_array($user->role, (array) setting_array('hosting.auth.two_factor.required_for_roles', []), true));

            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.2fa_required')], 403);
        }

        return redirect()->route('panel.security')
            ->with('error', __('auth.2fa_required'));
    }
}
