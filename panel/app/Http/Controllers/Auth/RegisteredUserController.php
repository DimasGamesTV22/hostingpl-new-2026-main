<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Users\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly RegistrationService $registration) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (! setting_bool('hosting.auth.registration_enabled', true)) {
            return redirect()->route('login')->with('error', __('auth.errors.registration_disabled'));
        }

        return view('auth.register', [
            'recaptcha' => setting_array('hosting.auth.recaptcha'),
            'trial' => setting_array('hosting.marketing.trial'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'alpha_dash', 'unique:users,username'],
            'referral_code' => ['nullable', 'string', 'max:16'],
            'terms' => setting_bool('hosting.auth.terms_acceptance', true) ? ['accepted'] : ['nullable'],
            'newsletter' => ['nullable', 'boolean'],
        ], [
            'accepted' => __('auth.errors.accept_terms'),
        ]);

        // Капча
        $captcha = setting_array('hosting.auth.recaptcha');
        if (($captcha['enabled'] ?? false) && filled($captcha['secret_key'] ?? null)) {
            $response = \Illuminate\Support\Facades\Http::asForm()->post(
                match ($captcha['provider'] ?? 'turnstile') {
                    'recaptcha_v2', 'recaptcha_v3' => 'https://www.google.com/recaptcha/api/siteverify',
                    default => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                },
                ['secret' => $captcha['secret_key'], 'response' => $request->input('captcha')],
            );

            if (! ($response->json()['success'] ?? false)) {
                $validator->errors()->add('captcha', __('auth.errors.captcha'));
            }
        }

        $validator->validate();

        try {
            $user = $this->registration->register([
                'name' => $request->string('name')->trim()->value(),
                'email' => $request->string('email')->trim()->value(),
                'password' => $request->string('password')->value(),
                'username' => $request->input('username'),
                'referral_code' => $request->input('referral_code'),
                'locale' => app()->getLocale(),
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'newsletter' => $request->boolean('newsletter'),
                'invite_code' => $request->input('invite_code'),
            ]);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('panel.dashboard')->with(
            'success',
            __('auth.welcome', ['name' => $user->name]),
        );
    }
}
