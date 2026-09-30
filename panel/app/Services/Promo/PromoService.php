<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Models\Deposit;
use App\Models\PromoCode;
use App\Models\PromoUse;
use App\Models\Referral;
use App\Models\Server;
use App\Models\ServerCharge;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\UserTransaction;
use App\Services\Billing\WalletService;
use App\Services\Nodes\PortAllocator;
use App\Services\Notify\Notifier;
use App\Services\Notify\TelegramService;
use App\Services\Servers\Provisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Промокоды, бонусы и реферальная программа.
 *
 * Типы промокодов:
 *   discount — скидка на сумму оплаты (применяется в WalletService при создании депозита)
 *   duration — добавляет дни к аренде сервера
 *   bonus    — разовый бонус: рубли, слоты, RAM, дни
 */
class PromoService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly Provisioner $provisioner,
        private readonly PortAllocator $ports,
        private readonly TelegramService $telegram,
    ) {}

    public function isEnabled(string $type): bool
    {
        return match ($type) {
            PromoCode::TYPE_DISCOUNT => setting_bool('hosting.marketing.promo_discount.enabled', true),
            PromoCode::TYPE_DURATION => setting_bool('hosting.marketing.promo_duration.enabled', true),
            PromoCode::TYPE_BONUS => setting_bool('hosting.marketing.promo_bonus.enabled', true),
            default => false,
        };
    }

    // ── Проверка и применение ───────────────────────────────────────────

    /**
     * Проверить промокод без применения.
     *
     * @return array{ok: bool, promo: ?PromoCode, reason: ?string, discount: float, days: int, bonus: array}
     */
    public function validate(string $code, ?User $user = null, float $orderAmount = 0.0, ?Server $server = null, ?string $product = null): array
    {
        $promo = PromoCode::whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($code))])->first();

        if (! $promo) {
            return $this->fail(__('promo.errors.not_found'));
        }

        if (! $this->isEnabled($promo->type)) {
            return $this->fail(__('promo.errors.type_disabled', ['type' => $promo->type]), $promo);
        }

        $check = $promo->isUsableBy($user, $orderAmount, $server, $product);

        if (! $check['ok']) {
            return $this->fail($check['reason'], $promo);
        }

        return [
            'ok' => true,
            'promo' => $promo,
            'reason' => null,
            'discount' => $check['discount'],
            'days' => $check['days'],
            'bonus' => $check['bonus'],
        ];
    }

    private function fail(string $reason, ?PromoCode $promo = null): array
    {
        return [
            'ok' => false,
            'promo' => $promo,
            'reason' => $reason,
            'discount' => 0.0,
            'days' => 0,
            'bonus' => [],
        ];
    }

    /**
     * Активировать промокод.
     *
     * @param  string|null  $depositUuid  для discount-бонусов после оплаты
     */
    public function redeem(
        string $code,
        User $user,
        ?Server $server = null,
        ?string $depositUuid = null,
        ?StoreOrder $order = null,
    ): array {
        $result = $this->validate($code, $user, 0.0, $server, $order?->product);

        if (! $result['ok']) {
            return $result;
        }

        /** @var PromoCode $promo */
        $promo = $result['promo'];
        $applied = [];

        return DB::transaction(function () use ($promo, $user, $server, $depositUuid, $order, &$applied) {
            switch ($promo->type) {
                case PromoCode::TYPE_DURATION:
                    if ($server) {
                        $days = (int) $promo->days;
                        $server->forceFill([
                            'expires_at' => ($server->expires_at ?? now())->copy()->addDays($days),
                        ])->save();

                        $applied['days_added'] = $days;
                    }
                    break;

                case PromoCode::TYPE_BONUS:
                    $applied = $this->applyBonus($promo, $user, $server);
                    break;

                case PromoCode::TYPE_DISCOUNT:
                    // Скидка применяется на этапе создания депозита;
                    // здесь только фиксируем использование, если промокод пришёл
                    // без депозита (например, из формы продления).
                    $applied['discount_pending'] = true;
                    break;
            }

            $promo->increment('used_count');

            PromoUse::create([
                'promo_code_id' => $promo->id,
                'user_id' => $user->id,
                'server_id' => $server?->id,
                'order_id' => $order?->id,
                'deposit_id' => $depositUuid ? Deposit::where('uuid', $depositUuid)->value('id') : null,
                'discount' => $result['discount'] ?? 0,
                'days_added' => $applied['days_added'] ?? 0,
                'bonus_applied' => $applied['rub'] ?? 0,
                'slots_added' => $applied['slots'] ?? 0,
                'ip' => request()?->ip(),
            ]);

            $this->notifyBonus($user, $promo, $applied);

            return [
                'ok' => true,
                'promo' => $promo,
                'reason' => null,
                'applied' => $applied,
            ];
        });
    }

    /**
     * @return array<string, float|int>
     */
    private function applyBonus(PromoCode $promo, User $user, ?Server $server = null): array
    {
        $applied = [];

        $rub = (float) ($promo->bonus_rub ?? 0);
        if ($rub > 0) {
            $this->wallet->credit($user, $rub, UserTransaction::TYPE_BONUS, __('promo.titles.bonus', ['code' => $promo->code]), [
                'source' => 'promo',
                'reference' => $promo->code,
                'description' => $promo->name,
            ]);
            $applied['rub'] = $rub;
        }

        $days = (int) ($promo->bonus_days ?? 0);
        if ($days > 0 && $server) {
            $server->forceFill(['expires_at' => ($server->expires_at ?? now())->copy()->addDays($days)])->save();
            $applied['days'] = $days;
        }

        $slots = (int) ($promo->bonus_slots ?? 0);
        $memory = (int) ($promo->bonus_memory_mb ?? 0);

        if (($slots > 0 || $memory > 0) && $server) {
            $this->provisioner->updateResources($server, array_filter([
                'slots' => $slots > 0 ? (int) $server->slots + $slots : null,
                'memory_mb' => $memory > 0 ? (int) $server->memory_mb + $memory : null,
            ]), $user);

            $applied['slots'] = $slots;
            $applied['memory_mb'] = $memory;
        }

        return $applied;
    }

    /** Продлить период сервера (используется после оплаты накопившихся начислений). */
    public function extendPeriod(ServerCharge $charge): void
    {
        $server = $charge->server;

        if (! $server || ! $server->expires_at) {
            return;
        }

        $days = (int) ($charge->period_end?->diffInDays($charge->period_start) ?: 30);
        $server->forceFill(['expires_at' => $server->expires_at->copy()->addDays($days)])->save();
    }

    // ── Реферальная программа ───────────────────────────────────────────

    public function isReferralEnabled(): bool
    {
        return setting_bool('hosting.marketing.referral.enabled', true);
    }

    /**
     * Привязать нового пользователя к рефереру по его коду.
     */
    public function attachReferral(User $user, string $referralCode): bool
    {
        if (! $this->isReferralEnabled() || $user->referred_by) {
            return false;
        }

        $referrer = User::whereRaw('UPPER(referral_code) = ?', [mb_strtoupper(trim($referralCode))])
            ->where('id', '!=', $user->id)
            ->first();

        if (! $referrer) {
            return false;
        }

        $user->forceFill(['referred_by' => $referrer->id])->save();

        Referral::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $user->id,
            'status' => Referral::STATUS_PENDING,
            'trigger' => (string) setting('hosting.marketing.referral.reward_after_payment', true) ? 'first_payment' : 'registration',
            'reward_referrer' => 0,
            'reward_referred' => 0,
        ]);

        // Награда сразу при регистрации, если так настроено
        if (! setting_bool('hosting.marketing.referral.reward_after_payment', true)) {
            $this->completeReferral($user, 0);
        }

        return true;
    }

    /**
     * Начислить награду, если это первая оплата реферата.
     */
    public function completeReferralIfNeeded(User $user, float $paymentAmount, ?int $transactionId = null): void
    {
        if (! $this->isReferralEnabled() || ! $user->referred_by) {
            return;
        }

        $referral = Referral::where('referred_id', $user->id)
            ->where('status', Referral::STATUS_PENDING)
            ->first();

        if (! $referral) {
            return;
        }

        $minPayment = (float) setting('hosting.marketing.referral.min_payment', 100);

        if ($paymentAmount < $minPayment) {
            return;
        }

        $this->completeReferral($user, $paymentAmount, $referral, $transactionId);
    }

    public function completeReferral(User $user, float $orderAmount, ?Referral $referral = null, ?int $transactionId = null): void
    {
        $referral ??= Referral::where('referred_id', $user->id)
            ->where('status', Referral::STATUS_PENDING)
            ->first();

        if (! $referral) {
            return;
        }

        $referrer = $referral->referrer;
        $rewardReferrer = (float) setting('hosting.marketing.referral.reward_referrer_rub', 100);
        $rewardReferred = (float) setting('hosting.marketing.referral.reward_referred_rub', 100);

        if ($referrer) {
            $this->wallet->credit($referrer, $rewardReferrer, UserTransaction::TYPE_REFERRAL, __('referral.titles.reward_referrer'), [
                'source' => 'referral',
                'reference' => 'ref-'.$referral->id,
                'description' => 'Приглашение '.$user->name,
            ]);

            $referrer->increment('referral_count');
        }

        if ($rewardReferred > 0) {
            $this->wallet->credit($user, $rewardReferred, UserTransaction::TYPE_BONUS, __('referral.titles.reward_referred'), [
                'source' => 'referral',
                'reference' => 'ref-'.$referral->id,
            ]);
        }

        $referral->forceFill([
            'status' => Referral::STATUS_COMPLETED,
            'reward_referrer' => $rewardReferrer,
            'reward_referred' => $rewardReferred,
            'order_amount' => $orderAmount,
            'completed_at' => now(),
        ])->save();

        $this->telegram->sendMessage(sprintf(
            "🤝 <b>Реферальная награда</b>\n\nПриглашённый: %s\nСумма оплаты: %s",
            $user->name,
            money($orderAmount),
        ));
    }

    // ── Уведомления ─────────────────────────────────────────────────────

    private function notifyBonus(User $user, PromoCode $promo, array $applied): void
    {
        $parts = [];

        if (! empty($applied['rub'])) {
            $parts[] = money($applied['rub']).' на баланс';
        }
        if (! empty($applied['days_added'])) {
            $parts[] = $applied['days_added'].' дней аренды';
        }
        if (! empty($applied['slots'])) {
            $parts[] = $applied['slots'].' слотов';
        }
        if (! empty($applied['memory_mb'])) {
            $parts[] = mb_gb($applied['memory_mb']).' RAM';
        }

        if ($parts === []) {
            return;
        }

        app(Notifier::class)->user(
            $user,
            'promo',
            __('promo.titles.activated', ['code' => $promo->code]),
            implode(', ', $parts),
            ['level' => 'success', 'telegram' => true],
        );
    }

    // ── Генерация кодов ─────────────────────────────────────────────────

    /**
     * Массовая генерация промокодов (для акций).
     *
     * @param  array<string, mixed>  $attributes
     * @return int количество созданных
     */
    public function generateMany(int $count, array $attributes, string $prefix = ''): int
    {
        $created = 0;

        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper($prefix).'-'.Str::upper(Str::random(8));

            if (PromoCode::where('code', $code)->exists()) {
                continue;
            }

            PromoCode::create(array_merge($attributes, ['code' => $code]));
            $created++;
        }

        return $created;
    }

    /**
     * Промокоды, доступные конкретному пользователю.
     */
    public function availableFor(User $user, ?Server $server = null, float $orderAmount = 0.0): array
    {
        $codes = PromoCode::active()
            ->where(function ($q) use ($user) {
                $q->whereNull('per_user_limit')
                    ->orWhere('per_user_limit', '>', $this->userUsesCount($user, null));
            })
            ->get();

        return $codes->map(function (PromoCode $promo) use ($user, $server, $orderAmount) {
            $check = $promo->isUsableBy($user, $orderAmount, $server);

            return [
                'code' => $promo->code,
                'type' => $promo->type,
                'value' => $promo->valueLabel(),
                'ok' => $check['ok'],
                'reason' => $check['reason'],
                'discount' => $check['discount'],
            ];
        })->all();
    }

    private function userUsesCount(User $user, ?PromoCode $promo): int
    {
        $query = PromoUse::where('user_id', $user->id);

        if ($promo) {
            $query->where('promo_code_id', $promo->id);
        }

        return $query->count();
    }
}
