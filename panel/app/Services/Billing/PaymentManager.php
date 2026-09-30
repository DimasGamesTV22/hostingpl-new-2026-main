<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Deposit;
use App\Services\Mail\Mailer;
use App\Services\Payments\CryptoBotGateway;
use App\Services\Payments\ManualGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\TinkoffGateway;
use App\Services\Payments\YooKassaGateway;
use App\Services\Notify\Notifier;
use App\Services\Promo\PromoService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Единая точка входа для оплаты: выбор провайдера, создание инвойса,
 * обработка вебхуков и автозаполнение (pay-as-you-go).
 */
class PaymentManager
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function __construct(
        private readonly WalletService $wallet,
        private readonly Notifier $notifier,
        private readonly Mailer $mailer,
        // Контейнер обязан идти раньше необязательного параметра: в PHP
        // необязательный параметр перед обязательным недопустим, и PHP 8
        // считает $promo обязательным, выдавая предупреждение при каждой
        // загрузке маршрутов. Порядок для внедрения Laravel не важен —
        // он сопоставляет зависимости по имени, а не по позиции.
        private readonly Container $container,
        private readonly ?PromoService $promo = null,
    ) {
        $this->gateways = [
            'yookassa' => $this->container->make(YooKassaGateway::class),
            'tinkoff' => $this->container->make(TinkoffGateway::class),
            'cryptobot' => $this->container->make(CryptoBotGateway::class),
            'manual' => $this->container->make(ManualGateway::class),
        ];
    }

    public function gateway(string $code): ?PaymentGateway
    {
        return $this->gateways[$code] ?? null;
    }

    /** @return array<string, PaymentGateway> */
    public function gateways(): array
    {
        return $this->gateways;
    }

    /**
     * Способы оплаты, доступные пользователю прямо сейчас.
     *
     * @return array<int, array{code: string, label: string, min: float, max: float}>
     */
    public function availableMethods(): array
    {
        if (! setting_bool('hosting.payments.enabled', true)) {
            return [];
        }

        $min = (float) setting('hosting.payments.min_amount', 50);
        $max = 100000.0;

        $out = [];

        foreach ($this->gateways as $code => $gateway) {
            if (! $gateway->isEnabled()) {
                continue;
            }

            $out[] = [
                'code' => $code,
                'label' => $gateway->label(),
                'min' => $code === 'manual' ? $min : max($min, 10) ,
                'max' => $max,
            ];
        }

        return $out;
    }

    /**
     * Создать депозит и инвойс у провайдера.
     *
     * @return array{ok: bool, deposit: ?Deposit, url: ?string, error: ?string}
     */
    public function deposit(
        \App\Models\User $user,
        float $amount,
        string $method,
        ?string $promoCode = null,
        array $options = [],
    ): array {
        if (! setting_bool('hosting.payments.enabled', true)) {
            return ['ok' => false, 'deposit' => null, 'url' => null, 'error' => __('billing.errors.payments_disabled')];
        }

        $min = (float) setting('hosting.billing.min_deposit', 100);

        if ($amount < $min) {
            return ['ok' => false, 'deposit' => null, 'url' => null,
                'error' => __('billing.errors.min_deposit', ['min' => money($min)])];
        }

        $gateway = $this->gateway($method);

        if (! $gateway || ! $gateway->isEnabled()) {
            return ['ok' => false, 'deposit' => null, 'url' => null, 'error' => __('billing.errors.method_unavailable')];
        }

        $deposit = $this->wallet->createDeposit($user, $amount, $method, $promoCode);

        if (isset($options['crypto']) && $options['crypto']) {
            $deposit->meta = array_merge((array) $deposit->meta, ['crypto' => $options['crypto']]);
            $deposit->save();
        }

        $result = $gateway->createPayment($deposit);

        if (! $result['ok']) {
            $this->wallet->failDeposit($deposit, (string) $result['error']);

            return ['ok' => false, 'deposit' => $deposit, 'url' => null, 'error' => $result['error']];
        }

        $deposit->forceFill([
            'meta' => array_merge((array) $deposit->meta, ['gateway' => $result['meta'] ?? []]),
        ])->save();

        return ['ok' => true, 'deposit' => $deposit->refresh(), 'url' => $result['url'], 'error' => null];
    }

    /**
     * Оплата с баланса (без внешнего провайдера).
     *
     * @return array{ok: bool, error: ?string}
     */
    public function payFromBalance(\App\Models\User $user, float $amount, string $title = 'Оплата с баланса'): array
    {
        try {
            $this->wallet->debit($user, $amount, \App\Models\UserTransaction::TYPE_PURCHASE, $title, [
                'source' => 'wallet',
            ]);

            return ['ok' => true, 'error' => null];
        } catch (InsufficientFundsException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Обработать успешную оплату (идемпотентно).
     */
    public function settle(Deposit $deposit, ?string $providerId = null): bool
    {
        if ($deposit->isPaid()) {
            return true;
        }

        $transaction = $this->wallet->settleDeposit($deposit, $providerId);

        if (! $transaction) {
            return false;
        }

        $user = $deposit->user;

        $this->notifier->user(
            $user,
            'billing.paid',
            __('notifications.deposit_paid', ['amount' => money($deposit->amount)]),
            __('notifications.deposit_paid_body', ['balance' => money($user->fresh()->balance)]),
            ['level' => 'success', 'link' => route('panel.billing.transactions'), 'telegram' => true],
        );

        // Пробуем применить отложенные начисления, на которые уже хватило денег
        $this->trySettlePendingCharges($user->fresh());

        $this->applyPromoBonus($deposit);

        return true;
    }

    /** Бонус по промокоду начисляем после подтверждения оплаты. */
    private function applyPromoBonus(Deposit $deposit): void
    {
        $code = data_get($deposit->meta, 'promo_code');

        if (! $code || ! $this->promo) {
            return;
        }

        $this->promo->redeem($code, $deposit->user, deposit: $deposit);
    }

    /**
     * Попытка списать все накопившиеся начисления пользователя.
     * Возвращает количество оплаченных.
     */
    public function trySettlePendingCharges(\App\Models\User $user): int
    {
        $paid = 0;

        $charges = \App\Models\ServerCharge::where('user_id', $user->id)
            ->where('status', \App\Models\ServerCharge::STATUS_PENDING)
            ->orderBy('period_end')
            ->get();

        foreach ($charges as $charge) {
            $user = $user->fresh();

            if ((float) $user->balance < (float) $charge->amount) {
                break;
            }

            try {
                $transaction = $this->wallet->debit(
                    $user,
                    (float) $charge->amount,
                    \App\Models\UserTransaction::TYPE_CHARGE,
                    __('billing.titles.charge', [
                        'server' => $charge->server?->name ?? '#'.$charge->server_id,
                    ]),
                    [
                        'source' => 'billing',
                        'server_id' => $charge->server_id,
                        'description' => $charge->type,
                    ],
                );

                $this->wallet->payCharge($charge, $transaction);

                $this->promo?->extendPeriod($charge);

                $paid++;
            } catch (InsufficientFundsException) {
                break;
            }
        }

        return $paid;
    }

    /**
     * Обработка входящего вебхука.
     */
    public function handleWebhook(string $gatewayCode, string $body, array $headers = []): array
    {
        $gateway = $this->gateway($gatewayCode);

        if (! $gateway) {
            return ['ok' => false, 'error' => 'Неизвестный провайдер'];
        }

        if (! $gateway->verifyWebhook($body, $headers)) {
            Log::warning('Webhook: некорректная подпись', ['gateway' => $gatewayCode]);

            return ['ok' => false, 'error' => 'Bad signature'];
        }

        $parsed = method_exists($gateway, 'parseWebhook') ? $gateway->parseWebhook($body) : [];

        $deposit = $this->findDeposit($parsed, $gatewayCode);

        if (! $deposit) {
            return ['ok' => false, 'error' => 'Deposit not found'];
        }

        if (! empty($parsed['paid'])) {
            $this->settle($deposit, $parsed['payment_id'] ?? null);

            return ['ok' => true, 'deposit' => $deposit];
        }

        if (($parsed['event'] ?? '') === 'payment.canceled' || ($parsed['event'] ?? '') === 'payment.failed') {
            $this->wallet->failDeposit($deposit, 'Платёж отменён провайдером');
        }

        return ['ok' => true, 'deposit' => $deposit];
    }

    private function findDeposit(array $parsed, string $gateway): ?Deposit
    {
        if (! empty($parsed['deposit_uuid'])) {
            $deposit = Deposit::where('uuid', $parsed['deposit_uuid'])->first();
            if ($deposit) {
                return $deposit;
            }
        }

        if (! empty($parsed['deposit_id'])) {
            return Deposit::find($parsed['deposit_id']);
        }

        if (! empty($parsed['payment_id'])) {
            return Deposit::where('method', $gateway)
                ->where('provider_id', $parsed['payment_id'])
                ->first();
        }

        return null;
    }

    /**
     * Проверить статус депозита (cron-подписка + возврат пользователя с сайта).
     */
    public function refresh(Deposit $deposit): Deposit
    {
        if ($deposit->isPaid() || $deposit->isExpired()) {
            return $deposit;
        }

        $gateway = $this->gateway($deposit->method);

        if (! $gateway) {
            return $deposit;
        }

        $result = $gateway->checkPayment($deposit);

        if ($result['paid']) {
            $this->settle($deposit, $result['provider_id'] ?? null);
        } elseif ($deposit->isExpired()) {
            $deposit->forceFill(['status' => Deposit::STATUS_EXPIRED])->save();
        }

        return $deposit->refresh();
    }

    /**
     * Автопополнение: если баланс ниже порога, списываем с привязанной карты.
     */
    public function autoTopUp(\App\Models\User $user): array
    {
        $config = (array) setting('hosting.payments.auto_topup', []);

        if (! ($config['enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'Автопополнение выключено'];
        }

        $threshold = (float) ($config['when_below'] ?? 50);
        $amount = (float) ($config['min_amount'] ?? 200);

        if ((float) $user->balance > $threshold) {
            return ['ok' => false, 'error' => 'Баланс выше порога'];
        }

        return $this->deposit($user, $amount, 'yookassa', null, ['auto' => true]);
    }

    public function refund(Deposit $deposit, float $amount): array
    {
        $gateway = $this->gateway($deposit->method);

        if (! $gateway) {
            return ['ok' => false, 'error' => 'Провайдер не найден'];
        }

        return $gateway->refund($deposit, $amount);
    }
}
