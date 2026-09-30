<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Users\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('panel.dashboard');
        }

        return view('auth.login', [
            'registrationEnabled' => setting_bool('hosting.auth.registration_enabled', true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->auth->attempt(
            $credentials['email'],
            $credentials['password'],
            $request->ip(),
            (string) $request->userAgent(),
        );

        if (! $result['ok']) {
            Log::info('Неудачный вход', ['email' => $credentials['email'], 'ip' => $request->ip()]);

            throw ValidationException::withMessages([
                'email' => $result['error'],
            ]);
        }

        if ($request->boolean('remember')) {
            Auth::remember($result['user'], (int) setting('hosting.auth.session.lifetime_minutes', 10080));
        }

        $request->session()->regenerate();

        if ($result['two_factor']) {
            $request->session()->put('2fa_user_id', $result['user']->id);

            return redirect()->route('2fa.challenge');
        }

        return redirect()->intended(route('panel.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', __('auth.logged_out'));
    }

    // ── Сброс пароля ────────────────────────────────────────────────────

    public function resetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        $status = \Illuminate\Support\Facades\Password::reset(
            $data,
            function (\App\Models\User $user, string $password) {
                $user->forceFill([
                    'password' => \Illuminate\Support\Facades\Hash::make($password),
                    'password_changed_at' => now(),
                    'remember_token' => \Illuminate\Support\Str::random(60),
                ])->save();

                // Сбрасываем все сессии и 2FA-ожидание
                $this->auth->revokeAll($user);

                \App\Audit\Auditor::log('auth.password_reset', 'Пароль сброшен по ссылке из письма', $user);
            },
        );

        if ($status === \Illuminate\Support\Facades\Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', __('auth.password_reset_success'));
        }

        return back()->with('error', __($status));
    }
}
