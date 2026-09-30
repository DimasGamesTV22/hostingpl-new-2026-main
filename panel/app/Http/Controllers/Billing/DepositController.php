<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Services\Billing\PaymentManager;
use App\Services\Promo\PromoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepositController extends Controller
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly PromoService $promo,
    ) {}

    public function create(Request $request): View
    {
        $methods = $this->payments->availableMethods();

        if ($methods === []) {
            return view('panel.billing.deposit-closed');
        }

        return view('panel.billing.deposit', [
            'methods' => $methods,
            'amount' => (float) $request->query('amount', (float) setting('hosting.billing.min_deposit', 100)),
            'min' => (float) setting('hosting.billing.min_deposit', 100),
            'autoTopup' => setting_array('hosting.payments.auto_topup'),
            'cryptoAssets' => (array) config('hosting.payments.methods.cryptobot.crypto', ['TON', 'USDT']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'method' => ['required', 'string', 'max:24'],
            'crypto' => ['nullable', 'string', 'max:16'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();

        $result = $this->payments->deposit(
            $user,
            (float) $data['amount'],
            $data['method'],
            $data['promo_code'] ?? null,
            ['crypto' => $data['crypto'] ?? null],
        );

        if (! $result['ok']) {
            return back()->withInput()->with('error', $result['error'] ?? __('billing.errors.deposit_failed'));
        }

        $deposit = $result['deposit'];

        // Крипта и карты уводят на внешний инвойс
        if ($result['url'] && $data['method'] !== 'manual') {
            return redirect()->away($result['url']);
        }

        return redirect()->route('panel.billing.deposits.show', $deposit->uuid)
            ->with('success', __('billing.messages.deposit_created'));
    }

    public function show(Request $request, string $deposit): View
    {
        $model = Deposit::where('uuid', $deposit)->firstOrFail();

        $this->authorize('view', $model);

        // Синхронизируем статус при возврате с платёжной страницы
        if (! $model->isPaid() && ! $model->isExpired()) {
            $model = $this->payments->refresh($model);
        }

        return view('panel.billing.deposit-show', [
            'deposit' => $model,
            'method' => $this->payments->gateway($model->method)?->label() ?? $model->method,
        ]);
    }

    public function return(Request $request, string $deposit): RedirectResponse
    {
        $model = Deposit::where('uuid', $deposit)->firstOrFail();

        $this->payments->refresh($model);

        if ($model->isPaid()) {
            return redirect()->route('panel.billing.deposits.show', $model->uuid)
                ->with('success', __('billing.messages.deposit_paid'));
        }

        return redirect()->route('panel.billing.deposits.show', $model->uuid)
            ->with('warning', __('billing.messages.deposit_pending'));
    }

    public function success(): View
    {
        return view('payment.success');
    }

    public function cancel(): View
    {
        return view('payment.cancel');
    }
}
