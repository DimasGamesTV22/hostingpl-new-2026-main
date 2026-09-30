<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Deposit;

/**
 * Контракт платёжного провайдера.
 *
 * Реализации: YooKassaGateway, TinkoffGateway, CryptoBotGateway, ManualGateway.
 * Добавить нового — реализовать интерфейс и зарегистрировать в PaymentManager.
 */
interface PaymentGateway
{
    public function code(): string;

    public function label(): string;

    public function isEnabled(): bool;

    /**
     * Создать платёж и вернуть ссылку для перехода.
     *
     * @return array{ok: bool, url: ?string, provider_id: ?string, error: ?string, meta: array}
     */
    public function createPayment(Deposit $deposit): array;

    /**
     * Проверить статус платежа (используется кроном и при возврате пользователя).
     *
     * @return array{paid: bool, status: string, provider_id: ?string, error: ?string, raw: mixed}
     */
    public function checkPayment(Deposit $deposit): array;

    /**
     * Вернуть средства.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function refund(Deposit $deposit, float $amount): array;

    /**
     * Проверить подпись входящего вебхука.
     */
    public function verifyWebhook(string $body, array $headers): bool;
}
