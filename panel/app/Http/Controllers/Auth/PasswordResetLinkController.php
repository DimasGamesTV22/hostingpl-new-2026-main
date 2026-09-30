<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Mail\Mailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function __construct(private readonly Mailer $mailer) {}

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($data);

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('success', __('auth.reset_link_sent'));
        }

        // Не раскрываем, существует ли аккаунт
        return back()->with('success', __('auth.reset_link_sent'));
    }
}
