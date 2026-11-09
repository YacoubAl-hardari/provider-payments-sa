<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

use YacoubAlhaidari\ProviderPayments\Support\Money;

readonly class CaptureRequestDTO
{
    public function __construct(
        public string $transactionReference,
        public int $amount,
        public string $currency = 'SAR',
        public ?string $reason = null,
        public array $metadata = [],
        public int $shippingAmount = 0,
        public int $taxAmount = 0,
        public int $discountAmount = 0,
    ) {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Positive minor amount required.');
        }
        Money::places($currency);
        if ($shippingAmount < 0 || $taxAmount < 0 || $discountAmount < 0) {
            throw new \InvalidArgumentException('Capture adjustments must be nonnegative minor units.');
        }
    }
}
