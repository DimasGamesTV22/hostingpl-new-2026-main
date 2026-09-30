<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Users\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $setup = null;

        if (setting_bool('hosting.auth.two_factor.enabled', true) && ! $user->hasTwoFactor()) {
            $setup = $this->auth->startTwoFactorSetup($user);
        }

        return view('panel.security', [
            'user' => $user,
            'setup' => $setup,
            'recoveryCodes' => $user->hasTwoFactor() ? $user->twoFactorRecoveryCodes() : null,
            'twoFactorRequired' => in_array(
                $user->role,
                (array) setting_array('hosting.auth.two_factor.required_for_roles', []),
                true,
            ) && setting_bool('hosting.auth.two_factor.enabled', true),
            'graceExpired' => $user->twoFactorGraceExpired(),
            'sessionCount' => $this->auth->activeSessions($user)->count(),
            'passwordAge' => $user->password_changed_at?->diffForHumans(),
        ]);
    }

    // ── 2FA ─────────────────────────────────────────────────────────────

    public function enable2fa(Request $request): RedirectResponse
    {
        $this->auth->startTwoFactorSetup($request->user());

        return back()->with('success', __('security.messages.2fa_secret_generated'));
    }

    public function confirm2fa(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        if (! $this->auth->confirmTwoFactor($request->user(), $data['code'])) {
            throw ValidationException::withMessages([
                'code' => __('security.errors.invalid_code'),
            ]);
        }

        Auditor::log('security.2fa_enabled', 'Включена двухфакторная аутентификация', $request->user());

        return back()->with('success', __('security.messages.2fa_enabled'));
    }

    public function disable2fa(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        if (! $this->auth->disableTwoFactor($request->user(), $data['password'])) {
            throw ValidationException::withMessages([
                'password' => __('security.errors.wrong_password'),
            ]);
        }

        Auditor::log('security.2fa_disabled', 'Отключена двухфакторная аутентификация', $request->user());

        return back()->with('success', __('security.messages.2fa_disabled'));
    }

    public function regenerateRecovery(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $this->auth->regenerateRecoveryCodes($request->user());

        Auditor::log('security.2fa_recovery', 'Перегенерированы коды восстановления 2FA', $request->user());

        return back()->with('success', __('security.messages.recovery_regenerated'));
    }

    // ── Сессии ──────────────────────────────────────────────────────────

    public function sessions(Request $request): View
    {
        return view('panel.sessions', [
            'sessions' => $this->auth->activeSessions($request->user()),
            'currentIp' => $request->ip(),
            'currentAgent' => mb_substr((string) $request->userAgent(), 0, 512),
        ]);
    }

    public function destroySession(Request $request, UserSession $session): RedirectResponse
    {
        if (! $this->auth->revokeSession($request->user(), $session)) {
            abort(403);
        }

        Auditor::log('security.session_revoked', 'Завершена сессия #'.$session->id, $request->user());

        return back()->with('success', __('security.messages.session_revoked'));
    }

    public function revokeAll(Request $request): RedirectResponse
    {
        $count = $this->auth->revokeAll($request->user());

        Auditor::log('security.sessions_revoked', 'Завершены все сессии ('.$count.')', $request->user());

        return back()->with('success', __('security.messages.sessions_revoked', ['count' => $count]));
    }

    public function loginHistory(Request $request): View
    {
        return view('panel.login-history', [
            'history' => $this->auth->loginHistory($request->user()),
            'attempts' => \Illuminate\Support\Facades\DB::table('login_attempts')
                ->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
        ]);
    }
}
