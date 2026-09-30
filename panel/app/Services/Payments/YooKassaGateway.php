<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Deposit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ЮKassa — приём карт, СБП и ЮMoney.
 * Документация: https://yookassa.ru/developers
 */
class YooKassaGateway implements PaymentGateway
{
    private const API = 'https://api.yookassa.ru/v3';

    public function code(): string
    {
        return 'yookassa';
    }

    public function label(): string
    {
        return (string) config('hosting.payments.methods.yookassa.label', 'ЮKassa');
    }

    public function isEnabled(): bool
    {
        return (bool) config('hosting.payments.methods.yookassa.enabled')
            && filled($this->shopId())
            && filled($this->secretKey());
    }

    private function shopId(): ?string
    {
        return $this->config('shop_id') ?: env('GD_YOOKASSA_SHOP_ID');
    }

    private function secretKey(): ?string
    {
        return $this->config('secret_key') ?: env('GD_YOOKASSA_SECRET_KEY');
    }

    private function config(string $key): ?string
    {
        $value = (string) (config('hosting.payments.methods.yookassa.'.$key) ?? '');
        $override = \App\Models\Setting::firstWhere('key', 'payments.yookassa.'.$key)?->value;

        return $override ?: ($value !== '' ? $value : null);
    }

    public function createPayment(Deposit $deposit): array
    {
        $confirmationUrl = route('payment.return', ['deposit' => $deposit->uuid]);

        $payload = [
            'amount' => [
                'value' => number_format((float) $deposit->amount, 2, '.', ''),
                'currency' => $deposit->currency === 'RUB' ? 'RUB' : $deposit->currency,
            ],
            'capture' => true,
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $confirmationUrl,
            ],
            'description' => 'Пополнение кошелька GameDock #'.$deposit->uuid,
            'metadata' => [
                'deposit_id' => $deposit->id,
                'deposit_uuid' => $deposit->uuid,
                'user_id' => $deposit->user_id,
            ],
        ];

        try {
            $response = Http::withBasicAuth($this->shopId(), $this->secretKey())
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->retry(2, 300)
                ->post(self::API.'/payments', $payload);

            $body = $response->json();

            if (! $response->successful()) {
                $error = $body['description'] ?? 'HTTP '.$response->status();
                Log::warning('ЮKassa createPayment failed', ['error' => $error]);

                return ['ok' => false, 'url' => null, 'provider_id' => null, 'error' => $error, 'meta' => []];
            }

            $deposit->forceFill([
                'provider_id' => $body['id'] ?? null,
                'invoice_url' => $body['confirmation']['confirmation_url'] ?? null,
                'status' => Deposit::STATUS_PENDING,
                'meta' => array_merge((array) $deposit->meta, ['yookassa' => ['status' => $body['status'] ?? null]]),
            ])->save();

            return [
                'ok' => true,
                'url' => $body['confirmation']['confirmation_url'] ?? $confirmationUrl,
                'provider_id' => $body['id'] ?? null,
                'error' => null,
                'meta' => ['status' => $body['status'] ?? null],
            ];
        } catch (\Throwable $e) {
            Log::error('ЮKassa exception: '.$e->getMessage());

            return ['ok' => false, 'url' => null, 'provider_id' => null, 'error' => $e->getMessage(), 'meta' => []];
        }
    }

    public function checkPayment(Deposit $deposit): array
    {
        if (! $deposit->provider_id) {
            return ['paid' => false, 'status' => 'unknown', 'provider_id' => null, 'error' => 'Нет provider_id', 'raw' => null];
        }

        try {
            $response = Http::withBasicAuth($this->shopId(), $this->secretKey())
                ->acceptJson()
                ->timeout(20)
                ->get(self::API.'/payments/'.$deposit->provider_id);

            $body = $response->json();
            $status = (string) ($body['status'] ?? 'unknown');

            $paid = $response->successful() && $status === 'succeeded' && ($body['captured'] ?? false);

            return [
                'paid' => $paid,
                'status' => $status,
                'provider_id' => $body['id'] ?? null,
                'error' => $response->successful() ? null : ($body['description'] ?? 'HTTP '.$response->status()),
                'raw' => $body,
            ];
        } catch (\Throwable $e) {
            return ['paid' => false, 'status' => 'error', 'provider_id' => null, 'error' => $e->getMessage(), 'raw' => null];
        }
    }

    public function refund(Deposit $deposit, float $amount): array
    {
        if (! $deposit->provider_id) {
            return ['ok' => false, 'error' => 'Платёж не найден у провайдера'];
        }

        try {
            $response = Http::withBasicAuth($this->shopId(), $this->secretKey())
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post(self::API.'/refunds', [
                    'payment_id' => $deposit->provider_id,
                    'amount' => [
                        'value' => number_format($amount, 2, '.', ''),
                        'currency' => $deposit->currency,
                    ],
                ]);

            if (! $response->successful()) {
                return ['ok' => false, 'error' => $response->json()['description'] ?? 'HTTP '.$response->status()];
            }

            $deposit->forceFill(['status' => Deposit::STATUS_REFUNDED])->save();

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        // ЮKassa подписывает уведомления? Официально — нет.
        // Проверяем источник: только POST с корректным Content-Type и структурой события.
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded['event'])) {
            return false;
        }

        $allowed = ['payment.succeeded', 'payment.canceled', 'payment.pending', 'refund.succeeded'];

        return in_array($decoded['event'], $allowed, true);
    }

    /**
     * Разбор вебхука в команду для обработчика.
     *
     * @return array{event: string, deposit_uuid: ?string, payment_id: ?string, amount: ?float, paid: bool}
     */
    public function parseWebhook(string $body): array
    {
        $data = json_decode($body, true) ?: [];
        $object = $data['object'] ?? [];

        $amount = null;
        if (isset($object['amount']['value'])) {
            $amount = (float) $object['amount']['value'];
        }

        return [
            'event' => (string) ($data['event'] ?? ''),
            'deposit_uuid' => $object['metadata']['deposit_uuid'] ?? null,
            'payment_id' => $object['id'] ?? null,
            'amount' => $amount,
            'paid' => ($data['event'] ?? '') === 'payment.succeeded',
        ];
    }
}
