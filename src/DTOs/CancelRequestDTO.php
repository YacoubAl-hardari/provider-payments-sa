<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

use YacoubAlhaidari\ProviderPayments\Support\Money;

readonly class CancelRequestDTO
{
    public function __construct(public string $transactionReference, public int $amount, public string $currency = 'SAR', public string $reference = '')
    {
        if ($transactionReference === '' || $amount <= 0 || $reference === '') {
            throw new \InvalidArgumentException('Cancellation identity, amount and reference required.');
        }
        Money::places($currency);
    }
}
