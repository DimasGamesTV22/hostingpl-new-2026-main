<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\BillingService;

/**
 * Обёртка для вызова биллинга из планировщика (DI вместо сервис-локатора).
 */
class BillingTask
{
    public function __construct(private readonly BillingService $billing) {}

    /** @return array<string, int> */
    public function run(): array
    {
        return $this->billing->processDueCharges();
    }
}
