<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Server;
use App\Models\ServerCharge;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\UserTransaction;
use App\Services\Notify\Notifier;
use App\Services\Servers\Provisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Жизненный цикл оплаты серверов: ежедневные начисления, предупреждения,
 * автоостановка при нулевом балансе, возобновление после оплаты.
 */
class BillingService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly PaymentManager $payments,
        private readonly Provisioner $provisioner,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Создать начисление на следующий период при создании сервера.
     */
    public function scheduleFirstCharge(Server $server): ?ServerCharge
    {
        $user = $server->user;
        $tariff = $server->tariff;

        if (! $user || ! $tariff) {
            return null;
        }

        // Тестовый тариф — без начислений
        if ($tariff->is_trial) {
            return null;
        }

        $amount = $this->chargeAmount($server);

        if ($amount <= 0) {
            return null;
        }

        return $this->wallet->createCharge($user, $server, $amount, ServerCharge::TYPE_PERIOD, [
            'period_start' => now(),
            'days' => $tariff->duration_days ?: 30,
        ]);
    }

    /** Сумма периода по тарифу и модели. */
    public function chargeAmount(Server $server): float
    {
        $tariff = $server->tariff;

        if (! $tariff) {
            return 0.0;
        }

        if ($tariff->model === \App\Models\Tariff::MODEL_SLOTS) {
            return round((int) $server->slots * ((float) $tariff->prices->firstWhere('resource', 'extra_slots')?->price ?? 0), 2);
        }

        return round((float) $tariff->price, 2);
    }

    /**
     * Основной крон-метод: ежечасная обработка начислений.
     *
     * @return array{charged: int, suspended: int, resumed: int, warned: int}
     */
    public function processDueCharges(): array
    {
        $stats = ['charged' => 0, 'suspended' => 0, 'resumed' => 0, 'warned' => 0];

        $this->warnExpiring();
        $this->resolveOrders();
        $this->chargeDue();
        $this->suspendOverdue();
        $this->purgeExpired();

        return $stats;
    }

    // ── Списания ────────────────────────────────────────────────────────

    private function chargeDue(): void
    {
        $servers = Server::query()
            ->scheduledForCharge()
            ->with(['user', 'tariff'])
            ->limit(500)
            ->get();

        foreach ($servers as $server) {
            $user = $server->user;
            $tariff = $server->tariff;

            if (! $user || ! $tariff || $tariff->is_trial) {
                // Тестовые серверы просто истекают
                if ($server->expires_at?->isPast()) {
                    $server->forceFill(['status' => Server::STATUS_STOPPED])->save();
                }

                continue;
            }

            // Не дублируем начисление, если за текущий период уже есть
            $exists = ServerCharge::where('server_id', $server->id)
                ->where('type', ServerCharge::TYPE_PERIOD)
                ->where('period_start', '>=', $server->expires_at)
                ->whereIn('status', [ServerCharge::STATUS_PENDING, ServerCharge::STATUS_PAID])
                ->exists();

            if ($exists) {
                continue;
            }

            $amount = $this->chargeAmount($server);

            if ($amount <= 0) {
                $server->forceFill(['expires_at' => $server->expires_at->copy()->addDays($tariff->duration_days ?: 30)])->save();

                continue;
            }

            $charge = $this->wallet->createCharge($user, $server, $amount, ServerCharge::TYPE_PERIOD, [
                'period_start' => now(),
                'days' => $tariff->duration_days ?: 30,
            ]);

            if ($this->settleCharge($charge, $server)) {
                $this->notifier->user(
                    $user,
                    'billing.charged',
                    __('billing.notices.charged', ['server' => $server->name, 'amount' => money($amount)]),
                    __('billing.notices.charged_until', ['date' => $server->fresh()->expires_at?->format(config('hosting.locale.date_format'))]),
                    ['level' => 'info', 'link' => route('panel.servers.show', $server), 'telegram' => false],
                );
            }
        }
    }

    /** Попытаться списать начисление. */
    public function settleCharge(ServerCharge $charge, ?Server $server = null): bool
    {
        $server ??= $charge->server;
        $user = $charge->user;

        try {
            $transaction = $this->wallet->debit(
                $user,
                (float) $charge->amount,
                UserTransaction::TYPE_CHARGE,
                __('billing.titles.charge', ['server' => $server?->name ?? '#'.$charge->server_id]),
                [
                    'source' => 'billing',
                    'server_id' => $charge->server_id,
                    'description' => $charge->type,
                    'meta' => ['charge_id' => $charge->id],
                ],
            );

            $this->wallet->payCharge($charge, $transaction);

            if ($server) {
                $server->forceFill([
                    'expires_at' => $charge->period_end,
                    'is_frozen' => false,
                    'suspended_at' => null,
                    'suspended_reason' => null,
                    'purge_at' => null,
                ])->save();
            }

            return true;
        } catch (InsufficientFundsException) {
            // Денег нет — начисление остаётся pending, сервер уйдёт в grace-период
            return false;
        }
    }

    // ── Предупреждения ──────────────────────────────────────────────────

    private function warnExpiring(): void
    {
        $days = (int) setting('hosting.billing.warn_before_expiry_days', 3);

        $servers = Server::query()
            ->expiring($days * 24 * 60)
            ->where('status', '!=', Server::STATUS_SUSPENDED)
            ->with('user')
            ->limit(500)
            ->get();

        foreach ($servers as $server) {
            if (! $server->user) {
                continue;
            }

            $left = $server->expires_at?->diffInDays(now()) ?? 0;
            $key = "gamedock:warn:expiring:{$server->id}";

            if (cache()->get($key)) {
                continue;
            }

            cache()->put($key, 1, now()->addDays(2));

            $this->notifier->serverExpiring($server, (int) $left);
        }
    }

    // ── Заказы магазина ─────────────────────────────────────────────────

    private function resolveOrders(): void
    {
        $orders = StoreOrder::where('status', StoreOrder::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes(5))
            ->with(['user', 'server'])
            ->limit(200)
            ->get();

        foreach ($orders as $order) {
            $user = $order->user;

            if (! $user) {
                $order->forceFill(['status' => StoreOrder::STATUS_FAILED])->save();

                continue;
            }

            try {
                $transaction = $this->wallet->debit(
                    $user,
                    (float) $order->total,
                    UserTransaction::TYPE_PURCHASE,
                    $order->label,
                    [
                        'source' => 'store',
                        'server_id' => $order->server_id,
                        'order_id' => $order->id,
                    ],
                );

                $order->forceFill([
                    'status' => StoreOrder::STATUS_PAID,
                    'transaction_id' => $transaction->id,
                    'paid_at' => now(),
                ])->save();
            } catch (InsufficientFundsException) {
                continue;
            }
        }
    }

    // ── Заморозка и разморозка ─────────────────────────────────────────

    private function suspendOverdue(): void
    {
        if (! setting_bool('hosting.billing.stop_on_zero_balance', true)) {
            return;
        }

        $graceDays = (int) setting('hosting.billing.grace_period_days', 3);
        $limit = now()->subDays($graceDays);

        $servers = Server::query()
            ->where('is_frozen', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $limit)
            ->with(['user'])
            ->limit(300)
            ->get();

        foreach ($servers as $server) {
            $reason = __('billing.suspend.reason_expired', [
                'date' => $server->expires_at?->format(config('hosting.locale.date_format')),
            ]);

            try {
                $this->provisioner->suspend($server, $reason);
            } catch (\Throwable $e) {
                Log::warning('Не удалось заморозить сервер: '.$e->getMessage(), ['server' => $server->id]);
            }

            $this->notifier->serverSuspended($server, $reason);
        }
    }

    /**
     * Возобновить все замороженные серверы пользователя после оплаты.
     *
     * @return int
     */
    public function resumeFrozen(User $user): int
    {
        $servers = Server::where('user_id', $user->id)
            ->where('is_frozen', true)
            ->get();

        $resumed = 0;

        foreach ($servers as $server) {
            // Сначала списываем накопленные начисления
            $charges = ServerCharge::where('server_id', $server->id)
                ->where('status', ServerCharge::STATUS_PENDING)
                ->get();

            $user = $user->fresh();

            $allPaid = true;
            foreach ($charges as $charge) {
                if (! $this->settleCharge($charge, $server)) {
                    $allPaid = false;
                    break;
                }
            }

            if (! $allPaid) {
                continue;
            }

            $this->provisioner->resume($server);
            $resumed++;
        }

        return $resumed;
    }

    // ── Удаление ────────────────────────────────────────────────────────

    private function purgeExpired(): void
    {
        $days = (int) setting('hosting.billing.delete_after_stop_days', 14);

        $servers = Server::query()
            ->purgable()
            ->where('is_frozen', true)
            ->limit(50)
            ->get();

        foreach ($servers as $server) {
            Log::info('Удаляем просроченный сервер', [
                'server' => $server->id,
                'user' => $server->user_id,
                'purge_at' => $server->purge_at,
                'grace_days' => $days,
            ]);

            try {
                $this->provisioner->delete($server, true, null);
            } catch (\Throwable $e) {
                Log::warning('Не удалось удалить сервер: '.$e->getMessage());
            }
        }
    }

    // ── Отчётность ──────────────────────────────────────────────────────

    /**
     * Выручка за период.
     *
     * @return array<string, mixed>
     */
    public function revenue(int $days = 30): array
    {
        $from = now()->subDays($days);

        $income = (float) UserTransaction::where('status', UserTransaction::STATUS_COMPLETED)
            ->where('direction', UserTransaction::DIR_CREDIT)
            ->where('created_at', '>=', $from)
            ->sum('amount');

        $income -= (float) UserTransaction::where('status', UserTransaction::STATUS_COMPLETED)
            ->where('direction', UserTransaction::DIR_DEBIT)
            ->where('created_at', '>=', $from)
            ->sum('amount');

        $pending = (float) ServerCharge::where('status', ServerCharge::STATUS_PENDING)
            ->where('period_end', '<', now())
            ->sum('amount');

        $servers = Server::count();
        $active = Server::where('status', Server::STATUS_RUNNING)->count();
        $users = User::count();

        $arpu = $users > 0 ? round($income / $users, 2) : 0.0;

        return [
            'income' => round($income, 2),
            'income_formatted' => money($income),
            'pending' => round($pending, 2),
            'pending_formatted' => money($pending),
            'servers' => $servers,
            'active_servers' => $active,
            'users' => $users,
            'arpu' => $arpu,
            'days' => $days,
        ];
    }
}
