<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Tariff;
use App\Services\Billing\WalletService;
use App\Services\Nodes\PortAllocator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly PortAllocator $ports,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $servers = Server::where('user_id', $user->id)
            ->with(['game', 'tariff', 'node'])
            ->orderBy('expires_at')
            ->get();

        $charges = \App\Models\ServerCharge::where('user_id', $user->id)
            ->where('status', 'pending')
            ->orderBy('period_end')
            ->get();

        $pendingTotal = (float) $charges->sum('amount');

        $tariffs = Tariff::public()->ordered()->with('prices')->get();

        return view('panel.billing.index', [
            'user' => $user,
            'servers' => $servers,
            'tariffs' => $tariffs,
            'pendingCharges' => $charges,
            'pendingTotal' => $pendingTotal,
            'canAfford' => (float) $user->balance >= $pendingTotal,
            'summary' => $this->wallet->summary($user, 90),
            'purchasablePorts' => $this->ports->purchasableRanges(),
            'graceDays' => (int) setting('hosting.billing.grace_period_days', 3),
        ]);
    }
}
