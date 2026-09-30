<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Deposit;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Криптобот (@CryptoBot) — приём оплаты криптовалютой прямо в Telegram.
 * Документация: https://t.me/CryptoBot
 */
class CryptoBotGateway implements PaymentGateway
{
    private const API = 'https://api.crypt.bot';

    public function code(): string
    {
        return 'cryptobot';
    }

    public function label(): string
    {
        return (string) config('hosting.payments.methods.cryptobot.label', 'Криптобот (Telegram)');
    }

    public function isEnabled(): bool
    {
        return (bool) config('hosting.payments.methods.cryptobot.enabled') && filled($this->token());
    }

    private function token(): ?string
    {
        $value = Setting::firstWhere('key', 'payments.cryptobot.token')?->value;

        return filled($value) ? (string) $value : (env('GD_CRYPTOBOT_TOKEN') ?: null);
    }

    public function createPayment(Deposit $deposit): array
    {
        $asset = (string) (data_get($deposit->meta, 'crypto', 'TON'));

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->post(self::API.'/createInvoice', [
                    'token' => $this->token(),
                    'asset' => $asset,
                    'amount' => number_format((float) $deposit->amount, 2, '.', ''),
                    'description' => 'Пополнение GameDock #'.$deposit->id,
                    'payload' => (string) $deposit->uuid,
                    'allow_comments' => false,
                    'allow_anonymous' => true,
                    'expired_in' => (int) config('hosting.payments.methods.cryptobot.expire_minutes', 30) * 60,
                ], [
                    'X-Telegram-Bot-Api-Secret-Token' => (string) Setting::firstWhere('key', 'payments.cryptobot.webhook_secret')?->value,
                ]);

            $body = $response->json();

            if (! $response->successful()) {
                $error = $body['error']['message'] ?? 'HTTP '.$response->status();

                return ['ok' => false, 'url' => null, 'provider_id' => null, 'error' => $error, 'meta' => []];
            }

            $invoice = $body['result'] ?? [];

            $deposit->forceFill([
                'provider_id' => (string) ($invoice['invoice_payload'] ?? ''),
                'invoice_url' => $body['bot_invoice_url'] ?? ($invoice['bot_invoice_url'] ?? null),
                'status' => Deposit::STATUS_PENDING,
                'meta' => array_merge((array) $deposit->meta, [
                    'crypto' => $asset,
                    'crypto_bot' => [
                        'invoice_id' => $invoice['invoice_id'] ?? null,
                        'paid' => $invoice['paid'] ?? false,
                    ],
                ]),
            ])->save();

            return [
                'ok' => true,
                'url' => $body['bot_invoice_url'] ?? null,
                'provider_id' => (string) ($invoice['invoice_payload'] ?? ''),
                'error' => null,
                'meta' => ['invoice_id' => $invoice['invoice_id'] ?? null, 'asset' => $asset],
            ];
        } catch (\Throwable $e) {
            Log::error('CryptoBot createInvoice failed: '.$e->getMessage());

            return ['ok' => false, 'url' => null, 'provider_id' => null, 'error' => $e->getMessage(), 'meta' => []];
        }
    }

    public function checkPayment(Deposit $deposit): array
    {
        $invoiceId = data_get($deposit->meta, 'crypto_bot.invoice_id');

        if (! $invoiceId) {
            return ['paid' => false, 'status' => 'unknown', 'provider_id' => null, 'error' => 'Нет invoice_id', 'raw' => null];
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(15)
                ->get(self::API.'/getInvoices', [
                    'token' => $this->token(),
                    'invoice_id' => $invoiceId,
                ]);

            $body = $response->json();
            $invoice = $body['result']['items'][0] ?? null;

            $paid = (bool) ($invoice['paid'] ?? false);
            $status = $paid ? 'paid' : (string) ($invoice['status'] ?? 'active');

            $deposit->meta = array_merge((array) $deposit->meta, [
                'crypto_bot' => array_merge((array) data_get($deposit->meta, 'crypto_bot'), [
                    'paid' => $paid,
                    'status' => $status,
                    'paid_at' => $invoice['paid_at'] ?? null,
                    'telegram_charge_id' => $invoice['telegram_charge_id'] ?? null,
                ]),
            ]);
            $deposit->save();

            return [
                'paid' => $paid,
                'status' => $status,
                'provider_id' => $deposit->provider_id,
                'error' => null,
                'raw' => $invoice,
            ];
        } catch (\Throwable $e) {
            return ['paid' => false, 'status' => 'error', 'provider_id' => null, 'error' => $e->getMessage(), 'raw' => null];
        }
    }

    public function refund(Deposit $deposit, float $amount): array
    {
        $chargeId = data_get($deposit->meta, 'crypto_bot.telegram_charge_id');

        if (! $chargeId) {
            return ['ok' => false, 'error' => 'Возврат крипты автоматически не поддерживается'];
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->post(self::API.'/refundStar', [
                    'token' => $this->token(),
                    'user_id' => data_get($deposit->meta, 'crypto_bot.user_id'),
                    'telegram_charge_id' => $chargeId,
                ]);

            $ok = $response->successful() && ($response->json()['ok'] ?? false);

            return ['ok' => $ok, 'error' => $ok ? null : ($response->json()['error']['message'] ?? 'Ошибка возврата')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        $secret = (string) Setting::firstWhere('key', 'payments.cryptobot.webhook_secret')?->value;

        if (blank($secret)) {
            return false;
        }

        $data = json_decode($body, true);
        $hash = $data['hash'] ?? null;

        if (! is_string($hash)) {
            return false;
        }

        $bodyWithoutHash = $body;
        $decoded = json_decode($body, true);
        unset($decoded['hash']);
        $bodyWithoutHash = (string) json_encode($decoded);

        $expected = hash_hmac('sha256', $bodyWithoutHash, $secret);

        return hash_equals($expected, $hash);
    }

    /**
     * @return array{event: string, deposit_uuid: ?string, paid: bool, method: ?string}
     */
    public function parseWebhook(string $body): array
    {
        $data = json_decode($body, true) ?: [];
        $updateType = (string) ($data['update_type'] ?? '');
        $payload = (string) ($data['payload'] ?? '');

        $isPayment = $updateType === 'invoice_paid'
            || (($data['method']['name'] ?? null) !== null);

        return [
            'event' => $isPayment ? 'payment.succeeded' : $updateType,
            'deposit_uuid' => $payload !== '' ? $payload : null,
            'paid' => $isPayment,
            'method' => $data['method']['name'] ?? null,
        ];
    }

    /** Список поддерживаемых активов. */
    public function assets(): array
    {
        return (array) config('hosting.payments.methods.cryptobot.crypto', ['TON', 'USDT']);
    }
}
