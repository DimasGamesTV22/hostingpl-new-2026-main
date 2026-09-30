<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Deposit;
use App\Services\Mail\Mailer;

/**
 * Ручной приём: пользователь переводит деньги, админ подтверждает оплату.
 * Для тех, у кого не подключены эквайринг и крипта.
 */
class ManualGateway implements PaymentGateway
{
    public function __construct(private readonly Mailer $mailer) {}

    public function code(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return (string) config('hosting.payments.methods.manual.label', 'Ручной приём');
    }

    public function isEnabled(): bool
    {
        return (bool) config('hosting.payments.methods.manual.enabled');
    }

    public function createPayment(Deposit $deposit): array
    {
        $deposit->forceFill([
            'status' => Deposit::STATUS_PENDING,
            'meta' => array_merge((array) $deposit->meta, [
                'manual' => [
                    'requested_at' => now()->toIso8601String(),
                    'instructions' => 'Переведите '.$deposit->amountFormatted().' на карту, указанную ниже, и укажите ID платежа.',
                ],
            ]),
        ])->save();

        $this->mailer
            ->to((string) config('hosting.payments.methods.manual.notify_email', config('hosting.branding.support_email')))
            ->subject('Ручной платёж #'.$deposit->id.' — '.$deposit->amountFormatted())
            ->body(sprintf(
                "Пользователь %s (ID %d) ожидает ручного подтверждения оплаты.\n\n"
                ."Сумма: %s\nID депозита: %s\nМетод: %s\n",
                $deposit->user?->name,
                $deposit->user_id,
                $deposit->amountFormatted(),
                $deposit->uuid,
                $deposit->method,
            ));

        return [
            'ok' => true,
            'url' => route('panel.billing.deposits.show', $deposit->uuid),
            'provider_id' => null,
            'error' => null,
            'meta' => ['manual' => true],
        ];
    }

    public function checkPayment(Deposit $deposit): array
    {
        return [
            'paid' => $deposit->isPaid(),
            'status' => $deposit->status,
            'provider_id' => null,
            'error' => null,
            'raw' => null,
        ];
    }

    public function refund(Deposit $deposit, float $amount): array
    {
        return ['ok' => false, 'error' => 'Возврат ручного платежа делается через кошелёк вручную'];
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        return false;
    }
}
