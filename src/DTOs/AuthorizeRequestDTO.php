<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

use YacoubAlhaidari\ProviderPayments\Support\Money;

readonly class AuthorizeRequestDTO
{
    public function __construct(
        public string $orderReference,
        public int $amount,
        public string $currency = 'SAR',
        public ?string $providerOrderId = null,
        public array $metadata = [],
    ) {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Positive minor amount required.');
        }
        Money::places($currency);
    }
}
