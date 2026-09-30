<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\Promo\PromoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReferralController extends Controller
{
    public function __construct(private readonly PromoService $promo) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $referrals = Referral::where('referrer_id', $user->id)
            ->with('referred:id,name,email,created_at')
            ->orderByDesc('created_at')
            ->get();

        $completed = $referrals->where('status', Referral::STATUS_COMPLETED);
        $earned = (float) $completed->sum('reward_referrer');

        $config = (array) setting('hosting.marketing.referral', []);

        return view('panel.referral', [
            'user' => $user,
            'referrals' => $referrals,
            'stats' => [
                'total' => $referrals->count(),
                'completed' => $completed->count(),
                'pending' => $referrals->where('status', Referral::STATUS_PENDING)->count(),
                'earned' => $earned,
                'earnedFormatted' => money($earned),
            ],
            'rewardReferrer' => money((float) ($config['reward_referrer_rub'] ?? 100)),
            'rewardReferred' => money((float) ($config['reward_referred_rub'] ?? 100)),
            'minPayment' => money((float) ($config['min_payment'] ?? 100)),
            'link' => route('register', ['ref' => $user->referral_code]),
            'enabled' => $this->promo->isReferralEnabled(),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $user = $request->user();

        if ($user->referred_by) {
            return back()->with('error', __('referral.errors.already_referred'));
        }

        $ok = $this->promo->attachReferral($user, $data['code']);

        return $ok
            ? back()->with('success', __('referral.messages.applied'))
            : back()->with('error', __('referral.errors.invalid_code'));
    }
}
