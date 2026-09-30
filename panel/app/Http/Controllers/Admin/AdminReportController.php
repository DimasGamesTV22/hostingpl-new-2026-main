<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Models\UserTransaction;
use App\Services\Billing\BillingService;
use App\Services\Nodes\NodeScheduler;
use App\Services\Servers\ServerReaper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminReportController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly NodeScheduler $scheduler,
        private readonly ServerReaper $reaper,
    ) {}

    public function index(Request $request): View
    {
        $days = min((int) $request->query('days', 30), 365);

        return view('admin.dashboard', [
            'revenue' => $this->billing->revenue($days),
            'days' => $days,
            'nodes' => \App\Models\Node::withCount('servers')->orderByDesc('weight')->get(),
            'games' => \App\Models\Game::withCount('servers')->orderByDesc('servers_count')->limit(10)->get(),
            'statuses' => Server::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recentUsers' => User::orderByDesc('created_at')->limit(8)->withCount('servers')->get(),
            'recentTransactions' => UserTransaction::with('user:id,name,email')->orderByDesc('created_at')->limit(10)->get(),
            'topUsers' => User::withSum(['charges as spent' => fn ($q) => $q->where('status', 'paid')], 'amount')
                ->orderByDesc('spent')
                ->limit(8)
                ->get(),
            'queueSizes' => \App\Models\Node::get()
                ->mapWithKeys(fn ($n) => [$n->id => app(\App\Services\Agent\AgentClient::class)->queueSize($n->id)]),
            'system' => $this->systemInfo(),
        ]);
    }

    public function reports(Request $request): View
    {
        $days = min((int) $request->query('days', 30), 365);

        $start = now()->subDays($days);

        return view('admin.reports', [
            'days' => $days,
            'revenue' => $this->billing->revenue($days),
            'daily' => UserTransaction::where('status', 'completed')
                ->where('direction', 'credit')
                ->where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, SUM(amount) as total, COUNT(*) as count')
                ->groupBy('day')
                ->orderBy('day')
                ->get(),
            'newServers' => Server::where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupBy('day')
                ->get(),
            'newUsers' => User::where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupBy('day')
                ->get(),
            'byGame' => Server::with('game:id,name')
                ->selectRaw('game_id, COUNT(*) as total, SUM(slots) as slots')
                ->groupBy('game_id')
                ->get(),
            'byNode' => Server::with('node:id,name')
                ->selectRaw('node_id, COUNT(*) as total, SUM(memory_mb) as memory, SUM(disk_mb) as disk')
                ->groupBy('node_id')
                ->get(),
            'methods' => UserTransaction::where('created_at', '>=', $start)
                ->where('direction', 'credit')
                ->selectRaw('source, COUNT(*) as total, SUM(amount) as amount')
                ->groupBy('source')
                ->get(),
        ]);
    }

    public function revenue(Request $request): \Illuminate\Http\JsonResponse
    {
        $days = min((int) $request->query('days', 30), 365);

        $start = now()->subDays($days);

        $rows = UserTransaction::where('status', 'completed')
            ->where('direction', 'credit')
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, SUM(amount) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        return response()->json([
            'currency' => setting('hosting.billing.currency', 'RUB'),
            'days' => $days,
            'total' => round((float) $rows->sum(), 2),
            'series' => $rows,
        ]);
    }

    private function systemInfo(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'node_mode' => node_mode(),
            'runtime' => default_runtime(),
            'db' => config('database.default'),
            'cache' => config('cache.default'),
            'queue' => config('queue.default'),
            'redis' => $this->redisOk(),
            'disk_free' => round(@disk_free_space(base_path()) / 1073741824, 2),
            'disk_total' => round(@disk_total_space(base_path()) / 1073741824, 2),
            'memory_limit' => ini_get('memory_limit'),
            'upload_max' => ini_get('upload_max_filesize'),
            'servers_total' => Server::count(),
            'servers_running' => Server::where('status', Server::STATUS_RUNNING)->count(),
        ];
    }

    private function redisOk(): bool
    {
        try {
            return (bool) \Illuminate\Support\Facades\Redis::connection()->ping();
        } catch (\Throwable) {
            return false;
        }
    }
}
