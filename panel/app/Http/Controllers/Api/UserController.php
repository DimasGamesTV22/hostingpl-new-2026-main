<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->routeName(),
                'email' => $user->email,
                'role' => $user->role,
                'is_staff' => $user->isStaff(),
                'balance' => (float) $user->balance,
                'currency' => $user->currency,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'email_verified' => $user->isEmailVerified(),
                'two_factor' => $user->hasTwoFactor(),
                'referral_code' => $user->referral_code,
                'trial_ends_at' => $user->trial_ends_at?->toIso8601String(),
                'servers_count' => $user->servers()->count(),
                'servers_quota' => setting_int('hosting.account.max_servers_per_user', 20),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:60'],
            'contact_telegram' => ['nullable', 'string', 'max:64'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ]);

        $user->fill($data)->save();

        return $this->me($request);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $summary = app(WalletService::class)->summary($user, (int) $request->query('days', 30));

        $summary['pending_charges'] = round((float) \App\Models\ServerCharge::where('user_id', $user->id)
            ->where('status', 'pending')
            ->sum('amount'), 2);

        return response()->json(['data' => $summary]);
    }
}
