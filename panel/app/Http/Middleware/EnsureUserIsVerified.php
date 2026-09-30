<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Требует подтверждённый email. Пропускает, если верификация выключена.
 */
class EnsureUserIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! setting_bool('hosting.auth.require_email_verification', true)) {
            return $next($request);
        }

        if ($user->hasVerifiedEmail()) {
            // Показываем напоминание о верификации, но не блокируем
            if (setting_bool('hosting.auth.trial.enabled', true) && $user->trialActive()) {
                return $next($request);
            }

            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('auth.verify_email_required'),
            ], 403);
        }

        // Оплата и API доступны без подтверждения — иначе не купить подписку
        if ($request->is('billing/*', 'payment/*', 'api/*', 'webhooks/*')) {
            return $next($request);
        }

        return redirect()->route('verification.code')
            ->with('warning', __('auth.verify_email_warning'));
    }
}
