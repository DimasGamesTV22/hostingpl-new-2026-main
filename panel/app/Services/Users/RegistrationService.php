<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use App\Services\Mail\Mailer;
use App\Services\Promo\PromoService;
use App\Support\Crypto;
use App\Support\Totp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Регистрация новых пользователей: валидация, промокоды, рефералка,
 * пробный период, код подтверждения email.
 */
class RegistrationService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Totp $totp,
        private readonly ?PromoService $promo = null,
    ) {}

    /**
     * @param  array{
     *   name: string, email: string, password: string, username?: ?string,
     *   referral_code?: ?string, promo_code?: ?string, locale?: string,
     *   ip?: ?string, user_agent?: ?string
     * }  $data
     */
    public function register(array $data): User
    {
        if (! setting_bool('hosting.auth.registration_enabled', true)) {
            throw new \RuntimeException(__('auth.errors.registration_disabled'));
        }

        if (setting_bool('hosting.auth.invite_only', false) && blank($data['invite_code'] ?? null)) {
            throw new \RuntimeException(__('auth.errors.invite_only'));
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $this->uniqueUsername($data['username'] ?? null, $data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => Hash::make($data['password']),
                'role' => User::ROLE_USER,
                'locale' => $data['locale'] ?? app()->getLocale(),
                'register_ip' => $data['ip'] ?? request()?->ip(),
                'terms_accepted_at' => setting_bool('hosting.auth.terms_acceptance', true) ? now() : null,
                'newsletter' => (bool) ($data['newsletter'] ?? false),
            ]);

            // Рефералка
            if (! empty($data['referral_code'])) {
                $this->promo?->attachReferral($user, (string) $data['referral_code']);
            }

            // Пробный период
            $this->grantTrial($user);

            return $user;
        });

        // Приветственное письмо и код подтверждения
        $this->sendVerification($user);
        $this->sendWelcome($user);

        return $user;
    }

    /**
     * Регистрация через OAuth: возвращает существующего или создаёт нового.
     *
     * @param  array{provider: string, provider_id: string, name: string, email: string, avatar?: ?string, token?: ?string}  $profile
     */
    public function registerViaOauth(array $profile): User
    {
        $existing = User::where('email', mb_strtolower($profile['email']))->first();

        if ($existing) {
            $this->linkOauth($existing, $profile);

            return $existing;
        }

        $user = DB::transaction(function () use ($profile) {
            $user = User::create([
                'name' => $profile['name'],
                'username' => $this->uniqueUsername(null, $profile['name']),
                'email' => mb_strtolower($profile['email']),
                // Пароля нет: вход только через OAuth
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
                'role' => User::ROLE_USER,
                'avatar_url' => $profile['avatar'] ?? null,
                'register_ip' => request()?->ip(),
            ]);

            $this->linkOauth($user, $profile);
            $this->grantTrial($user);

            return $user;
        });

        $this->sendWelcome($user);

        return $user;
    }

    private function linkOauth(User $user, array $profile): void
    {
        \App\Models\OAuthAccount::updateOrCreate(
            ['provider' => $profile['provider'], 'provider_id' => $profile['provider_id']],
            [
                'user_id' => $user->id,
                'nickname' => $profile['name'] ?? null,
                'avatar' => $profile['avatar'] ?? null,
                'access_token' => $profile['token'] ?? null,
            ],
        );
    }

    // ── Пробный период ──────────────────────────────────────────────────

    private function grantTrial(User $user): void
    {
        $config = (array) setting('hosting.marketing.trial', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        $days = (int) ($config['days'] ?? 0);

        if ($days <= 0) {
            return;
        }

        $user->forceFill([
            'trial_ends_at' => now()->addDays($days),
        ])->save();
    }

    // ── Верификация ─────────────────────────────────────────────────────

    public function sendVerification(User $user): void
    {
        if ($user->isEmailVerified()) {
            return;
        }

        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(24),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $body = view('emails.verify', [
            'user' => $user,
            'link' => $link,
            'code' => $this->createLoginCode($user),
        ])->render();

        $this->mailer->to($user->email)
            ->subject(__('emails.verify_subject', ['name' => setting('hosting.branding.name', 'GameDock')]))
            ->body($body);
    }

    /** Шестизначный код для подтверждения без перехода по ссылке. */
    public function createLoginCode(User $user): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        \App\Models\LoginCode::create([
            'user_id' => $user->id,
            'code_hash' => \App\Models\LoginCode::hash($code),
            'purpose' => \App\Models\LoginCode::PURPOSE_VERIFY_EMAIL,
            'expires_at' => now()->addMinutes(30),
            'ip' => request()?->ip(),
        ]);

        return $code;
    }

    public function verifyCode(User $user, string $code): bool
    {
        $record = \App\Models\LoginCode::where('user_id', $user->id)
            ->where('purpose', \App\Models\LoginCode::PURPOSE_VERIFY_EMAIL)
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if (! $record || ! $record->isValid()) {
            return false;
        }

        if (! hash_equals($record->code_hash, \App\Models\LoginCode::hash($code))) {
            $record->increment('attempts');

            return false;
        }

        $record->forceFill(['used_at' => now()])->save();

        $user->forceFill(['email_verified_at' => now()])->save();

        return true;
    }

    public function verifyByLink(User $user, string $hash): bool
    {
        if (! hash_equals(sha1($user->email), $hash)) {
            return false;
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        return true;
    }

    // ── Приветственное письмо ───────────────────────────────────────────

    private function sendWelcome(User $user): void
    {
        $body = view('emails.welcome', [
            'user' => $user,
            'panelUrl' => url('/panel'),
        ])->render();

        $this->mailer->to($user->email)
            ->subject(__('emails.welcome_subject', ['name' => setting('hosting.branding.name', 'GameDock')]))
            ->body($body);
    }

    // ── Вспомогательное ─────────────────────────────────────────────────

    private function uniqueUsername(?string $desired, string $fallback): string
    {
        $base = Str::slug($desired ?: $fallback, '_');
        $base = preg_replace('/[^a-z0-9_]/i', '', (string) $base) ?: 'user';

        $base = mb_substr($base, 0, 32);
        $username = $base;
        $i = 1;

        while (User::where('username', $username)->exists()) {
            $suffix = ++$i;
            $username = mb_substr($base, 0, 30).$suffix;
        }

        return $username;
    }

    /** Истекает ли пробный период. */
    public function trialExpired(User $user): bool
    {
        return $user->trial_ends_at !== null && Carbon::parse($user->trial_ends_at)->isPast();
    }
}
