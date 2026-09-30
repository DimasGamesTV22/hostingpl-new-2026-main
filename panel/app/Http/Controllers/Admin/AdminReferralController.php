<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\Promo\PromoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminReferralController extends Controller
{
    public function __construct(private readonly PromoService $promo) {}

    public function __invoke(Request $request): View
    {
        $referrals = Referral::with(['referrer:id,name,email,referral_code', 'referred:id,name,email,total_deposited'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(40)
            ->withQueryString();

        $config = (array) setting('hosting.marketing.referral', []);

        return view('admin.referrals', [
            'referrals' => $referrals,
            'stats' => [
                'total' => Referral::count(),
                'completed' => Referral::where('status', Referral::STATUS_COMPLETED)->count(),
                'pending' => Referral::where('status', Referral::STATUS_PENDING)->count(),
                'paid' => round((float) Referral::sum('reward_referrer') + (float) Referral::sum('reward_referred'), 2),
            ],
            'config' => $config,
            'enabled' => $this->promo->isReferralEnabled(),
        ]);
    }

    public function complete(Referral $referral): RedirectResponse
    {
        if ($referral->status === Referral::STATUS_COMPLETED) {
            return back()->with('error', __('referral.errors.already_done'));
        }

        $this->promo->completeReferral($referral->referred, (float) $referral->order_amount, $referral);

        Auditor::log('admin.referral_complete', 'Реферал #'.$referral->id.' засчитан', $referral->referred);

        return back()->with('success', __('referral.messages.completed'));
    }

    public function reject(Request $request, Referral $referral): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:190']]);

        $referral->forceFill([
            'status' => Referral::STATUS_REJECTED,
            'rejected_reason' => $data['reason'],
        ])->save();

        Auditor::log('admin.referral_reject', 'Реферал #'.$referral->id.' отклонён: '.$data['reason'], $referral->referred);

        return back()->with('success', __('referral.messages.rejected'));
    }
}
