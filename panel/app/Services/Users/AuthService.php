<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Audit\Auditor;
use App\Models\IpBan;
use App\Models\LoginCode;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Mail\Mailer;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Вход, выход, сессии, 2FA, защита от брутфорса.
 */
class AuthService
{
    public function __construct(
        private readonly Totp $totp,
        private readonly Mailer $mailer,
    ) {}

    // ── Вход ────────────────────────────────────────────────────────────

    /**
     * Попытка входа по email/паролю.
     *
     * @return array{ok: bool, user: ?User, two_factor: bool, error: ?string}
     */
    public function attempt(string $email, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $fail = static fn (string $error) => [
            'ok' => false, 'user' => null, 'two_factor' => false, 'error' => $error,
        ];

        $ip ??= request()?->ip();
        $userAgent ??= (string) request()?->userAgent();

        if ($ip && IpBan::isBanned($ip, 'login')) {
            $this->recordAttempt($ip, $email, null, false);

            return $fail(__('auth.errors.ip_banned'));
        }

        if ($this->isLockedOut($ip, $email)) {
            $this->recordAttempt($ip, $email, null, false);

            return $fail(__('auth.errors.too_many_attempts', [
                'minutes' => (int) setting('hosting.auth.bruteforce.lockout_minutes', 15),
            ]));
        }

        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();

        if (! $user) {
            $this->recordAttempt($ip, $email, null, false);

            return $fail(__('auth.errors.credentials'));
        }

        if (! Hash::check($password, $user->password)) {
            $this->recordAttempt($ip, $email, $user->id, false);

            Auditor::login($user, request(), false);

            return $fail(__('auth.errors.credentials'));
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            $this->recordAttempt($ip, $email, $user->id, false);

            return $fail(__('auth.errors.account_blocked', ['reason' => $user->block_reason]));
        }

        $this->recordAttempt($ip, $email, $user->id, true);
        $this->clearAttempts($ip, $email);

        // 2FA
        if ($user->two_factor_enabled && $user->two_factor_confirmed) {
            $user->to2faPending();

            return ['ok' => true, 'user' => $user, 'two_factor' => true, 'error' => null];
        }

        $this->onSuccess($user, $ip, $userAgent);

        return ['ok' => true, 'user' => $user, 'two_factor' => false, 'error' => null];
    }

    public function onSuccess(User $user, ?string $ip = null, ?string $userAgent = null): void
    {
        $user->clear2faPending();
        $user->markLogin($ip);

        $this->registerSession($user, $ip, $userAgent);

        Auditor::login($user, request());
    }

    // ── Сессии ──────────────────────────────────────────────────────────

    public function registerSession(User $user, ?string $ip, ?string $userAgent): UserSession
    {
        return UserSession::create([
            'user_id' => $user->id,
            'ip' => $ip,
            'user_agent' => mb_substr((string) $userAgent, 0, 512),
            'device' => UserSession::detectDevice($userAgent),
            'last_activity_at' => now(),
        ]);
    }

    public function activeSessions(User $user)
    {
        return UserSession::where('user_id', $user->id)
            ->active()
            ->orderByDesc('last_activity_at')
            ->get();
    }

    public function revokeSession(User $user, UserSession $session, string $reason = 'user'): bool
    {
        if ($session->user_id !== $user->id) {
            return false;
        }

        $session->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();

        return true;
    }

    public function revokeAll(User $user, ?int $exceptId = null): int
    {
        return UserSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['revoked_at' => now(), 'revoked_reason' => 'revoke_all']);
    }

    // ── 2FA ─────────────────────────────────────────────────────────────

    /** Подготовить настройку 2FA (секрет + QR). */
    public function startTwoFactorSetup(User $user): array
    {
        $secret = $user->two_factor_secret ?: $this->totp->generateSecret();

        $user->forceFill(['two_factor_secret' => $secret])->saveQuietly();

        $issuer = (string) setting('hosting.branding.name', 'GameDock');
        $account = $user->email;

        return [
            'secret' => $secret,
            'url' => $this->totp->otpauthUrl($secret, $account, $issuer),
            'qr' => $this->qrCodeUrl($this->totp->otpauthUrl($secret, $account, $issuer)),
            'manual' => $this->totp->base32Encode($secret),
        ];
    }

    public function confirmTwoFactor(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! $secret || ! $this->totp->verify($secret, $code)) {
            return false;
        }

        $recovery = $this->totp->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed' => true,
            'two_factor_enabled_at' => now(),
            'two_factor_recovery' => json_encode($recovery),
            'two_factor_2fa_pending_until' => null,
        ])->save();

        return true;
    }

    public function disableTwoFactor(User $user, string $password): bool
    {
        if (! Hash::check($password, (string) $user->password)) {
            return false;
        }

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_confirmed' => false,
            'two_factor_secret' => null,
            'two_factor_recovery' => null,
        ])->save();

        return true;
    }

    public function verifyTwoFactorCode(User $user, string $code): bool
    {
        if (! $user->two_factor_enabled) {
            return true;
        }

        return (bool) $user->two_factor_secret
            && $this->totp->verify($user->two_factor_secret, $code);
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->twoFactorRecoveryCodes();

        if (! in_array(mb_strtolower(trim($code)), array_map('mb_strtolower', $codes), true)) {
            return false;
        }

        $remaining = array_values(array_filter(
            $codes,
            static fn (string $c) => ! hash_equals($c, mb_strtolower(trim($code))),
        ));

        $user->forceFill(['two_factor_recovery' => json_encode($remaining)])->save();

        return true;
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->totp->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery' => json_encode($codes)])->save();

        return $codes;
    }

    /** Отправить код 2FA на email (запасной способ). */
    public function sendTwoFactorCode(User $user): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        LoginCode::create([
            'user_id' => $user->id,
            'code_hash' => LoginCode::hash($code),
            'purpose' => LoginCode::PURPOSE_LOGIN_2FA,
            'expires_at' => now()->addMinutes(10),
            'ip' => request()?->ip(),
        ]);

        return $this->mailer->to($user->email)
            ->subject(__('emails.2fa_subject', ['name' => setting('hosting.branding.name', 'GameDock')]))
            ->body(view('emails.2fa', ['user' => $user, 'code' => $code])->render());
    }

    public function verifyTwoFactorEmailCode(User $user, string $code): bool
    {
        $record = LoginCode::where('user_id', $user->id)
            ->where('purpose', LoginCode::PURPOSE_LOGIN_2FA)
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if (! $record || ! $record->isValid()) {
            return false;
        }

        if (! hash_equals($record->code_hash, LoginCode::hash($code))) {
            $record->increment('attempts');

            return false;
        }

        $record->forceFill(['used_at' => now()])->save();

        return true;
    }

    // ── Защита от брутфорса ─────────────────────────────────────────────

    public function isLockedOut(?string $ip, string $email): bool
    {
        $max = (int) setting('hosting.auth.bruteforce.max_attempts', 5);
        $decay = (int) setting('hosting.auth.bruteforce.decay_minutes', 15);
        $hash = ip_hash($ip);

        $failures = \Illuminate\Support\Facades\DB::table('login_attempts')
            ->where('ip_hash', $hash)
            ->where('successful', false)
            ->where('created_at', '>=', now()->subMinutes($decay))
            ->count();

        $byEmail = \Illuminate\Support\Facades\DB::table('login_attempts')
            ->where('email', mb_strtolower(trim($email)))
            ->where('successful', false)
            ->where('created_at', '>=', now()->subMinutes($decay))
            ->count();

        return $failures >= $max || $byEmail >= $max;
    }

    public function recordAttempt(?string $ip, string $email, ?int $userId, bool $successful): void
    {
        try {
            DB::table('login_attempts')->insert([
                'ip' => $ip,
                'email' => mb_subtolower(trim($email)),
                'user_id' => $userId,
                'ip_hash' => ip_hash($ip),
                'successful' => $successful,
                'user_agent' => mb_substr((string) request()?->userAgent(), 0, 512),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Не блокируем вход из-за ошибки записи статистики
        }
    }

    public function clearAttempts(?string $ip, string $email): void
    {
        try {
            DB::table('login_attempts')
                ->where('ip_hash', ip_hash($ip))
                ->where('email', mb_strtolower(trim($email)))
                ->where('successful', false)
                ->delete();
        } catch (\Throwable) {
            // noop
        }
    }

    /** История входов для страницы безопасности. */
    public function loginHistory(User $user, int $limit = 25)
    {
        return UserSession::where('user_id', $user->id)
            ->orderByDesc('last_activity_at')
            ->limit($limit)
            ->get();
    }

    private function qrCodeUrl(string $otpauthUrl): string
    {
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=10&data=';

        return $url.urlencode($otpauthUrl);
    }
}
