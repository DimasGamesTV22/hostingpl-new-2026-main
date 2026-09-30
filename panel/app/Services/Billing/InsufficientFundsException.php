<?php

declare(strict_types=1);

namespace App\Services\Billing;

use RuntimeException;

class InsufficientFundsException extends RuntimeException
{
    public function __construct(
        public readonly float $balance,
        public readonly float $required,
    ) {
        parent::__construct(sprintf(
            'Недостаточно средств: на счёте %s, требуется %s',
            number_format($balance, 2, ',', ' '),
            number_format($required, 2, ',', ' '),
        ));
    }
}
