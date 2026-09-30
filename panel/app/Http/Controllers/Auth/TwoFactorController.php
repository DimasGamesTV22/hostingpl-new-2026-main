<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(private readonly RegistrationService $registration) {}

    public function create(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        return view('auth.two-factor', [
            'user' => $user,
            'recovery' => setting_bool('hosting.auth.two_factor.email_fallback', true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $throttleKey = '2fa:'.$user->id.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->with('error', __('auth.errors.too_many_2fa_attempts', [
                'seconds' => RateLimiter::availableIn($throttleKey),
            ]));
        }

        $valid = app(\App\Services\Users\AuthService::class)->verifyTwoFactorCode($user, $request->string('code')->value());

        if (! $valid && setting_bool('hosting.auth.two_factor.email_fallback', true)) {
            $valid = app(\App\Services\Users\AuthService::class)->verifyTwoFactorEmailCode($user, $request->string('code')->value());
        }

        if (! $valid) {
            RateLimiter::hit($throttleKey, 300);

            return back()->with('error', __('auth.errors.invalid_2fa_code'));
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('2fa_user_id');

        app(\App\Services\Users\AuthService::class)->onSuccess($user, $request->ip(), (string) $request->userAgent());

        return redirect()->intended(route('panel.dashboard'));
    }

    public function recover(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['required', 'string', 'max:40']]);

        $ok = app(\App\Services\Users\AuthService::class)->useRecoveryCode($user, $request->string('code')->value());

        if (! $ok) {
            return back()->with('error', __('auth.errors.invalid_recovery_code'));
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('2fa_user_id');

        return redirect()->route('panel.dashboard')->with('warning', __('auth.2fa_recovered'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $throttleKey = '2fa-resend:'.$user->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            return back()->with('error', __('auth.errors.2fa_resend_too_soon', [
                'seconds' => RateLimiter::availableIn($throttleKey),
            ]));
        }

        RateLimiter::hit($throttleKey, 60);

        app(\App\Services\Users\AuthService::class)->sendTwoFactorCode($user);

        return back()->with('success', __('auth.2fa_code_sent'));
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('2fa_user_id');

        return $id ? User::find($id) : null;
    }
}
