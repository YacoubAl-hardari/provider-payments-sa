<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\DTOs;

readonly class EligibilityRequestDTO
{
    public function __construct(
        public ?CustomerDTO $customer = null,
        public int $amount = 0,
        public string $currency = 'SAR',
        public array $metadata = [],
    ) {}
}
