<?php

use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Http\Middleware\EnforceAdminTwoFactor;
use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\PanelAccess;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackImpersonation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['lang', 'gamedock_theme']);

        $middleware->web(append: [
            SetLocale::class,
            EnsureUserIsVerified::class,
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            'throttle:api',
        ]);

        $middleware->alias([
            'verified' => EnsureUserIsVerified::class,
            '2fa' => EnforceTwoFactor::class,
            'admin.2fa' => EnforceAdminTwoFactor::class,
            'panel.access' => PanelAccess::class,
            'role' => RequireRole::class,
            'agent.auth' => AuthenticateAgent::class,
            'password.confirm' => RequirePassword::class,
            'impersonation' => TrackImpersonation::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson()
            ? null
            : route('login'));

        $middleware->redirectUsersTo('/panel');

        $middleware->throttleApi('120,1');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 401);
            }

            return null;
        });
    })
    ->create();
