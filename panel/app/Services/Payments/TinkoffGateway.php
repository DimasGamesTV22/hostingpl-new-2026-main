<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Deposit;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Т-Банк (Тинькофф) Интернет-магазин.
 * Документация: https://www.tbank.ru/developers/
 */
class TinkoffGateway implements PaymentGateway
{
    private const API = 'https://securepay.tinkoff.ru/v2';

    public function code(): string
    {
        return 'tinkoff';
    }

    public function label(): string
    {
        return (string) config('hosting.payments.methods.tinkoff.label', 'Т-Банк');
    }

    public function isEnabled(): bool
    {
        return (bool) config('hosting.payments.methods.tinkoff.enabled')
            && filled($this->terminalKey())
            && filled($this->password());
    }

    private function terminalKey(): ?string
    {
        return $this->override('terminal_key') ?: (config('hosting.payments.methods.tinkoff.terminal_key') ?: env('GD_TINKOFF_TERMINAL_KEY'));
    }

    private function password(): ?string
    {
        return $this->override('password') ?: (config('hosting.payments.methods.tinkoff.password') ?: env('GD_TINKOFF_PASSWORD'));
    }

    private function override(string $key): ?string
    {
        $value = Setting::firstWhere('key', 'payments.tinkoff.'.$key)?->value;

        return filled($value) ? (string) $value : null;
    }

    public function createPayment(Deposit $deposit): array
    {
        $returnUrl = route('payment.return', ['deposit' => $deposit->uuid]);

        $payload = [
            'TerminalKey' => $this->terminalKey(),
            'Amount' => (int) round((float) $deposit->amount * 100),
            'OrderId' => (string) $deposit->id,
            'Description' => 'Пополнение кошелька GameDock',
            'SuccessURL' => $returnUrl,
            'NotificationURL' => route('webhooks.tinkoff'),
            'PaymentMethodData' => ['Type' => 'TwoStage'],
            'Receipt' => $this->receipt($deposit),
        ];

        try {
            $token = $this->getToken();

            if (! $token) {
                return ['ok' => false, 'url' => null, 'provider_id' => null, 'error' => 'Не удалось получить токен', 'meta' => []];
            }

            $response = Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->timeout(20)
                ->post(self::API.'/Init', $payload);

            $body = $response->json();

            if (! $response->successful() || ($body['Success'] ?? false) !== true) {
                $error = $body['Message'] ?? 'HTTP '.$response->status();
                Log::warning('Tinkoff Init failed', ['error' => $error, 'deposit' => $deposit->id]);

                return ['ok' => false, 'url' => null, 'provider_id' => null, 'error' => $error, 'meta' => []];
            }

            $deposit->forceFill([
                'provider_id' => $body['PaymentId'],
                'invoice_url' => $body['PaymentURL'] ?? null,
                'status' => Deposit::STATUS_PENDING,
            ])->save();

            return [
                'ok' => true,
                'url' => $body['PaymentURL'] ?? $returnUrl,
                'provider_id' => (string) $body['PaymentId'],
                'error' => null,
                'meta' => ['terminal_key' => $this->terminalKey()],
            ];
        } catch (\Throwable $e) {
            Log::error('Tinkoff exception: '.$e->getMessage());

            return ['ok' => false, 'url' => null, 'provider_id' => null, 'error' => $e->getMessage(), 'meta' => []];
        }
    }

    public function checkPayment(Deposit $deposit): array
    {
        if (! $deposit->provider_id) {
            return ['paid' => false, 'status' => 'unknown', 'provider_id' => null, 'error' => 'Нет PaymentId', 'raw' => null];
        }

        try {
            $token = $this->getToken();

            $response = Http::acceptJson()
                ->asJson()
                ->withToken((string) $token)
                ->timeout(20)
                ->post(self::API.'/GetState', ['PaymentId' => $deposit->provider_id]);

            $body = $response->json();
            $status = (string) ($body['Status'] ?? 'unknown');

            return [
                'paid' => $status === 'CONFIRMED' && (bool) ($body['Success'] ?? false),
                'status' => $status,
                'provider_id' => (string) ($body['PaymentId'] ?? $deposit->provider_id),
                'error' => null,
                'raw' => $body,
            ];
        } catch (\Throwable $e) {
            return ['paid' => false, 'status' => 'error', 'provider_id' => null, 'error' => $e->getMessage(), 'raw' => null];
        }
    }

    public function refund(Deposit $deposit, float $amount): array
    {
        if (! $deposit->provider_id) {
            return ['ok' => false, 'error' => 'Платёж не найден'];
        }

        try {
            $token = $this->getToken();

            $response = Http::acceptJson()
                ->asJson()
                ->withToken((string) $token)
                ->timeout(20)
                ->post(self::API.'/Reverse', [
                    'PaymentId' => $deposit->provider_id,
                    'Amount' => (int) round($amount * 100),
                ]);

            $body = $response->json();

            if (($body['Success'] ?? false) !== true) {
                return ['ok' => false, 'error' => $body['Message'] ?? 'Ошибка возврата'];
            }

            $deposit->forceFill(['status' => Deposit::STATUS_REFUNDED])->save();

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return false;
        }

        $token = $this->getToken();

        if (! $token) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, (string) $token);
        $provided = (string) ($data['Token'] ?? '');

        return hash_equals($expected, $provided);
    }

    /**
     * @return array{event: string, deposit_id: ?int, payment_id: ?string, paid: bool, success: bool}
     */
    public function parseWebhook(string $body): array
    {
        $data = json_decode($body, true) ?: [];
        $success = (bool) ($data['Success'] ?? false);

        return [
            'event' => $success ? 'payment.succeeded' : 'payment.failed',
            'deposit_id' => isset($data['OrderId']) ? (int) $data['OrderId'] : null,
            'payment_id' => isset($data['PaymentId']) ? (string) $data['PaymentId'] : null,
            'paid' => $success && ($data['Status'] ?? '') === 'CONFIRMED',
            'success' => $success,
        ];
    }

    private function getToken(): ?string
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(15)
                ->post(self::API.'/Token', [
                    'TerminalKey' => $this->terminalKey(),
                    'Password' => $this->password(),
                ]);

            $body = $response->json();

            if ($response->successful() && ($body['Success'] ?? false)) {
                return $body['Token'];
            }

            Log::warning('Tinkoff token error: '.($body['Message'] ?? 'unknown'));

            return null;
        } catch (\Throwable $e) {
            Log::error('Tinkoff token exception: '.$e->getMessage());

            return null;
        }
    }

    /** Чек для самозанятых/ИП — если ИНН не задан, чек не отправляем. */
    private function receipt(Deposit $deposit): ?array
    {
        $inn = $this->override('inn');

        if (blank($inn)) {
            return null;
        }

        $email = $deposit->user?->email ?: $this->override('email');

        return [
            'Client' => array_filter([
                'Email' => $email,
                'Name' => $deposit->user?->name,
            ]),
            'Company' => [
                'Inn' => $inn,
                'TaxSystem' => $this->override('tax_system') ?: 'osn',
            ],
            'Items' => [[
                'Name' => 'Пополнение баланса GameDock',
                'Price' => (int) round((float) $deposit->amount * 100),
                'Quantity' => 1,
                'Amount' => (int) round((float) $deposit->amount * 100),
                'PaymentMethod' => 'full_payment',
                'PaymentObject' => 'service',
                'VatRate' => 'none',
            ]],
        ];
    }
}
