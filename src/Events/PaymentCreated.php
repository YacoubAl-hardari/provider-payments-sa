<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Events;

class PaymentCreated
{
    public function __construct(
        public string $provider,
        public string $orderReference,
        public int $amount,
        public string $currency,
        public ?string $transactionReference = null,
    ) {}
}
