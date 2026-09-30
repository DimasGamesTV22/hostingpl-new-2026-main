<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Deposit;
use App\Models\PromoCode;
use App\Models\ServerCharge;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\UserTransaction;
use App\Services\Promo\PromoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Кошелёк пользователя.
 *
 * Все движения денег проходят здесь и только здесь. Баланс меняется
 * под блокировкой строки пользователя (SELECT ... FOR UPDATE), поэтому
 * параллельные оплаты не могут «съесть» деньги дважды.
 */
class WalletService
{
    /**
     * PromoService не внедряется через конструктор намеренно: он сам зависит от
     * WalletService, и цикл в конструкторе приводит к тому, что контейнер
     * подставляет null — промокоды молча перестают работать при пополнении.
     * Поэтому сервис берётся лениво, в момент использования.
     */
    private function promo(): ?PromoService
    {
        return app(PromoService::class);
    }

    // ── Движения ────────────────────────────────────────────────────────

    /**
     * Зачислить деньги.
     */
    public function credit(
        User $user,
        float $amount,
        string $type = UserTransaction::TYPE_DEPOSIT,
        string $title = 'Пополнение',
        array $options = [],
    ): UserTransaction {
        return $this->apply($user, $amount, $type, $title, $options);
    }

    /** Списать деньги (с проверкой баланса). */
    public function debit(
        User $user,
        float $amount,
        string $type = UserTransaction::TYPE_PURCHASE,
        string $title = 'Списание',
        array $options = [],
    ): UserTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма списания должна быть больше нуля.');
        }

        return $this->apply($user, -$amount, $type, $title, $options);
    }

    /**
     * Основная операция: меняет баланс и пишет запись в реестр.
     *
     * @param  array{
     *   source?: string, reference?: string, description?: string,
     *   server_id?: ?int, order_id?: ?int, meta?: array, status?: string
     * }  $options
     */
    public function apply(
        User $user,
        float $signedAmount,
        string $type,
        string $title,
        array $options = [],
    ): UserTransaction {
        if ($signedAmount === 0.0) {
            throw new \InvalidArgumentException('Сумма операции не может быть нулевой.');
        }

        return DB::transaction(function () use ($user, $signedAmount, $type, $title, $options) {
            // Блокируем строку, чтобы параллельные операции выстроились в очередь
            $locked = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $before = (float) $locked->balance;
            $after = round($before + $signedAmount, 2);

            if ($after < 0) {
                throw new InsufficientFundsException($before, abs($signedAmount));
            }

            $locked->forceFill([
                'balance' => $after,
                'balance_updated_at' => now(),
            ]);

            if ($signedAmount > 0 && $type === UserTransaction::TYPE_DEPOSIT) {
                $locked->total_deposited = round((float) $locked->total_deposited + $signedAmount, 2);
            }

            if ($signedAmount < 0) {
                $locked->total_spent = round((float) $locked->total_spent + abs($signedAmount), 2);
            }

            $locked->save();

            return UserTransaction::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $locked->id,
                'type' => $type,
                'direction' => $signedAmount > 0 ? UserTransaction::DIR_CREDIT : UserTransaction::DIR_DEBIT,
                'amount' => abs($signedAmount),
                'balance_before' => $before,
                'balance_after' => $after,
                'currency' => $locked->currency,
                'status' => $options['status'] ?? UserTransaction::STATUS_COMPLETED,
                'source' => $options['source'] ?? 'system',
                'reference' => $options['reference'] ?? null,
                'title' => mb_substr($title, 0, 190),
                'description' => $options['description'] ?? null,
                'server_id' => $options['server_id'] ?? null,
                'order_id' => $options['order_id'] ?? null,
                'meta' => $options['meta'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);
        });
    }

    /** Не списать, а записать в долг: отложенная попытка оплаты. */
    public function allowNegative(User $user, float $amount, string $title, array $options = []): void
    {
        $this->apply($user, -$amount, UserTransaction::TYPE_CHARGE, $title, $options + [
            'status' => UserTransaction::STATUS_PENDING,
        ]);
    }

    // ── Депозиты ────────────────────────────────────────────────────────

    public function createDeposit(
        User $user,
        float $amount,
        string $method,
        ?string $promoCode = null,
    ): Deposit {
        $amount = round($amount, 2);

        $discount = 0.0;

        if ($promoCode) {
            $promo = $this->promo();
            $code = PromoCode::whereRaw('UPPER(code) = ?', [mb_strtoupper($promoCode)])->first();
            $check = $code?->isUsableBy($user, $amount);

            if ($check && $check['ok'] && $code->type === PromoCode::TYPE_DISCOUNT) {
                $discount = $check['discount'];
            }
        }

        $finalAmount = round(max(0, $amount - $discount), 2);

        return Deposit::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'amount' => $finalAmount,
            'currency' => $user->currency,
            'method' => $method,
            'status' => Deposit::STATUS_PENDING,
            'meta' => array_filter([
                'promo_code' => $promoCode,
                'discount' => $discount ?: null,
                'original_amount' => $amount,
            ]),
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addHours(24),
        ]);
    }

    /**
     * Зафиксировать успешную оплату депозита.
     * Идемпотентно: повторный вызов с тем же deposit_id ничего не сделает.
     */
    public function settleDeposit(Deposit $deposit, ?string $providerId = null): ?UserTransaction
    {
        $transaction = DB::transaction(function () use ($deposit, $providerId) {
            $locked = Deposit::where('id', $deposit->id)->lockForUpdate()->first();

            if (! $locked || $locked->isPaid()) {
                return null;
            }

            $locked->forceFill([
                'status' => Deposit::STATUS_PAID,
                'paid_at' => now(),
                'provider_id' => $providerId ?? $locked->provider_id,
                'error' => null,
            ])->save();

            $user = $locked->user;

            $tx = $this->credit(
                $user,
                (float) $locked->amount,
                UserTransaction::TYPE_DEPOSIT,
                __('billing.titles.deposit', ['method' => $locked->methodLabel()]),
                [
                    'source' => $locked->method,
                    'reference' => $providerId ?: $locked->uuid,
                    'description' => 'Пополнение кошелька',
                    'meta' => ['deposit_id' => $locked->id],
                ],
            );

            // Рефералка начисляется после первой оплаты
            $this->promo()->completeReferralIfNeeded($user, (float) $locked->amount, $tx->id);

            return $tx;
        });

        return $transaction;
    }

    public function failDeposit(Deposit $deposit, string $error): void
    {
        $deposit->forceFill([
            'status' => Deposit::STATUS_FAILED,
            'error' => mb_substr($error, 0, 500),
            'attempts' => (int) $deposit->attempts + 1,
        ])->save();
    }

    // ── Начисления ──────────────────────────────────────────────────────

    public function createCharge(
        User $user,
        \App\Models\Server $server,
        float $amount,
        string $type = ServerCharge::TYPE_PERIOD,
        array $options = [],
    ): ServerCharge {
        $days = (int) ($options['days'] ?? $server->tariff?->duration_days ?? 30);
        $start = $options['period_start'] ?? now();

        return ServerCharge::create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'tariff_id' => $server->tariff_id,
            'type' => $type,
            'amount' => round($amount, 2),
            'currency' => $user->currency,
            'status' => ServerCharge::STATUS_PENDING,
            'period_start' => $start,
            'period_end' => $start->copy()->addDays($days),
            'meta' => $options['meta'] ?? null,
        ]);
    }

    public function payCharge(ServerCharge $charge, ?UserTransaction $transaction = null): void
    {
        $charge->forceFill([
            'status' => ServerCharge::STATUS_PAID,
            'transaction_id' => $transaction?->id,
        ])->save();
    }

    public function failCharge(ServerCharge $charge): void
    {
        $charge->forceFill(['status' => ServerCharge::STATUS_FAILED])->save();
    }

    // ── Магазин ─────────────────────────────────────────────────────────

    public function createOrder(
        User $user,
        string $product,
        string $label,
        int $quantity,
        float $unitPrice,
        string $unit = 'шт',
        ?\App\Models\Server $server = null,
        array $meta = [],
    ): StoreOrder {
        $total = round($unitPrice * $quantity, 2);

        return StoreOrder::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'server_id' => $server?->id,
            'product' => $product,
            'label' => $label,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'total' => $total,
            'status' => StoreOrder::STATUS_PENDING,
            'meta' => $meta ?: null,
        ]);
    }

    // ── Отчётность ──────────────────────────────────────────────────────

    /**
     * Сводка по кошельку за период.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, int $days = 30): array
    {
        $from = now()->subDays($days);

        $income = (float) UserTransaction::where('user_id', $user->id)
            ->where('status', UserTransaction::STATUS_COMPLETED)
            ->where('direction', UserTransaction::DIR_CREDIT)
            ->where('created_at', '>=', $from)
            ->sum('amount');

        $expense = (float) UserTransaction::where('user_id', $user->id)
            ->where('status', UserTransaction::STATUS_COMPLETED)
            ->where('direction', UserTransaction::DIR_DEBIT)
            ->where('created_at', '>=', $from)
            ->sum('amount');

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'net' => round($income - $expense, 2),
            'balance' => (float) $user->balance,
            'total_deposited' => (float) $user->total_deposited,
            'total_spent' => (float) $user->total_spent,
        ];
    }

    /** Проверка баланса с исключением депозита, который сейчас оплачивается. */
    public function canAfford(User $user, float $amount, ?int $ignoreDepositId = null): bool
    {
        $balance = (float) $user->balance;

        if ($ignoreDepositId) {
            $deposit = Deposit::find($ignoreDepositId);
            if ($deposit && $deposit->isPaid()) {
                $balance += (float) $deposit->amount;
            }
        }

        return $balance >= $amount;
    }
}
