<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\Users\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly RegistrationService $registration) {}

    /**
     * Инвокаемый контроллер: маршрут задан как
     * Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
     * — без метода. Без __invoke Laravel падает на загрузке маршрутов.
     */
    public function __invoke(Request $request, int $id, string $hash): RedirectResponse
    {
        return $this->verify($request, $id, $hash);
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        if (! $user) {
            return redirect()->route('login')->with('error', __('auth.verify_email_failed'));
        }

        if (! hash_equals(sha1($user->email), $hash)) {
            return redirect()->route('verification.code')
                ->with('error', __('auth.verify_email_failed'));
        }

        if ($this->registration->verifyByLink($user, $hash)) {
            return redirect()->route('panel.dashboard')->with('success', __('auth.verify_email_success'));
        }

        return redirect()->route('panel.dashboard');
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user || $user->isEmailVerified()) {
            return redirect()->route('panel.dashboard');
        }

        $this->registration->sendVerification($user);

        return back()->with('success', __('auth.verify_email_sent'));
    }

    public function codeForm(Request $request): View
    {
        return view('auth.verify-code', [
            'user' => $request->user(),
        ]);
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($this->registration->verifyCode($user, $data['code'])) {
            return redirect()->route('panel.dashboard')->with('success', __('auth.verify_email_success'));
        }

        return back()->with('error', __('auth.verify_email_invalid_code'));
    }
}
