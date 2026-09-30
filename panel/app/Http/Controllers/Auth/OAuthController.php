<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Вход через Google и VK.
 * Реализация на чистом HTTP — без Socialite, чтобы не тянуть лишний пакет.
 */
class OAuthController extends Controller
{
    public function __construct(private readonly RegistrationService $registration) {}

    // ── Google ──────────────────────────────────────────────────────────

    public function redirectGoogle(): RedirectResponse
    {
        $clientId = (string) config('services.google.client_id');

        if (blank($clientId)) {
            return redirect()->route('login')->with('error', __('auth.errors.oauth_not_configured', ['provider' => 'Google']));
        }

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => route('oauth.google.callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => Str::random(32),
            'prompt' => 'select_account',
        ]);

        session(['oauth_state' => session('oauth_state')]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function handleGoogle(): RedirectResponse
    {
        $code = request()->query('code');

        if (blank($code)) {
            return redirect()->route('login')->with('error', __('auth.errors.oauth_failed', ['provider' => 'Google']));
        }

        try {
            $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => route('oauth.google.callback'),
                'grant_type' => 'authorization_code',
            ])->json();

            if (blank($token['access_token'] ?? null)) {
                throw new \RuntimeException($token['error_description'] ?? 'no access token');
            }

            $info = Http::withToken($token['access_token'])
                ->get('https://www.googleapis.com/oauth2/v3/userinfo')
                ->json();

            $user = $this->registration->registerViaOauth([
                'provider' => 'google',
                'provider_id' => (string) ($info['sub'] ?? $info['id'] ?? ''),
                'name' => $info['name'] ?? Str::before((string) $info['email'], '@'),
                'email' => (string) $info['email'],
                'avatar' => $info['picture'] ?? null,
                'token' => $token['access_token'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('OAuth Google failed: '.$e->getMessage());

            return redirect()->route('login')->with('error', __('auth.errors.oauth_failed', ['provider' => 'Google']));
        }

        return $this->login($user);
    }

    // ── VK ──────────────────────────────────────────────────────────────

    public function redirectVk(): RedirectResponse
    {
        $clientId = (string) config('services.vk.client_id');

        if (blank($clientId)) {
            return redirect()->route('login')->with('error', __('auth.errors.oauth_not_configured', ['provider' => 'VK']));
        }

        $version = (string) config('services.vk.version', '5.199');

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => route('oauth.vk.callback'),
            'response_type' => 'code',
            'scope' => 'email',
            'v' => $version,
            'state' => Str::random(32),
        ]);

        return redirect()->away('https://oauth.vk.com/authorize?'.$query);
    }

    public function handleVk(): RedirectResponse
    {
        $code = request()->query('code');

        if (blank($code)) {
            return redirect()->route('login')->with('error', __('auth.errors.oauth_failed', ['provider' => 'VK']));
        }

        try {
            $token = Http::asForm()->post('https://oauth.vk.com/access_token', [
                'client_id' => config('services.vk.client_id'),
                'client_secret' => config('services.vk.client_secret'),
                'redirect_uri' => route('oauth.vk.callback'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'v' => config('services.vk.version'),
            ])->json();

            if (blank($token['access_token'] ?? null)) {
                throw new \RuntimeException($token['error_description'] ?? 'no access token');
            }

            $info = Http::get('https://api.vk.com/method/users.get', [
                'access_token' => $token['access_token'],
                'fields' => 'screen_name,photo_200',
                'v' => config('services.vk.version'),
            ])->json();

            $profile = $info['response'][0] ?? [];

            $email = $token['email'] ?? null;

            if (blank($email)) {
                // VK не выдал email — просим пользователя дозаполнить
                return redirect()->route('login')
                    ->with('error', __('auth.errors.oauth_no_email', ['provider' => 'VK']));
            }

            $user = $this->registration->registerViaOauth([
                'provider' => 'vk',
                'provider_id' => (string) $profile['id'],
                'name' => $profile['first_name'].' '.$profile['last_name'],
                'email' => (string) $email,
                'avatar' => $profile['photo_200'] ?? null,
                'token' => $token['access_token'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('OAuth VK failed: '.$e->getMessage());

            return redirect()->route('login')->with('error', __('auth.errors.oauth_failed', ['provider' => 'VK']));
        }

        return $this->login($user);
    }

    private function login(User $user): RedirectResponse
    {
        if ($user->status !== User::STATUS_ACTIVE) {
            return redirect()->route('login')->with('error', __('auth.errors.account_blocked', ['reason' => $user->block_reason]));
        }

        Auth::login($user, true);
        request()->session()->regenerate();

        app(\App\Services\Users\AuthService::class)->onSuccess($user);

        return redirect()->intended(route('panel.dashboard'));
    }
}
