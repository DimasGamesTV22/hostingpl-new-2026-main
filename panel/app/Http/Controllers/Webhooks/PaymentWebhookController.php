<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Billing\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Входящие вебхуки платёжных систем.
 * Аутентификация — подпись провайдера, а не сессия.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentManager $payments) {}

    public function yookassa(Request $request): JsonResponse
    {
        return $this->handle('yookassa', $request);
    }

    public function tinkoff(Request $request): JsonResponse
    {
        return $this->handle('tinkoff', $request);
    }

    public function cryptobot(Request $request): JsonResponse
    {
        return $this->handle('cryptobot', $request);
    }

    public function generic(Request $request, string $provider): JsonResponse
    {
        return $this->handle($provider, $request);
    }

    private function handle(string $gateway, Request $request): JsonResponse
    {
        $body = $request->getContent();

        $result = $this->payments->handleWebhook($gateway, $body, $request->headers->all());

        if (! $result['ok']) {
            Log::warning('Webhook rejected', [
                'gateway' => $gateway,
                'error' => $result['error'],
                'ip' => $request->ip(),
            ]);

            return response()->json(['ok' => false, 'error' => $result['error']], 400);
        }

        // Всегда отвечаем 200: повторная доставка вебхука не должна
        // приводить к тому, что провайдер будет слать его снова
        return response()->json(['ok' => true]);
    }
}
