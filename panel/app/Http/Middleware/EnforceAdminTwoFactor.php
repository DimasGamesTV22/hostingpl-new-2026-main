<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA обязательна для администраторов — без исключений.
 */
class EnforceAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $requiredRoles = (array) setting_array('hosting.auth.two_factor.required_for_roles', ['superadmin', 'admin']);
        $isRequired = $user && setting_bool('hosting.auth.two_factor.enabled', true)
            && in_array($user->role, $requiredRoles, true);

        if (! $isRequired) {
            return $next($request);
        }

        if ($user->hasTwoFactor()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('auth.2fa_required_for_admins'),
                'redirect' => route('panel.security'),
            ], 403);
        }

        return redirect()->route('panel.security')
            ->with('error', __('auth.2fa_required_for_admins'));
    }
}
