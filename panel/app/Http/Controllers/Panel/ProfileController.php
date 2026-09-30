<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Webhook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('panel.profile', [
            'user' => $request->user(),
            'stats' => [
                'servers' => $request->user()->servers()->count(),
                'registered' => $request->user()->created_at?->format('d.m.Y'),
                'referrals' => $request->user()->referralsSent()->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_telegram' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:5'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'newsletter' => ['nullable', 'boolean'],
            'about' => ['nullable', 'string', 'max:1000'],
        ]);

        $emailChanged = $data['email'] !== $user->email;

        $user->forceFill([
            'name' => $data['name'],
            'username' => $data['username'] ?: null,
            'email' => mb_strtolower($data['email']),
            'contact_email' => $data['contact_email'] ?? null,
            'contact_telegram' => $data['contact_telegram'] ?: null,
            'locale' => $data['locale'] ?? $user->locale,
            'timezone' => $data['timezone'] ?? $user->timezone,
            'newsletter' => $request->boolean('newsletter'),
            'about' => $data['about'] ?? null,
        ]);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            app(\App\Services\Users\RegistrationService::class)->sendVerification($user);
        }

        Auditor::log('profile.update', 'Обновлён профиль', $user);

        return back()->with('success', __('profile.messages.updated'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        $user = $request->user();

        $user->forceFill([
            'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
            'password_changed_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        // Завершаем остальные сессии
        app(\App\Services\Users\AuthService::class)->revokeAll($user);

        Auditor::log('profile.password', 'Пароль изменён', $user);

        return back()->with('success', __('profile.messages.password_changed'));
    }

    // ── Аватар ──────────────────────────────────────────────────────────

    public function uploadAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64'],
        ]);

        $user = $request->user();

        if ($user->avatar_url && str_contains($user->avatar_url, '/storage/avatars/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $user->avatar_url));
        }

        $path = $request->file('avatar')->store('avatars/'.$user->id, 'public');

        $user->forceFill(['avatar_url' => Storage::disk('public')->url($path)])->save();

        return back()->with('success', __('profile.messages.avatar_updated'));
    }

    public function deleteAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_url && str_contains($user->avatar_url, '/storage/avatars/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $user->avatar_url));
        }

        $user->forceFill(['avatar_url' => null])->save();

        return back()->with('success', __('profile.messages.avatar_deleted'));
    }

    // ── API-токены ──────────────────────────────────────────────────────

    public function apiTokens(Request $request): View
    {
        return view('panel.api-tokens', [
            'tokens' => $request->user()->apiTokens()->orderByDesc('created_at')->get(),
        ]);
    }

    public function createApiToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'expires_in' => ['nullable', 'integer', 'in:0,7,30,90,365'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => [Rule::in($this->abilities())],
            'ip_whitelist' => ['nullable', 'string', 'max:500'],
        ]);

        $plain = 'gd_'.Str::random(48);
        $meta = ApiToken::generate($plain);

        $whitelist = array_filter(array_map(
            'trim',
            explode(',', (string) ($data['ip_whitelist'] ?? '')),
        ));

        $request->user()->apiTokens()->create([
            'name' => $data['name'],
            'abilities' => $data['abilities'] ?? ['*'],
            'ip_whitelist' => $whitelist ?: null,
            'expires_at' => ($data['expires_in'] ?? 0) > 0 ? now()->addDays((int) $data['expires_in']) : null,
            ...$meta,
        ]);

        Auditor::log('api.token.create', 'Создан API-токен: '.$data['name'], $request->user());

        return back()
            ->with('success', __('profile.messages.token_created'))
            ->with('token_plain', $plain);
    }

    public function destroyApiToken(Request $request, ApiToken $token): RedirectResponse
    {
        abort_if($token->user_id !== $request->user()->id, 403);

        $token->forceFill(['revoked_at' => now()])->save();

        return back()->with('success', __('profile.messages.token_revoked'));
    }

    /** @return array<int, string> */
    public function abilities(): array
    {
        return ['*', 'servers:read', 'servers:write', 'servers:control', 'billing:read', 'profile:read'];
    }

    // ── Вебхуки ─────────────────────────────────────────────────────────

    public function webhooks(Request $request): View
    {
        return view('panel.webhooks', [
            'webhooks' => Webhook::where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->get(),
            'events' => Webhook::EVENTS,
        ]);
    }

    public function createWebhook(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'url' => ['required', 'url', 'max:1024'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(Webhook::EVENTS)],
        ]);

        Webhook::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'] ?? $data['url'],
            'url' => $data['url'],
            'events' => $data['events'],
            'secret' => bin2hex(random_bytes(24)),
        ]);

        return back()->with('success', __('profile.messages.webhook_created'));
    }

    public function destroyWebhook(Request $request, Webhook $webhook): RedirectResponse
    {
        abort_if($webhook->user_id !== $request->user()->id, 403);

        $webhook->delete();

        return back()->with('success', __('profile.messages.webhook_deleted'));
    }
}
