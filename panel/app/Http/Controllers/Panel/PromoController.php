<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Promo\PromoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromoController extends Controller
{
    public function __construct(private readonly PromoService $promo) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('panel.promo', [
            'available' => $this->promo->availableFor($user, null, 0.0),
            'myUses' => \App\Models\PromoUse::where('user_id', $user->id)
                ->with('promoCode')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),
            'servers' => $user->servers()->with('game')->get(),
            'features' => [
                'discount' => $this->promo->isEnabled(\App\Models\PromoCode::TYPE_DISCOUNT),
                'duration' => $this->promo->isEnabled(\App\Models\PromoCode::TYPE_DURATION),
                'bonus' => $this->promo->isEnabled(\App\Models\PromoCode::TYPE_BONUS),
            ],
        ]);
    }

    public function activate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
        ]);

        $user = $request->user();

        $server = $data['server_id']
            ? Server::where('user_id', $user->id)->find($data['server_id'])
            : null;

        $result = $this->promo->redeal($data['code'], $user, $server);

        if (! $result['ok']) {
            return back()->with('error', $result['reason'] ?? __('promo.errors.not_found'));
        }

        $applied = $result['applied'] ?? [];
        $details = [];

        if (! empty($applied['rub'])) {
            $details[] = money($applied['rub']).' на баланс';
        }
        if (! empty($applied['days_added'])) {
            $details[] = $applied['days_added'].' дней аренды';
        }
        if (! empty($applied['slots'])) {
            $details[] = $applied['slots'].' слотов';
        }

        return back()->with('success', __('promo.messages.activated', [
            'code' => $result['promo']->code,
            'details' => $details ? ' ('.implode(', ', $details).')' : '',
        ]));
    }
}
