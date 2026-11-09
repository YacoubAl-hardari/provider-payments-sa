<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

use YacoubAlhaidari\ProviderPayments\Support\Money;

/**
 * Standardized refund request.
 */
readonly class RefundRequestDTO
{
    public function __construct(
        public string $transactionReference,
        public int $amount,
        public string $currency = 'SAR',
        public ?string $reason = null,
        public ?string $refundReference = null,
        public array $metadata = [],
    ) {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Positive minor amount required.');
        }
        Money::places($currency);
    }
}
