<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Billing\WalletService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly WalletService $wallet) {}

    /**
     * Инвокаемый контроллер: маршрут задан как
     * Route::get('/', DashboardController::class) — без метода.
     * Без __invoke Laravel падает на загрузке маршрутов.
     */
    public function __invoke(Request $request): View
    {
        return $this->index($request);
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $servers = Server::where('user_id', $user->id)
            ->with(['game', 'node'])
            ->orderByDesc('created_at')
            ->get();

        $byStatus = $servers->groupBy('status')->map->count();

        return view('panel.dashboard', [
            'user' => $user,
            'servers' => $servers,
            'serversCount' => $servers->count(),
            'runningCount' => $byStatus->get(Server::STATUS_RUNNING, 0),
            'crashedCount' => $byStatus->get(Server::STATUS_CRASHED, 0) + $byStatus->get(Server::STATUS_ERROR, 0),
            'playersTotal' => (int) $servers->sum('players_online'),
            'summary' => $this->wallet->summary($user, 30),
            'expiring' => $servers->filter(fn (Server $s) => $s->expires_at && $s->expires_at->lt(now()->addDays((int) setting('hosting.billing.warn_before_expiry_days', 3))))
                ->sortBy('expires_at')
                ->values(),
            'recentTransactions' => $user->transactions()->orderByDesc('created_at')->limit(8)->get(),
            'quota' => [
                'used' => $servers->count(),
                'total' => setting_int('hosting.account.max_servers_per_user', 20),
            ],
            'trialEndsAt' => $user->trial_ends_at,
            'openTickets' => $user->tickets()->open()->count(),
        ]);
    }

    public function activity(Request $request): View
    {
        $servers = $request->user()->servers()->pluck('id');

        $events = \App\Models\ServerEvent::whereIn('server_id', $servers)
            ->with('server:id,name')
            ->orderByDesc('created_at')
            ->paginate(60);

        return view('panel.activity', [
            'events' => $events,
        ]);
    }
}
